<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Tracking;

use InvalidArgumentException;
use Nowo\MailKitBundle\DependencyInjection\Configuration;
use Nowo\MailKitBundle\Mailer\MailHeaders;
use Nowo\MailKitBundle\Tests\Support\HostTrackingPolicy;
use Nowo\MailKitBundle\Tests\Support\RecordingLogger;
use Nowo\MailKitBundle\Tests\Support\ShortLinkRewriter;
use Nowo\MailKitBundle\Tests\Support\TrackingStoreTrait;
use Nowo\MailKitBundle\Tracking\ConfiguredTrackableLinkFilter;
use Nowo\MailKitBundle\Tracking\CredentialLinkDetector;
use Nowo\MailKitBundle\Tracking\EmailLinkRewriterInterface;
use Nowo\MailKitBundle\Tracking\EmailTracker;
use Nowo\MailKitBundle\Tracking\EmailTrackingPolicyInterface;
use Nowo\MailKitBundle\Tracking\HtmlLinkTracker;
use Nowo\MailKitBundle\Tracking\Store\TrackingStore;
use Nowo\MailKitBundle\Tracking\TrackableLinkFilterInterface;
use Nowo\MailKitBundle\Tracking\TrackedEmail;
use Nowo\MailKitBundle\Tracking\TrackedLink;
use Nowo\MailKitBundle\Tracking\TrackingBaseUrl;
use Nowo\MailKitBundle\Tracking\TrackingCandidate;
use Nowo\MailKitBundle\Tracking\TrackingTokenSigner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\BodyRendererInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;

use function count;
use function fopen;
use function fwrite;
use function preg_match;
use function rewind;
use function str_repeat;

final class EmailTrackerTest extends TestCase
{
    use TrackingStoreTrait;

    private const BASE = 'https://app.example.com/e/o';

    private const HTML = '<html><body><p>Hi</p>'
        . '<a href="https://app.example.com/issues/1">View issue</a>'
        . '<a href="https://app.example.com/account/notifications/unsubscribe">Unsubscribe</a>'
        . '<a href="https://app.example.com/reset-password?token=abc">Reset</a>'
        . '<a href="https://partner.example.net/offer">Partner</a>'
        . '<a href="mailto:help@example.com">Help</a><a href="tel:+34600000000">Call</a><a href="#top">Top</a>'
        . '</body></html>';

    private ShortLinkRewriter $rewriter;

    private TrackingStore $store;

