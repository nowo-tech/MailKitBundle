<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Log;

use DateTimeImmutable;
use Nowo\MailKitBundle\Log\SentMailFilters;
use Nowo\MailKitBundle\Log\SentMailRecorder;
use Nowo\MailKitBundle\Log\SentMailRepository;
use Nowo\MailKitBundle\Log\SentMailStatus;
use Nowo\MailKitBundle\Mailer\MailHeaders;
use Nowo\MailKitBundle\Tests\Integration\Kernel\KernelTestCase;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Sent-mail log persistence (SQLite): recorder writes, keyset purge, right to erasure, repository filters and the CLI.
 */
final class SentMailLogTest extends KernelTestCase
{
    public function testRecorderLifecycleAndRepository(): void
    {
        $kernel     = $this->bootKernel();
        $recorder   = $this->service($kernel, SentMailRecorder::class);
        $repository = $this->service($kernel, SentMailRepository::class);

        $email = (new Email())->from('App <APP@example.com>')->to('Alice@example.com')->bcc('audit@example.com')->subject('Invoice 100%_')->text('Hi');
        MailHeaders::tag($email, 'invoice');
        $id = $recorder->queued($email);
        self::assertNotNull($id);
        $recorder->sent($id, $email, 'smtp', 'provider-1', 1234);
        $recorder->failed($id, $email, 'smtp', new RuntimeException('second attempt failed'));

        $row = $repository->find($id);
        self::assertNotNull($row);
        self::assertSame(SentMailStatus::Failed, $row->getStatus());
        self::assertSame('invoice', $row->getTag());
        self::assertSame('app@example.com', $row->getSender());
        self::assertSame(['alice@example.com'], $row->getTo());
        self::assertSame(['to' => ['alice@example.com'], 'bcc' => ['audit@example.com']], $row->getRecipients());
        self::assertSame(2, $row->getAttempts());
        self::assertSame('provider-1', $row->getProviderMessageId());
        self::assertSame(1234, $row->getSize());
        self::assertNotNull($row->getSentAt());
        self::assertNotNull($row->getFailedAt());
        self::assertSame('second attempt failed', $row->getError());
        self::assertNull($repository->findBody($id));

        // An unknown id (purged queued row) still records the outcome; raw messages have no envelope data.
        $recorder->sent('018f0000-0000-7000-8000-000000000000', new RawMessage('raw'), 'default', null, null);
        $recorder->failed(null, new RawMessage('raw'), 'default', new RuntimeException('x'));

        $now = new DateTimeImmutable();
        self::assertSame(3, $repository->countFiltered(new SentMailFilters()));
        self::assertSame(1, $repository->countFiltered(new SentMailFilters(query: '100%_')));
        self::assertSame(0, $repository->countFiltered(new SentMailFilters(query: '100%x')));
        self::assertSame(1, $repository->countFiltered(new SentMailFilters(query: 'alice')));
        self::assertSame(2, $repository->countFiltered(new SentMailFilters(SentMailStatus::Failed)));
        self::assertSame(3, $repository->countFiltered(new SentMailFilters(since: $now->modify('-1 hour'))));
        self::assertCount(1, $repository->findFiltered(new SentMailFilters(), 1, 2));
    }

    public function testPurgeAndForgetDeleteRowsAndBodies(): void
    {
        $kernel   = $this->bootKernel(['log' => ['store_bodies' => true]]);
        $recorder = $this->service($kernel, SentMailRecorder::class);
        foreach (['a@example.com', 'b@example.com', 'a@example.com'] as $to) {
            $recorder->sent(null, (new Email())->from('app@example.com')->to($to)->subject('S')->text('t'), 'smtp', null, null);
        }
        $db = $this->connection($kernel);
        $db->executeStatement("UPDATE mail_kit_sent_mail SET queued_at = '2020-01-01 00:00:00' WHERE id IN (SELECT id FROM mail_kit_sent_mail WHERE recipients LIKE '%b@example.com%')");

        self::assertSame(0, $recorder->forgetRecipient('  '));
        self::assertSame(2, $recorder->forgetRecipient('A@example.com'));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM mail_kit_sent_mail_body'));
        self::assertSame(1, $recorder->purgeBefore(new DateTimeImmutable('2021-01-01'), 1));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM mail_kit_sent_mail'));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM mail_kit_sent_mail_body'));
    }

    public function testWriteErrorsNeverBreakDelivery(): void
    {
        $kernel = $this->bootKernel();
        $this->connection($kernel)->executeStatement('DROP TABLE mail_kit_sent_mail');
        $recorder = $this->service($kernel, SentMailRecorder::class);

        self::assertNull($recorder->queued((new Email())->from('app@example.com')->to('a@example.com')->subject('S')->text('t')));
        $recorder->sent('018f0000-0000-7000-8000-000000000000', new RawMessage('raw'), 'smtp', null, null);
        $this->addToAssertionCount(1);
    }

    public function testCommands(): void
    {
        $kernel      = $this->bootKernel();
        $application = new Application($kernel);
        $recorder    = $this->service($kernel, SentMailRecorder::class);
        $recorder->sent(null, (new Email())->from('app@example.com')->to('gone@example.com')->subject('S')->text('t'), 'smtp', null, null);

        $purge = new CommandTester($application->find('nowo:mail-kit:log:purge'));
        self::assertSame(2, $purge->execute(['--older-than-days' => '0']));
        self::assertSame(0, $purge->execute([]));
        self::assertStringContainsString('0 row(s) older than 30 day(s) deleted', $purge->getDisplay());
        self::assertSame(0, $purge->execute(['--older-than-days' => '7']));

        $forget = new CommandTester($application->find('nowo:mail-kit:log:forget'));
        self::assertSame(2, $forget->execute(['email' => 'not-an-address']));
        self::assertSame(0, $forget->execute(['email' => 'gone@example.com']));
        self::assertStringContainsString('1 row(s) deleted', $forget->getDisplay());

        $send = new CommandTester($application->find('nowo:mail-kit:send-test'));
        self::assertSame(1, $send->execute([]));
        self::assertStringContainsString('No valid recipient', $send->getDisplay());
        $this->settings($kernel)->save('mailer', ['from' => 'noreply@example.com']);
        self::assertSame(0, $send->execute(['to' => 'qa@example.com']));
        self::assertStringContainsString('Test e-mail sent to qa@example.com.', $send->getDisplay());
        self::assertSame(1, $send->execute(['to' => 'qa@example.com', '--resend' => true]));
    }
}
