<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Log;

use InvalidArgumentException;
use Nowo\MailKitBundle\Entity\SentMail;
use Nowo\MailKitBundle\Log\SentMailCsvExporter;
use Nowo\MailKitBundle\Log\SentMailFilters;
use Nowo\MailKitBundle\Log\SentMailRecorder;
use Nowo\MailKitBundle\Log\SentMailRepository;
use Nowo\MailKitBundle\Mailer\MailHeaders;
use Nowo\MailKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\MailKitBundle\Tests\Integration\Kernel\MailKitTestKernel;
use Nowo\MailKitBundle\Ui\AdminPages;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mime\Email;

use function array_keys;
use function array_map;
use function array_reverse;
use function array_slice;
use function count;
use function explode;
use function html_entity_decode;
use function ob_get_clean;
use function ob_start;
use function sprintf;
use function str_getcsv;
use function str_starts_with;
use function strpos;
use function substr;
use function trim;

/**
 * CSV export (content, formula-injection payloads, filters and sort, access, truncation, bodies) and the server-side
 * sortable columns of the sent-mail log (whitelist, tie-breaker, sort kept by pagination and filters). Runs on every
 * database platform of CI (tests/Integration/Log).
 */
final class SentMailExportTest extends KernelTestCase
{
    private const HEADER = ['Date', 'Status', 'Tag', 'From', 'To', 'Cc', 'Bcc', 'Subject', 'Error', 'Reference'];

    public function testTheCsvIsFormulaSafeStreamedAndFollowsTheFilters(): void
    {
        $kernel   = $this->bootKernel();
        $recorder = $this->service($kernel, SentMailRecorder::class);
        $evil     = (new Email())->from('app@example.com')->to('alice@example.com')->cc('carol@example.com')->bcc('audit@example.com')
            ->subject('=HYPERLINK("https://attacker.example/x","Open")')->text('t');
        MailHeaders::tag($evil, '+SUM(1+1)');
        $recorder->sent(null, $evil, 'smtp', 'provider-42', 10);
        $recorder->sent(null, (new Email())->from('app@example.com')->to('bob@example.com')->subject('@cmd')->text('t'), 'smtp', null, null);
        $recorder->failed(null, (new Email())->from('app@example.com')->to('dave@example.com')->subject('-2+3')->text('t'), 'smtp', 'Connection to smtp://u:REDACTED-TEST@relay.example failed');
        $client = $this->client($kernel);

        $rows     = $this->csv($client, '/admin/mail/log/export');
        $response = $client->getResponse();
        self::assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        self::assertSame('0', $response->headers->get(SentMailCsvExporter::TRUNCATED_HEADER));

        self::assertSame(self::HEADER, $rows[0]);
        self::assertCount(4, $rows);
        $byTo = [];
        foreach (array_slice($rows, 1) as $row) {
            $byTo[$row[4]] = $row;
        }
        // Every formula prefix (=, +, -, @) is neutralised with a leading quote.
        self::assertSame("'=HYPERLINK(\"https://attacker.example/x\",\"Open\")", $byTo['alice@example.com'][7]);
        self::assertSame("'@cmd", $byTo['bob@example.com'][7]);
        self::assertSame("'-2+3", $byTo['dave@example.com'][7]);
        [$date, $status, $tag, $from, , $cc, $bcc, , $error, $reference] = $byTo['alice@example.com'];
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/', $date);
        self::assertSame(['sent', "'+SUM(1+1)", 'app@example.com', 'carol@example.com', 'audit@example.com', '', 'provider-42'], [$status, $tag, $from, $cc, $bcc, $error, $reference]);
        self::assertSame('failed', $byTo['dave@example.com'][1]);
        self::assertStringNotContainsString('REDACTED-TEST', $byTo['dave@example.com'][8]);
        self::assertStringContainsString('***@relay.example', $byTo['dave@example.com'][8]);

        // The current sort applies to the export too.
        $sorted = $this->csv($client, '/admin/mail/log/export?sort=status&dir=asc');
        self::assertSame(['failed', 'sent', 'sent'], [$sorted[1][1], $sorted[2][1], $sorted[3][1]]);

        $failed = $this->csv($client, '/admin/mail/log/export?status=failed&period=all');
        self::assertCount(2, $failed);
        self::assertSame('dave@example.com', $failed[1][4]);
        self::assertCount(1, $this->csv($client, '/admin/mail/log/export?q=nobody-matches'));
    }