    private TrackingTokenSigner $signer;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->rewriter = new ShortLinkRewriter();
        $this->logger   = new RecordingLogger();
        $this->store    = $this->trackingStore(logger: $this->logger);
        $this->signer   = new TrackingTokenSigner('test-tracking-secret-REDACTED-0000000000');
    }

    public function testTracksPixelLinksAndTextAndKeepsTheOriginal(): void
    {
        $email = $this->email()->text('View: https://app.example.com/issues/1 Reset: https://app.example.com/reset-password?token=abc');
        MailHeaders::template($email, 'issue_alert');
        MailHeaders::trackingRecipient($email, 'UserA', 'acme');
        $tracked = $this->tracker()->track($email, null, 'log-1');

        self::assertInstanceOf(TrackedEmail::class, $tracked);
        self::assertNotSame($email, $tracked->email);
        self::assertSame(self::HTML, $email->getHtmlBody(), 'The original is never changed.');
        $html  = (string) $tracked->email->getHtmlBody();
        $short = 'https://app.example.com/s/' . substr($tracked->sendId, 0, 8);
        self::assertStringContainsString('<a href="' . $short . '-1">View issue</a>', $html);
        self::assertStringContainsString('<a href="' . $short . '-2">Unsubscribe</a>', $html);
        self::assertStringContainsString('<a href="https://app.example.com/reset-password?token=abc">Reset</a>', $html);
        self::assertStringContainsString('<a href="https://partner.example.net/offer">Partner</a>', $html, 'Not in the allow-list.');
        foreach (['mailto:help@example.com', 'tel:+34600000000', '"#top"'] as $kept) {
            self::assertStringContainsString($kept, $html);
        }
        self::assertSame(1, preg_match('~<img src="' . self::BASE . '/([A-Za-z0-9_-]{48})\.gif"~', $html, $m));
        self::assertSame($tracked->sendId, $this->signer->verify($m[1]));
        self::assertSame('View: ' . $short . '-1 Reset: https://app.example.com/reset-password?token=abc', $tracked->email->getTextBody());

        self::assertCount(2, $this->rewriter->links);
        [$first, $second] = $this->rewriter->links;
        self::assertSame(['View issue', 1, false, 'issue_alert', 'UserA', 'acme'], [$first->label, $first->position, $first->essential, $first->templateId, $first->recipientId, $first->tenant]);
        self::assertTrue($second->essential);

        $stats = $this->store->find($tracked->sendId);
        self::assertNotNull($stats);
        self::assertSame(['log-1', 'issue_alert', 'UserA', 'acme', 0], [$stats->logId, $stats->templateId, $stats->recipientId, $stats->tenant, $stats->opens]);

        $this->tracker()->attachLog($tracked, 'log-2');
        self::assertSame('log-2', $this->store->find($tracked->sendId)?->logId);
        $this->tracker()->discard($tracked);
        self::assertNull($this->store->find($tracked->sendId));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function securityTemplates(): iterable
    {
        foreach (HostTrackingPolicy::SECURITY as $template) {
            yield $template => [$template];
        }
    }

    #[DataProvider('securityTemplates')]
    public function testSecurityTemplatesCarryNoPixelAndNoRewrittenLink(string $template): void
    {
        // The bundle defaults refuse them before the policy is asked …
        $email = $this->email();
        MailHeaders::template($email, $template);
        self::assertNull($this->tracker()->track($email));
        // … the built-in patterns cannot be removed (audit NU-M2) …
        self::assertNull($this->tracker(neverTrack: [])->track($email));
        // … and the host policy refuses them too.
        self::assertFalse((new HostTrackingPolicy())->decide(new TrackingCandidate($template, 'user@example.com'))->tracks());
        self::assertSame(0, $this->rows());
        self::assertSame([], $this->rewriter->links);
    }

    public function testWhatIsNeverTracked(): void
    {
        $tracker = $this->tracker();

        $noTrack = $this->email();
        MailHeaders::noTracking($noTrack);
        self::assertNull($tracker->track($noTrack));

        $redacted = $this->email();
        MailHeaders::redact($redacted, 'abc');
        self::assertNull($tracker->track($redacted), 'Declares a one-time value.');

        $tagged = $this->email();
        MailHeaders::tag($tagged, 'Password_Reset_v2');
        self::assertNull($tracker->track($tagged), 'The tag stands in for the template id (case-insensitive patterns).');

        self::assertNull($tracker->track($this->email()->addTo('second@example.com')), 'Two recipients.');
        self::assertNull($tracker->track($this->email(), new Envelope(new Address('app@example.com'), [new Address('a@example.com'), new Address('b@example.com')])));
        self::assertNull($tracker->track((new Email())->from('app@example.com')->subject('No recipient')->html(self::HTML)), 'Envelope error.');
        self::assertNull($tracker->track(new Message()));
        self::assertNull($tracker->track($this->email()->html(null)->text('text only')));
        self::assertNull($tracker->track($this->email()->html('')));
        self::assertNull($tracker->track($this->email()->html('<p>' . str_repeat('x', 2048) . '</p>')), 'Larger than max_html_bytes.');
        self::assertNull($tracker->track($this->email()->html('<img src="' . self::BASE . '/x.gif">')), 'Already tracked (a retry).');
        self::assertNull($tracker->track($this->email('optout@example.com')), 'Opted out.');

        $boom = $this->email();
        MailHeaders::template($boom, 'boom');
        self::assertNull($tracker->track($boom), 'A failing policy tracks nothing.');
        $long = $this->email();
        $long->getHeaders()->addTextHeader(MailHeaders::TEMPLATE, str_repeat('t', 80));
        self::assertNull($tracker->track($long), 'An unacceptable template id tracks nothing.');

        self::assertSame(0, $this->rows(), 'Zero rows for every untracked e-mail.');
        self::assertSame([], $this->rewriter->links);
        self::assertGreaterThanOrEqual(2, count($this->logger->records));
    }

    public function testPixelOnlyLinksOnlyAndRedactedLinksWithoutSkipping(): void
    {
        $pixelOnly = $this->email();
        MailHeaders::template($pixelOnly, 'pixel_only');
        $tracked = $this->tracker()->track($pixelOnly);
        self::assertNotNull($tracked);
        self::assertStringContainsString('href="https://app.example.com/issues/1"', (string) $tracked->email->getHtmlBody());
        self::assertStringContainsString(self::BASE . '/', (string) $tracked->email->getHtmlBody());

        $linksOnly = $this->email();
        MailHeaders::template($linksOnly, 'links_only');
        $tracked = $this->tracker()->track($linksOnly);
        self::assertNotNull($tracked);
        self::assertStringNotContainsString(self::BASE, (string) $tracked->email->getHtmlBody());
        self::assertStringContainsString('/s/', (string) $tracked->email->getHtmlBody());

        // skip_redacted off: the e-mail is tracked, but no link that contains a declared value is rewritten.
        $redacted = $this->email()->html('<a href="https://app.example.com/issues/1">A</a><a href="https://app.example.com/i/SECRETVALUE">B</a>');
        MailHeaders::redact($redacted, 'SECRETVALUE');
        $tracked = $this->tracker(skipRedacted: false)->track($redacted);
        self::assertNotNull($tracked);
        self::assertStringContainsString('href="https://app.example.com/i/SECRETVALUE"', (string) $tracked->email->getHtmlBody());
        self::assertSame(3, $this->rows());
    }

    public function testRewriterAndFilterFailuresKeepTheLinks(): void
    {
        $rewriter = new class implements EmailLinkRewriterInterface {
            public function rewrite(TrackedLink $link): string
            {
                return match ($link->position) {
                    1       => throw new RuntimeException('down'),
                    2       => 'javascript:alert(1)',
                    3       => '/relative',
                    4       => 'https://t.example/' . str_repeat('x', 2100),
                    5       => 'https://t.example/with space',
                    6       => 'https://?x',
                    default => 'https://t.example/ok',
                };
            }
        };
        $html = '';
        for ($i = 1; $i <= 7; ++$i) {
            $html .= '<a href="https://app.example.com/p' . $i . '">' . $i . '</a>';
        }
        $tracked = $this->tracker(rewriter: $rewriter)->track($this->email()->html($html));
        self::assertNotNull($tracked);
        $out = (string) $tracked->email->getHtmlBody();
        for ($i = 1; $i <= 6; ++$i) {
            self::assertStringContainsString('href="https://app.example.com/p' . $i . '"', $out);
        }
        self::assertStringContainsString('href="https://t.example/ok"', $out);

        $filter = new class implements TrackableLinkFilterInterface {
            public function mayRewrite(string $url, ?string $templateId): bool
            {
                throw new RuntimeException('filter down');
            }
        };
        $tracked = $this->tracker(filter: $filter)->track($this->email());
        self::assertNotNull($tracked);
        self::assertSame([], $this->rewriter->links);
        self::assertStringContainsString('href="https://app.example.com/issues/1"', (string) $tracked->email->getHtmlBody());
    }

    public function testStreamsRenderingAndStoreFailure(): void
    {
        $html = fopen('php://memory', 'r+');
        $text = fopen('php://memory', 'r+');
        self::assertIsResource($html);
        self::assertIsResource($text);
        fwrite($html, self::HTML);
        fwrite($text, 'See https://app.example.com/issues/1');
        rewind($html);
        rewind($text);
        $tracked = $this->tracker()->track($this->email()->html($html)->text($text));
        self::assertNotNull($tracked);
        self::assertIsString($tracked->email->getHtmlBody());
        self::assertStringContainsString('/s/', (string) $tracked->email->getTextBody());

        // A templated e-mail sent synchronously is rendered first.
        $renderer = new class implements BodyRendererInterface {
            public function render(Message $message): void
            {
                if ($message instanceof Email && $message->getHtmlBody() === null) {
                    $message->html('<a href="https://app.example.com/issues/9">Rendered</a>');
                }
            }
        };
        $tracked = $this->tracker(renderer: $renderer)->track($this->email()->html(null));
        self::assertNotNull($tracked);
        self::assertStringContainsString('/s/', (string) $tracked->email->getHtmlBody());

        // Text left alone when nothing was rewritten; the e-mail goes out untracked when the store fails.
        $plain = $this->tracker()->track($this->email()->html('<p>No links</p>')->text('No links'));
        self::assertSame('No links', $plain?->email->getTextBody());
        $this->trackingDb()->executeStatement('DROP TABLE mail_kit_tracking_send');
        self::assertNull($this->tracker()->track($this->email()));
        self::assertTrue($this->logger->has('Mail tracking store write failed; the e-mail is sent untracked.'));
    }

    public function testExtraPatternsAndABrokenSignerLeaveMailUntracked(): void
    {
        $digest = $this->email();
        MailHeaders::template($digest, 'weekly_digest');
        self::assertNull($this->tracker(neverTrack: ['weekly_*'])->track($digest));
        self::assertNotNull($this->tracker(neverTrack: [])->track($digest));

        $broken = new EmailTracker(
            new HostTrackingPolicy(),
            $this->rewriter,
            new ConfiguredTrackableLinkFilter(['app.example.com']),
            new CredentialLinkDetector(),
            static fn (): TrackingTokenSigner => new TrackingTokenSigner('too-short'),
            $this->store,
            new HtmlLinkTracker(),
            self::BASE,
            logger: $this->logger,
        );
        self::assertNull($broken->track($this->email()));
        self::assertTrue($this->logger->has('Mail tracking signer unavailable'));
        self::assertSame(1, $this->rows(), 'Only the digest above; the broken signer stored nothing.');

        $lazy = new EmailTracker(new HostTrackingPolicy(), $this->rewriter, new ConfiguredTrackableLinkFilter(['app.example.com']), new CredentialLinkDetector(), fn (): TrackingTokenSigner => $this->signer, $this->store, new HtmlLinkTracker(), self::BASE);
        self::assertNotNull($lazy->track($this->email()));
    }

    public function testTheBaseFromAnEnvValueIsCheckedWhenFirstUsed(): void
    {
        $tracker = $this->trackerWithBase('https://app.example.com/');
        $tracked = $tracker->track($this->email());
        self::assertNotNull($tracked);
        self::assertStringContainsString('<img src="https://app.example.com/e/o/', (string) $tracked->email->getHtmlBody());
        self::assertNotNull($tracker->track($this->email()), 'The validated base is reused.');
    }

    #[DataProvider('invalidBases')]
    public function testAnInvalidBaseFromAnEnvValueFailsClosed(string $base): void
    {
        $tracker = $this->trackerWithBase($base);
        self::assertNull($tracker->track($this->email()), 'No pixel and no link: the e-mail is sent untracked.');
        self::assertTrue($this->logger->has('Mail tracking disabled: nowo_mail_kit.tracking.public_base_url must be an absolute http(s) URL'));
        self::assertSame(0, $this->rows(), 'Nothing was stored.');

        $this->expectException(InvalidArgumentException::class);
        TrackingBaseUrl::normalize($base);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidBases(): iterable
    {
        yield 'javascript' => ['javascript:x'];
        yield 'user info' => ['http://user@host'];
        yield 'query' => ['https://app.example.com/?x=1'];
        yield 'fragment' => ['https://app.example.com/#x'];
        yield 'relative' => ['/app'];
        yield 'empty' => [''];
    }

    public function testAnUnexpectedFailureLeavesTheMailUntracked(): void
    {
        $renderer = new class implements BodyRendererInterface {
            public function render(Message $message): void
            {
                throw new RuntimeException('render failed');
            }
        };
        self::assertNull($this->tracker(renderer: $renderer)->track($this->email()));
        self::assertTrue($this->logger->has('Mail tracking skipped: the e-mail is sent untracked.'));
    }

    public function testAHostThatSetsTheFullPixelBaseDoesNotGetThePrefixTwice(): void
    {
        // Beacon-style compiler pass: argument 7 = DEFAULT_URI + "/e/o", the extension still passes "/e/o" last.
        foreach (['https://app.example.com/e/o', 'https://app.example.com/e/o/'] as $full) {
            $tracked = $this->trackerWithBase($full)->track($this->email());
            self::assertNotNull($tracked);
            self::assertSame(1, preg_match('~<img src="https://app\.example\.com/e/o/[A-Za-z0-9_-]{48}\.gif"~', (string) $tracked->email->getHtmlBody()));
        }
    }

    public function testBaseUrlRules(): void
    {
        self::assertFalse(TrackingBaseUrl::isPlaceholder(null));
        self::assertFalse(TrackingBaseUrl::isPlaceholder('https://app.example.com'));
        self::assertFalse(TrackingBaseUrl::isValid(42));
        self::assertTrue(TrackingBaseUrl::isValid('https://app.example.com/sub'));
        self::assertSame('http://localhost:8080/sub', TrackingBaseUrl::normalize('http://localhost:8080/sub/'));
    }

    private function trackerWithBase(string $base): EmailTracker
    {
        return new EmailTracker(new HostTrackingPolicy(), $this->rewriter, new ConfiguredTrackableLinkFilter(['app.example.com']), new CredentialLinkDetector(), $this->signer, $this->store, new HtmlLinkTracker(), $base, logger: $this->logger, pathPrefix: '/e/o');
    }

    public function testStoreWritesJoinTheHostTransactionThroughASavepoint(): void
    {
        $db = $this->trackingDb();
        $db->beginTransaction();
        $tracked = $this->tracker()->track($this->email());
        self::assertNotNull($tracked);
        self::assertTrue($db->isTransactionActive());
        $db->executeStatement('DROP TABLE mail_kit_tracking_send');
        self::assertNull($this->tracker()->track($this->email()), 'The failing insert is rolled back to its savepoint.');
        self::assertTrue($db->isTransactionActive());
        $db->rollBack();
        self::assertSame([], $this->store->missingTablesSql());
    }

    /**
     * @param list<string>|null $neverTrack
     */
    private function tracker(
        ?array $neverTrack = null,
        bool $skipRedacted = true,
        ?EmailLinkRewriterInterface $rewriter = null,
        ?TrackableLinkFilterInterface $filter = null,
        ?BodyRendererInterface $renderer = null,
        ?EmailTrackingPolicyInterface $policy = null,
    ): EmailTracker {
        return new EmailTracker(
            $policy ?? new HostTrackingPolicy(),
            $rewriter ?? $this->rewriter,
            $filter ?? new ConfiguredTrackableLinkFilter(['app.example.com']),
            new CredentialLinkDetector(),
            $this->signer,
            $this->store,
            new HtmlLinkTracker(),
            self::BASE,
            $neverTrack ?? Configuration::NEVER_TRACK_TEMPLATES,
            $skipRedacted,
            Configuration::ESSENTIAL_LINK_PATTERNS,
            2048,
            $renderer,
            $this->logger,
        );
    }

    private function email(string $to = 'user@example.com'): Email
    {
        return (new Email())->from('app@example.com')->to($to)->subject('Alert')->html(self::HTML);
    }

    private function rows(): int
    {
        return (int) $this->trackingDb()->fetchOne('SELECT COUNT(*) FROM mail_kit_tracking_send');
    }
}
