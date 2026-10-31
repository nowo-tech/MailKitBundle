<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Log;

use Nowo\MailKitBundle\Log\SentMailFilters;
use Nowo\MailKitBundle\Log\SentMailRecorder;
use Nowo\MailKitBundle\Log\SentMailRepository;
use Nowo\MailKitBundle\Mailer\MailHeaders;
use Nowo\MailKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;

/**
 * Regression tests of the SEC-004 audit CA finding L2: erasure and recipient search use a normalised index (no JSON
 * matching), cover IDN / slash addresses and the sender, and mask the address in subjects and bodies.
 */
final class ErasureTest extends KernelTestCase
{
    public function testErasureCoversEscapedAndInternationalAddresses(): void
    {
        $kernel   = $this->bootKernel(['log' => ['store_bodies' => true]]);
        $recorder = $this->service($kernel, SentMailRecorder::class);
        foreach (['ana/lopez@example.com', 'JOSÉ@ejemplo.es', 'user@bücher.de'] as $to) {
            $recorder->sent(null, (new Email())->from('app@example.com')->to($to)->subject('Hi')->text('t'), 'smtp', null, null);
        }
        $recorder->sent(null, (new Email())->from('josé@ejemplo.es')->to('ops@example.com')->subject('From José')->text('t'), 'smtp', null, null);
        $recorder->sent(null, (new Email())->from('app@example.com')->to('ops@example.com')->subject('About josé@ejemplo.es')->text('Contact: josé@ejemplo.es / ' . rawurlencode('josé@ejemplo.es'))->html('<p>josé@ejemplo.es</p>'), 'smtp', null, null);

        $db = $this->connection($kernel);
        self::assertSame(SentMailRecorder::SEPARATOR . 'ana/lopez@example.com' . SentMailRecorder::SEPARATOR, $db->fetchOne("SELECT recipient_index FROM mail_kit_sent_mail WHERE subject = 'Hi' AND recipient_index LIKE '%ana%'"));

        self::assertSame(['deleted' => 1, 'redacted' => 0], $recorder->forgetAddress('ana/lopez@example.com'));
        self::assertSame(['deleted' => 2, 'redacted' => 2], $recorder->forgetAddress(' José@Ejemplo.es '));
        self::assertSame(1, $recorder->forgetRecipient('user@bücher.de'));
        self::assertSame(['deleted' => 0, 'redacted' => 0], $recorder->forgetAddress(''));

        self::assertSame('About [redacted]', $db->fetchOne('SELECT subject FROM mail_kit_sent_mail'));
        $body = $db->fetchAssociative('SELECT html_body, text_body FROM mail_kit_sent_mail_body');
        self::assertIsArray($body);
        self::assertSame('<p>[redacted]</p>', $body['html_body']);
        self::assertSame('Contact: [redacted] / [redacted]', $body['text_body']);
    }

    /**
     * CA R1 / R2: the index separator cannot appear in an address; Unicode (NFC) and punycode forms are equivalent.
     */
    public function testSeparatorAndIdnEquivalence(): void
    {
        $kernel   = $this->bootKernel();
        $recorder = $this->service($kernel, SentMailRecorder::class);
        foreach (['a|bob@example.com', 'user@bücher.de', "jose\u{0301}@example.com"] as $to) {
            $recorder->sent(null, (new Email())->from('app@example.com')->to($to)->subject('Hi')->text('t'), 'smtp', null, null);
        }

        self::assertSame(['deleted' => 0, 'redacted' => 0], $recorder->forgetAddress('bob@example.com'));
        self::assertSame(1, $recorder->forgetAddress('A|BOB@example.com')['deleted']);
        self::assertSame(1, $recorder->forgetAddress('user@xn--bcher-kva.de')['deleted']);
        self::assertSame(1, $recorder->forgetAddress('josé@example.com')['deleted']);
        self::assertSame(0, (int) $this->connection($kernel)->fetchOne('SELECT COUNT(*) FROM mail_kit_sent_mail'));
    }

    public function testRedactedValuesAreMaskedInEncodedFormsToo(): void
    {
        $kernel = $this->bootKernel(['log' => ['store_bodies' => true]]);
        $secret = 'tok+en/REDACTED=';
        $email  = (new Email())->from('app@example.com')->to('a@example.com')->subject('Link')
            ->text('raw ' . $secret . ' url ' . rawurlencode($secret) . ' form ' . urlencode($secret) . ' b64 ' . base64_encode($secret));
        MailHeaders::redact($email, $secret);
        $this->service($kernel, SentMailRecorder::class)->sent(null, $email, 'smtp', null, null);

        self::assertSame('raw [redacted] url [redacted] form [redacted] b64 [redacted]', $this->connection($kernel)->fetchOne('SELECT text_body FROM mail_kit_sent_mail_body'));
    }

    public function testSearchUsesTheIndexAndTheCliAcceptsIdn(): void
    {
        $kernel   = $this->bootKernel();
        $recorder = $this->service($kernel, SentMailRecorder::class);
        $email    = (new Email())->from('app@example.com')->to('josé@ejemplo.es')->subject('Welcome')->text('t');
        MailHeaders::tag($email, 'welcome');
        $recorder->sent(null, $email, 'smtp', null, null);

        self::assertSame(1, $this->service($kernel, SentMailRepository::class)->countFiltered(new SentMailFilters(query: 'josé@ejemplo')));

        $command = new CommandTester((new Application($kernel))->find('nowo:mail-kit:log:forget'));
        self::assertSame(0, $command->execute(['email' => 'josé@ejemplo.es']));
        self::assertStringContainsString('1 row(s) deleted, 0 row(s) redacted', $command->getDisplay());
    }
}