    public function testTheExportFollowsTheLogAccessAndTheExportRoles(): void
    {
        $kernel = $this->bootKernel();
        $this->send($kernel, 'Hello');
        $viewer = $this->client($kernel, 'viewer');
        $viewer->request('GET', '/admin/mail/log/export');
        self::assertSame(403, $viewer->getResponse()->getStatusCode());
        $anonymous = $this->client($kernel, null);
        $anonymous->request('GET', '/admin/mail/log/export');
        self::assertSame(401, $anonymous->getResponse()->getStatusCode());

        $client  = $this->client($kernel);
        $crawler = $client->request('GET', '/admin/mail/log?status=sent');
        $link    = $crawler->filter('[data-testid="mail-kit-export"]');
        self::assertCount(1, $link);
        self::assertSame('Export CSV', trim($link->text()));
        self::assertSame('/admin/mail/log/export?sort=queuedAt&dir=desc&status=sent', html_entity_decode((string) $link->attr('href')));
        self::assertCount(0, $crawler->filter('[data-testid="mail-kit-export-bodies"]'));

        $restricted = $this->bootKernel(['web_ui' => ['export' => ['access_roles' => ['ROLE_AUDIT']]]]);
        $admin      = $this->client($restricted);
        $crawler    = $admin->request('GET', '/admin/mail/log');
        self::assertSame(200, $admin->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('[data-testid="mail-kit-export"]'));
        $admin->request('GET', '/admin/mail/log/export');
        self::assertSame(403, $admin->getResponse()->getStatusCode());

        $disabled = $this->bootKernel(['web_ui' => ['export' => ['enabled' => false]]]);
        $admin    = $this->client($disabled);
        self::assertCount(0, $admin->request('GET', '/admin/mail/log')->filter('[data-testid="mail-kit-export"]'));
        $admin->request('GET', '/admin/mail/log/export');
        self::assertSame(404, $admin->getResponse()->getStatusCode());
        self::assertFalse($this->container($disabled)->has(SentMailCsvExporter::class));
    }

    public function testALongExportIsTruncatedWithANotice(): void
    {
        $kernel = $this->bootKernel(['web_ui' => ['export' => ['max_rows' => 2]]]);
        foreach (['one', 'two', 'three'] as $subject) {
            $this->send($kernel, $subject);
        }
        $client = $this->client($kernel);

        $rows = $this->csv($client, '/admin/mail/log/export');
        self::assertSame('1', $client->getResponse()->headers->get(SentMailCsvExporter::TRUNCATED_HEADER));
        self::assertCount(4, $rows);
        self::assertSame(['three', 'two'], [$rows[1][7], $rows[2][7]]);
        self::assertSame(['Export truncated: the first 2 of 3 rows. Narrow the filters to export the rest.'], $rows[3]);

        $crawler = $client->request('GET', '/admin/mail/log');
        self::assertSame('Export CSV (first 2 rows)', trim($crawler->filter('[data-testid="mail-kit-export"]')->text()));
    }

    public function testBodiesNeedTheExportOptionAndAnExplicitRequest(): void
    {
        $stored = $this->bootKernel(['log' => ['store_bodies' => true]]);
        $this->send($stored, 'With body', 'secret text body');
        $client = $this->client($stored);
        $rows   = $this->csv($client, '/admin/mail/log/export?bodies=1');
        self::assertSame(self::HEADER, $rows[0]);
        self::assertNotContains('secret text body', $rows[1]);
        self::assertCount(0, $client->request('GET', '/admin/mail/log')->filter('[data-testid="mail-kit-export-bodies"]'));

        $kernel = $this->bootKernel(['log' => ['store_bodies' => true], 'web_ui' => ['export' => ['include_bodies' => true]]]);
        $this->send($kernel, 'With body', 'secret text body', '<p>html body</p>');
        $this->send($kernel, 'Body gone', 'gone');
        $this->connection($kernel)->executeStatement("DELETE FROM mail_kit_sent_mail_body WHERE text_body = 'gone'");
        $client = $this->client($kernel);
        self::assertCount(10, $this->csv($client, '/admin/mail/log/export')[0]);

        $crawler = $client->request('GET', '/admin/mail/log');
        $href    = html_entity_decode((string) $crawler->filter('[data-testid="mail-kit-export-bodies"]')->attr('href'));
        self::assertStringContainsString('bodies=1', $href);
        $rows = $this->csv($client, $href);
        self::assertSame([...self::HEADER, 'Text body', 'HTML body'], $rows[0]);
        self::assertSame(['Body gone', '', ''], [$rows[1][7], $rows[1][10], $rows[1][11]]);
        self::assertSame(['With body', 'secret text body', '<p>html body</p>'], [$rows[2][7], $rows[2][10], $rows[2][11]]);
    }

