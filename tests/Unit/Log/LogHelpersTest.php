<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Log;

use DateTimeImmutable;
use Nowo\MailKitBundle\Entity\SentMail;
use Nowo\MailKitBundle\Entity\SentMailBody;
use Nowo\MailKitBundle\Log\ErrorScrubber;
use Nowo\MailKitBundle\Log\SentMailFilters;
use Nowo\MailKitBundle\Log\SentMailRecorder;
use Nowo\MailKitBundle\Log\SentMailStatus;
use Nowo\MailKitBundle\Mailer\MailHeaders;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mime\Email;

use function base64_encode;
use function mb_strlen;
use function str_repeat;

final class LogHelpersTest extends TestCase
{
    public function testScrubber(): void
    {
        self::assertSame('Failed smtp://***@relay.example.com:25 and https://***@api.example.com/', ErrorScrubber::scrub('Failed smtp://user:REDACTED-TEST@relay.example.com:25 and https://token@api.example.com/'));
        self::assertSame('key re_*** Bearer ***', ErrorScrubber::scrub('key re_REDACTEDTEST Bearer abc.def-123'));
        self::assertSame(ErrorScrubber::MAX, mb_strlen(ErrorScrubber::scrub(str_repeat('x', 5000))));
    }

    public function testFilters(): void
    {
        $now     = new DateTimeImmutable('2026-10-31 12:00:00');
        $filters = SentMailFilters::fromRequest(new Request(['status' => 'failed', 'q' => '  alice  ', 'period' => '24h']), $now);
        self::assertSame(SentMailStatus::Failed, $filters->status);
        self::assertSame('alice', $filters->query);
        self::assertSame('2026-10-30 12:00:00', $filters->since?->format('Y-m-d H:i:s'));
        self::assertTrue($filters->isFiltered());
        self::assertSame(['status' => 'failed', 'q' => 'alice', 'period' => '24h'], $filters->formData());

        $defaults = SentMailFilters::fromRequest(new Request(['status' => 'nope', 'q' => 'ab', 'period' => 'forever']), $now);
        self::assertNull($defaults->status);
        self::assertNull($defaults->query);
        self::assertSame('7d', $defaults->period);
        self::assertFalse($defaults->isFiltered());
        self::assertSame(['status' => '', 'q' => '', 'period' => '7d'], $defaults->formData());
        self::assertNull(SentMailFilters::fromRequest(new Request(['period' => 'all']), $now)->since);
        self::assertSame(SentMailFilters::QUERY_MAX, mb_strlen((string) SentMailFilters::fromRequest(new Request(['q' => str_repeat('a', 500)]), $now)->query));
    }

    public function testEntitiesAndEscaping(): void
    {
        $row = new SentMail('id-1', new DateTimeImmutable('2026-10-31'));
        self::assertSame('id-1', $row->getId());
        self::assertSame(SentMailStatus::Queued, $row->getStatus());
        self::assertSame([], $row->getTo());
        self::assertSame('', $row->getRecipientIndex());
        self::assertSame('|a@x.example|b@x.example|', (new SentMail('id-2', new DateTimeImmutable(), recipients: ['to' => ['a@x.example'], 'cc' => ['b@x.example'], 'bcc' => ['a@x.example']]))->getRecipientIndex());
        self::assertNull($row->getTag());
        self::assertSame('', $row->getTransport());
        $body = new SentMailBody('id-1', '<p>x</p>', 'x');
        self::assertSame('id-1', $body->getId());
        self::assertSame('<p>x</p>', $body->getHtmlBody());
        self::assertSame('x', $body->getTextBody());
        self::assertSame('a!%b!_c!!', SentMailRecorder::likeEscape('a%b_c!'));
    }

    public function testHeaders(): void
    {
        $email = new Email();
        MailHeaders::tag($email, '  ');
        self::assertNull(MailHeaders::value($email, MailHeaders::TAG));
        MailHeaders::tag($email, str_repeat('t', 100));
        self::assertSame(MailHeaders::TAG_MAX, mb_strlen((string) MailHeaders::value($email, MailHeaders::TAG)));
        MailHeaders::redact($email, 'one', '');
        MailHeaders::redact($email, 'two');
        self::assertSame(['one', 'two'], MailHeaders::secrets($email));
        MailHeaders::redact($email);
        self::assertSame(['one', 'two'], MailHeaders::secrets($email));
        $email->getHeaders()->addTextHeader(MailHeaders::LOG_ID, 'x');
        MailHeaders::strip($email);
        foreach ([MailHeaders::LOG_ID, MailHeaders::TAG, MailHeaders::REDACT] as $name) {
            self::assertFalse($email->getHeaders()->has($name));
        }
        self::assertSame([], MailHeaders::secrets($email));
        $junk = new Email();
        $junk->getHeaders()->addTextHeader(MailHeaders::REDACT, base64_encode('{"a":1}'));
        self::assertSame([], MailHeaders::secrets($junk));
        $email2 = new Email();
        MailHeaders::redact($email2);
        self::assertFalse($email2->getHeaders()->has(MailHeaders::REDACT));
    }
}