    public function testTheExporterFetchesBodiesInBatchesAndWorksWithoutATranslator(): void
    {
        $kernel = $this->bootKernel(['log' => ['store_bodies' => true]]);
        foreach (['one', 'two', 'three'] as $subject) {
            $this->send($kernel, $subject, 'text ' . $subject);
        }
        $exporter = new SentMailCsvExporter($this->service($kernel, SentMailRepository::class), 2, true, null, new MockClock('2030-01-02 03:04:05'), 2);
        self::assertSame(2, $exporter->maxRows());
        self::assertTrue($exporter->bodiesAllowed());

        $response = $exporter->export(new SentMailFilters(), 'subject', 'asc', true);
        self::assertStringContainsString('sent-mail-20300102-030405.csv', (string) $response->headers->get('Content-Disposition'));
        ob_start();
        $response->sendContent();
        $lines = explode("\n", trim(substr((string) ob_get_clean(), 3)));
        self::assertCount(4, $lines);
        self::assertStringStartsWith('log.export.column.date,', $lines[0]);
        self::assertMatchesRegularExpression('/,one,,[^,]*,"text one",$/', $lines[1]);
        self::assertMatchesRegularExpression('/,three,,[^,]*,"text three",$/', $lines[2]);
        self::assertSame('log.export.truncated', $lines[3]);
    }

    public function testTheLogSortsByWhitelistedColumnsOnly(): void
    {
        $kernel = $this->bootKernel();
        foreach (['bravo', 'alpha', 'charlie'] as $subject) {
            $this->send($kernel, $subject);
        }
        $client = $this->client($kernel);

        $crawler = $client->request('GET', '/admin/mail/log?sort=subject&dir=asc');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $this->assertOrder($client, ['alpha', 'bravo', 'charlie']);
        self::assertSame('ascending', $crawler->filter('th[aria-sort]')->attr('aria-sort'));
        self::assertSame('subject', $crawler->filter('input[name="sort"]')->attr('value'));
        self::assertSame('asc', $crawler->filter('input[name="dir"]')->attr('value'));

        $client->request('GET', '/admin/mail/log?sort=subject&dir=desc');
        $this->assertOrder($client, ['charlie', 'bravo', 'alpha']);

        // Unknown keys (a real column outside the whitelist, an injection attempt) fall back to newest first.
        foreach (['recipientIndex', 'subject;DROP TABLE x', 'm.id'] as $sort) {
            $client->request('GET', '/admin/mail/log?sort=' . $sort . '&dir=asc');
            self::assertSame(200, $client->getResponse()->getStatusCode());
            $this->assertOrder($client, ['charlie', 'alpha', 'bravo']);
        }

        // An unknown direction falls back to the key's first-click direction.
        $client->request('GET', '/admin/mail/log?sort=queuedAt&dir=sideways');
        $this->assertOrder($client, ['charlie', 'alpha', 'bravo']);
    }

    public function testPaginationAndFiltersKeepTheSort(): void
    {
        $kernel = $this->bootKernel();
        for ($i = 1; $i <= 12; ++$i) {
            $this->send($kernel, sprintf('subject %02d', $i));
        }
        $client = $this->client($kernel);

        $crawler = $client->request('GET', '/admin/mail/log?sort=subject&dir=asc&per_page=10&period=30d');
        $next    = $crawler->filter('a[href*="page=2"]');
        self::assertGreaterThan(0, count($next));
        $href = html_entity_decode((string) $next->first()->attr('href'));
        self::assertStringContainsString('sort=subject', $href);
        self::assertStringContainsString('dir=asc', $href);
        self::assertStringContainsString('period=30d', $href);

        $client->request('GET', $href);
        $this->assertOrder($client, ['subject 11', 'subject 12']);
        self::assertStringNotContainsString('subject 01', (string) $client->getResponse()->getContent());

        // Sort header links keep the filters and go back to page 1.
        $crawler = $client->request('GET', '/admin/mail/log?sort=subject&dir=asc&page=2&per_page=10&period=30d');
        $header  = html_entity_decode((string) $crawler->filter('th[aria-sort] a')->attr('href'));
        self::assertStringContainsString('dir=desc', $header);
        self::assertStringContainsString('period=30d', $header);
        self::assertStringNotContainsString('page=2', $header);

        // Submitting the filter form keeps the sort (hidden fields).
        $form = $crawler->filter('form[role="search"]')->form();
        $client->submit($form, ['q' => 'subject 1']);
        self::assertStringContainsString('sort=subject', (string) $client->getRequest()->getUri());
        $this->assertOrder($client, ['subject 10', 'subject 11', 'subject 12']);
    }

    public function testTheRepositoryRefusesUnknownSortsAndBreaksTiesById(): void
    {
        $kernel     = $this->bootKernel();
        $repository = $this->service($kernel, SentMailRepository::class);
        foreach (['same', 'same', 'same'] as $subject) {
            $this->send($kernel, $subject);
        }
        $this->connection($kernel)->executeStatement("UPDATE mail_kit_sent_mail SET queued_at = '2030-01-01 00:00:00'");
        $ids = $this->connection($kernel)->fetchFirstColumn('SELECT id FROM mail_kit_sent_mail ORDER BY id ASC');
        self::assertCount(3, $ids);

        $filters = new SentMailFilters(period: 'all');
        $asc     = array_map(static fn (SentMail $row): string => $row->getId(), $repository->findFiltered($filters, 10, 0, 'subject', 'asc'));
        $desc    = array_map(static fn (SentMail $row): string => $row->getId(), $repository->findFiltered($filters, 10, 0, 'subject', 'desc'));
        self::assertSame($ids, $asc);
        self::assertSame(array_reverse($ids), $desc);
        self::assertSame(array_reverse($ids), array_map(static fn (SentMail $row): string => $row->getId(), $repository->findFiltered($filters, 10)));
        self::assertSame(array_slice($asc, 1, 1), array_map(static fn (SentMail $row): string => $row->getId(), $repository->findFiltered($filters, 1, 1, 'status', 'asc')));
        self::assertSame($asc, array_map(static fn (SentMail $row): string => $row->getId(), [...$repository->iterateFiltered($filters, 10, 'tag', 'asc')]));
        self::assertSame([], $repository->findBodies([]));
        self::assertSame(array_keys(SentMailRepository::SORT_FIELDS), array_keys(AdminPages::sort(new Request())->fields));

        try {
            $repository->findFiltered($filters, 10, 0, 'recipientIndex');
            self::fail('Unknown sort accepted.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('Unknown sent-mail log sort "recipientIndex"', $e->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid sort direction "up"');
        $repository->findFiltered($filters, 10, 0, 'subject', 'up');
    }

    private function send(MailKitTestKernel $kernel, string $subject, string $text = 't', ?string $html = null): void
    {
        $email = (new Email())->from('app@example.com')->to('someone@example.com')->subject($subject)->text($text);
        if ($html !== null) {
            $email->html($html);
        }
        $this->mailer($kernel)->send($email);
    }

    /**
     * @return list<list<string>>
     */
    private function csv(KernelBrowser $client, string $uri): array
    {
        $client->request('GET', $uri);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $content = $client->getInternalResponse()->getContent();
        self::assertTrue(str_starts_with($content, "\xEF\xBB\xBF"), 'UTF-8 BOM first.');
        $rows = [];
        foreach (explode("\n", trim(substr($content, 3))) as $line) {
            $rows[] = array_map(static fn (?string $cell): string => (string) $cell, str_getcsv($line, ',', '"', ''));
        }

        return $rows;
    }

    /**
     * @param list<string> $subjects subjects expected in this order in the list
     */
    private function assertOrder(KernelBrowser $client, array $subjects): void
    {
        $html     = (string) $client->getResponse()->getContent();
        $previous = -1;
        foreach ($subjects as $subject) {
            $position = strpos($html, '>' . $subject . '</a>');
            self::assertNotFalse($position, $subject . ' missing.');
            self::assertGreaterThan($previous, $position, $subject . ' out of order.');
            $previous = $position;
        }
    }
}
