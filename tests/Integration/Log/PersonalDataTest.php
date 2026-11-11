<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Log;

use ArrayObject;
use DateTimeImmutable;
use DateTimeInterface;
use Nowo\MailKitBundle\Event\SentMailPersonalDataErasedEvent;
use Nowo\MailKitBundle\Log\SentMailErasureInterface;
use Nowo\MailKitBundle\Log\SentMailLogTableMissingException;
use Nowo\MailKitBundle\Log\SentMailPersonalDataEraserInterface;
use Nowo\MailKitBundle\Log\SentMailPersonalDataExporterInterface;
use Nowo\MailKitBundle\Log\SentMailPersonalDataSubject;
use Nowo\MailKitBundle\Log\SentMailRecorder;
use Nowo\MailKitBundle\Mailer\MailHeaders;
use Nowo\MailKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\MailKitBundle\Tests\Integration\Kernel\MailKitTestKernel;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;

use function array_column;
use function array_keys;
use function str_starts_with;

/**
 * GDPR export port and erasure semantics (1.7) on SQLite, and on MySQL / MariaDB / PostgreSQL in CI
 * (MAIL_KIT_TEST_DATABASE_URL): exact matching, idempotency, batches, transactions and the erased event.
 */
final class PersonalDataTest extends KernelTestCase
{
    public function testExportIsEnvelopeOnlyAndExact(): void
    {
        $kernel   = $this->bootKernel(['log' => ['store_bodies' => true]]);
        $recorder = $this->service($kernel, SentMailRecorder::class);
        $welcome  = (new Email())->from('app@example.com')->to('josé@ejemplo.es')->cc('other@example.com')->subject('Welcome')->text('secret body')->html('<p>secret body</p>');
        MailHeaders::tag($welcome, 'welcome');
        $recorder->sent(null, $welcome, 'smtp', 'provider-1', 10);
        $recorder->failed(null, (new Email())->from('app@example.com')->to('jose@ejemplo.es')->subject('Not you')->text('t'), 'smtp', 'boom');
        $recorder->sent(null, (new Email())->from('Ana@Example.com')->to('other@example.com')->cc('ana@example.com')->subject('From and to Ana')->text('t'), 'smtp', null, null);
        $recorder->queued((new Email())->from('app@example.com')->bcc('user@bücher.de')->subject('Queued')->text('t'));

        $exporter = $this->service($kernel, SentMailPersonalDataExporterInterface::class);
        self::assertSame($recorder, $exporter);

        $rows = $exporter->export(SentMailPersonalDataSubject::fromEmail('JOSÉ@ejemplo.es'));
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame(['id', 'roles', 'status', 'template', 'subject', 'queued_at', 'sent_at', 'failed_at'], array_keys($row));
        self::assertSame(['recipient'], $row['roles']);
        self::assertSame(['sent', 'welcome', 'Welcome', null], [$row['status'], $row['template'], $row['subject'], $row['failed_at']]);
        self::assertNotNull($row['queued_at']);
        self::assertNotFalse(DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $row['queued_at']));
        self::assertNotNull($row['sent_at']);

        // Accent variant: another person, even on an accent-insensitive MySQL collation.
        $jose = $exporter->export(SentMailPersonalDataSubject::fromEmail('jose@ejemplo.es'));
        self::assertSame([['Not you', 'failed']], array_map(static fn (array $r): array => [$r['subject'], $r['status']], $jose));
        self::assertNotNull($jose[0]['failed_at']);
        self::assertNull($jose[0]['sent_at']);

        // The log folds case on write: Ana@ and ana@ are one address; she is both sender and recipient.
        $ana = $exporter->export(SentMailPersonalDataSubject::fromEmail('ana@example.com'));
        self::assertCount(1, $ana);
        self::assertSame(['recipient', 'sender'], $ana[0]['roles']);

        // Bcc and punycode.
        $idn = $exporter->export(SentMailPersonalDataSubject::fromEmail('user@xn--bcher-kva.de'));
        self::assertSame(['queued', null, null], [$idn[0]['status'], $idn[0]['template'], $idn[0]['sent_at']]);

        self::assertSame([], $exporter->export(SentMailPersonalDataSubject::fromEmail('nobody@example.com')));
        // A prefix or suffix of an indexed address is not the address.
        self::assertSame([], $exporter->export(SentMailPersonalDataSubject::fromEmail('her@example.com')));
        self::assertSame([], $exporter->export(SentMailPersonalDataSubject::fromEmail('na@example.com')));
    }

    public function testErasureIsExactAndIdempotent(): void
    {
        $kernel   = $this->bootKernel(['log' => ['store_bodies' => true]]);
        $recorder = $this->service($kernel, SentMailRecorder::class);
        $recorder->sent(null, (new Email())->from('app@example.com')->to('josé@ejemplo.es')->subject('Hola josé@ejemplo.es')->text('t'), 'smtp', null, null);
        $recorder->sent(null, (new Email())->from('app@example.com')->to('jose@ejemplo.es')->subject('Hola jose@ejemplo.es')->text('Hi josé@ejemplo.es'), 'smtp', null, null);
        $recorder->sent(null, (new Email())->from('app@example.com')->to('ops@example.com')->subject('About JOSE@ejemplo.es')->text('t'), 'smtp', null, null);

        $erasure = $this->service($kernel, SentMailErasureInterface::class);
        // Only jose@'s row goes and only JOSE@ is masked; josé@'s row and subject stay (an accent-insensitive LIKE only pre-filters).
        self::assertSame(['deleted' => 1, 'unlinked' => 0, 'redacted' => 1], $erasure->forgetAddress('jose@ejemplo.es'));
        self::assertSame(['deleted' => 0, 'unlinked' => 0, 'redacted' => 0], $erasure->forgetAddress('jose@ejemplo.es'));
        $db = $this->connection($kernel);
        self::assertSame(['About [redacted]', 'Hola josé@ejemplo.es'], $db->fetchFirstColumn('SELECT subject FROM mail_kit_sent_mail ORDER BY subject'));
        self::assertCount(1, $recorder->export(SentMailPersonalDataSubject::fromEmail('josé@ejemplo.es')));
    }

    /**
     * The subject-keyed port is the same erasure; masking replaces whole addresses only, so erasing `na@` never
     * damages `ana@` or `na@example.com.au` (other people) in the subjects and bodies that stay.
     */
    public function testSubjectPortAndWholeAddressMasking(): void
    {
        $kernel   = $this->bootKernel(['log' => ['store_bodies' => true]]);
        $recorder = $this->service($kernel, SentMailRecorder::class);
        $recorder->sent(null, (new Email())->from('app@example.com')->to('na@example.com')->subject('For na')->text('t'), 'smtp', null, null);
        $recorder->sent(null, (new Email())->from('app@example.com')->to('ops@example.com')->subject('ana@example.com, NA@example.com.')->text('/u/na@example.com ?e=' . rawurlencode('na@example.com') . ' na@example.com.au x.na@example.com')->html('<a href="mailto:na@example.com">na@example.com</a>'), 'smtp', null, null);

        $eraser = $this->service($kernel, SentMailPersonalDataEraserInterface::class);
        self::assertSame($recorder, $eraser);
        $result = $eraser->erase(SentMailPersonalDataSubject::fromEmail('NA@example.com'));
        self::assertSame(['deleted' => 1, 'unlinked' => 0, 'redacted' => 2], $result->toArray());
        self::assertSame(0, $eraser->erase(SentMailPersonalDataSubject::fromEmail('na@example.com'))->total());

        $db = $this->connection($kernel);
        self::assertSame('ana@example.com, [redacted].', $db->fetchOne('SELECT subject FROM mail_kit_sent_mail'));
        $body = $db->fetchAssociative('SELECT html_body, text_body FROM mail_kit_sent_mail_body');
        self::assertIsArray($body);
        self::assertSame('/u/[redacted] ?e=[redacted] na@example.com.au x.na@example.com', $body['text_body']);
        self::assertSame('<a href="mailto:[redacted]">[redacted]</a>', $body['html_body']);
    }

    /**
     * 1.7 (owner decision LY-M3): a message to several people is one log row shared by them. Erasing one recipient
     * unlinks that address and keeps the row for the others; the row goes only with its last recipient.
     */
    public function testSharedMessagesAreUnlinkedNotDeleted(): void
    {
        $kernel   = $this->bootKernel(['log' => ['store_bodies' => true]]);
        $recorder = $this->service($kernel, SentMailRecorder::class);
        $recorder->sent(null, (new Email())->from('app@example.com')->to('a@example.com', 'b@example.com')->cc('A@Example.com')->bcc('c@example.com')->subject('Team, incl. a@example.com')->text('Hi a@example.com and b@example.com'), 'smtp', null, null);

        $result = $recorder->erase(SentMailPersonalDataSubject::fromEmail('a@example.com'));
        self::assertSame(['deleted' => 0, 'unlinked' => 1, 'redacted' => 1], $result->toArray());
        self::assertSame(0, $recorder->erase(SentMailPersonalDataSubject::fromEmail('a@example.com'))->total());

        // b@ and c@ still see the row and their own data; a@ appears nowhere.
        foreach (['b@example.com', 'c@example.com'] as $other) {
            $rows = $recorder->export(SentMailPersonalDataSubject::fromEmail($other));
            self::assertCount(1, $rows);
            self::assertSame(['recipient'], $rows[0]['roles']);
            self::assertSame('Team, incl. [redacted]', $rows[0]['subject']);
        }
        self::assertSame([], $recorder->export(SentMailPersonalDataSubject::fromEmail('a@example.com')));
        $db  = $this->connection($kernel);
        $row = $db->fetchAssociative('SELECT m.recipients, m.recipient_index, m.subject, m.sender, b.text_body FROM mail_kit_sent_mail m JOIN mail_kit_sent_mail_body b ON b.id = m.id');
        self::assertIsArray($row);
        self::assertSame(['to' => ['b@example.com'], 'bcc' => ['c@example.com']], json_decode((string) $row['recipients'], true));
        self::assertSame(SentMailRecorder::recipientIndex(['to' => ['b@example.com'], 'bcc' => ['c@example.com']]), $row['recipient_index']);
        self::assertSame('Hi [redacted] and b@example.com', $row['text_body']);
        self::assertStringNotContainsStringIgnoringCase('a@example.com', (string) json_encode($row));

        // The last recipients: the row goes with the last one.
        self::assertSame(['deleted' => 0, 'unlinked' => 1, 'redacted' => 1], $recorder->forgetAddress('b@example.com'));
        self::assertSame(['deleted' => 1, 'unlinked' => 0, 'redacted' => 0], $recorder->forgetAddress('c@example.com'));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM mail_kit_sent_mail'));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM mail_kit_sent_mail_body'));
    }

    /**
     * The erased address is the sender: rows that went to other people stay with the sender masked; a message to
     * oneself goes. This covers a site whose `default_from` is the person's own mailbox: erasing that person does not
     * wipe the log of what the site sent to everybody else.
     */
    public function testSenderIsMaskedWhenOthersReceivedTheMessage(): void
    {
        $kernel   = $this->bootKernel(['sender' => ['default_from' => 'owner@shop.example']]);
        $recorder = $this->service($kernel, SentMailRecorder::class);
        foreach (['client1@example.com', 'client2@example.com', 'owner@shop.example'] as $to) {
            $recorder->sent(null, (new Email())->from('owner@shop.example')->to($to)->subject('Order for ' . $to)->text('t'), 'smtp', null, null);
        }

        self::assertSame(['deleted' => 1, 'unlinked' => 2, 'redacted' => 0], $recorder->forgetAddress('Owner@Shop.example'));
        self::assertSame(['deleted' => 0, 'unlinked' => 0, 'redacted' => 0], $recorder->forgetAddress('owner@shop.example'));
        $db = $this->connection($kernel);
        self::assertSame([['Order for client1@example.com', '[redacted]'], ['Order for client2@example.com', '[redacted]']], array_map(array_values(...), $db->fetchAllAssociative('SELECT subject, sender FROM mail_kit_sent_mail ORDER BY subject')));
        self::assertSame([], $recorder->export(SentMailPersonalDataSubject::fromEmail('owner@shop.example')));
        self::assertSame(['Order for client1@example.com'], array_column($recorder->export(SentMailPersonalDataSubject::fromEmail('client1@example.com')), 'subject'));
    }

    /**
     * Unlinking is exact too: an accent / look-alike co-recipient keeps its entry (accent-insensitive MySQL collations
     * only pre-filter).
     */
    public function testUnlinkingIsExact(): void
    {
        $kernel   = $this->bootKernel();
        $recorder = $this->service($kernel, SentMailRecorder::class);
        $recorder->sent(null, (new Email())->from('josé@ejemplo.es')->to('jose@ejemplo.es', 'josé@ejemplo.es', 'ajose@ejemplo.es')->subject('S')->text('t'), 'smtp', null, null);

        self::assertSame(['deleted' => 0, 'unlinked' => 1, 'redacted' => 0], $recorder->forgetAddress('jose@ejemplo.es'));
        $row = $this->connection($kernel)->fetchAssociative('SELECT recipients, sender FROM mail_kit_sent_mail');
        self::assertIsArray($row);
        self::assertSame([['to' => ['josé@ejemplo.es', 'ajose@ejemplo.es']], 'josé@ejemplo.es'], [json_decode((string) $row['recipients'], true), $row['sender']]);
        self::assertSame(['recipient', 'sender'], $recorder->export(SentMailPersonalDataSubject::fromEmail('josé@ejemplo.es'))[0]['roles']);
    }

    /**
     * A row whose stored recipient list cannot be read is deleted rather than kept with the address in it.
     */
    public function testUnreadableRecipientListIsDeleted(): void
    {
        if (!str_starts_with(MailKitTestKernel::databaseUrl(), 'sqlite')) {
            self::markTestSkipped('MySQL and PostgreSQL JSON columns refuse invalid JSON.');
        }
        $kernel = $this->bootKernel();
        $this->insert($kernel, 'x@example.com', 'Broken');
        $this->connection($kernel)->executeStatement("UPDATE mail_kit_sent_mail SET recipients = 'not json'");

        self::assertSame(['deleted' => 1, 'unlinked' => 0, 'redacted' => 0], $this->service($kernel, SentMailRecorder::class)->forgetAddress('x@example.com'));
    }

    public function testLargeMailboxesArePagedAndOthersSurvive(): void
    {
        $kernel = $this->bootKernel();
        $db     = $this->connection($kernel);
        $total  = (2 * SentMailRecorder::PERSONAL_DATA_BATCH) + 7;
        $db->beginTransaction();
        for ($i = 0; $i < $total; ++$i) {
            $this->insert($kernel, 'bulk@example.com', 'Bulk ' . $i);
            if ($i % 100 === 0) {
                $this->insert($kernel, 'bulk@example.org', 'Neighbour ' . $i);
            }
        }
        $db->commit();

        $recorder = $this->service($kernel, SentMailRecorder::class);
        $rows     = $recorder->export(SentMailPersonalDataSubject::fromEmail('bulk@example.com'));
        self::assertCount($total, $rows);
        self::assertCount($total, array_unique(array_column($rows, 'id')));
        self::assertSame(['deleted' => $total, 'unlinked' => 0, 'redacted' => 0], $recorder->forgetAddress('bulk@example.com'));
        self::assertSame(11, (int) $db->fetchOne('SELECT COUNT(*) FROM mail_kit_sent_mail'));
    }

    public function testOwnTransactionDispatchesAfterCommitAndCallerTransactionDispatchesNothing(): void
    {
        $kernel   = $this->bootKernel();
        $recorder = $this->service($kernel, SentMailRecorder::class);
        $db       = $this->connection($kernel);
        /** @var ArrayObject<int, mixed> $events */
        $events     = new ArrayObject();
        $dispatcher = $this->container($kernel)->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(SentMailPersonalDataErasedEvent::class, static function (SentMailPersonalDataErasedEvent $event) use ($events, $db): void {
            $events[] = [$event->email, $event->deleted, $event->redacted, $db->isTransactionActive()];
        });
        foreach (['a@example.com', 'a@example.com', 'b@example.com'] as $to) {
            $recorder->sent(null, (new Email())->from('app@example.com')->to($to)->subject('Hi')->text('t'), 'smtp', null, null);
        }

        // Inside the caller's transaction: joined, no event, a rollback undoes it.
        $db->beginTransaction();
        self::assertSame(['deleted' => 2, 'unlinked' => 0, 'redacted' => 0], $recorder->forgetAddress('A@example.com'));
        self::assertTrue($db->isTransactionActive());
        $db->rollBack();
        self::assertSame([], $events->getArrayCopy());
        self::assertSame(3, (int) $db->fetchOne('SELECT COUNT(*) FROM mail_kit_sent_mail'));

        // Own transaction: committed, then one event (outside any transaction), also for a no-op run.
        self::assertSame(['deleted' => 2, 'unlinked' => 0, 'redacted' => 0], $recorder->forgetAddress('A@example.com'));
        self::assertSame(['deleted' => 0, 'unlinked' => 0, 'redacted' => 0], $recorder->forgetAddress('a@example.com'));
        self::assertSame([['a@example.com', 2, 0, false], ['a@example.com', 0, 0, false]], $events->getArrayCopy());
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM mail_kit_sent_mail'));
    }

    public function testMissingTables(): void
    {
        $optional = $this->bootKernel(['log' => ['enabled' => false, 'map_entities' => false]]);
        self::assertSame([], $this->service($optional, SentMailPersonalDataExporterInterface::class)->export(SentMailPersonalDataSubject::fromEmail('a@example.com')));

        $mapped = $this->bootKernel();
        $this->connection($mapped)->executeStatement('DROP TABLE mail_kit_sent_mail_body');
        $this->connection($mapped)->executeStatement('DROP TABLE mail_kit_sent_mail');
        $this->expectException(SentMailLogTableMissingException::class);
        $this->service($mapped, SentMailPersonalDataExporterInterface::class)->export(SentMailPersonalDataSubject::fromEmail('a@example.com'));
    }

    /**
     * FrankenPHP worker mode (kernel kept, no reset): the same service instances serve user A then user B many times;
     * nothing leaks between them and memory stays flat.
     */
    public function testWorkerModeRepeatedExportAndErasureDoNotLeak(): void
    {
        $kernel   = $this->bootKernel();
        $recorder = $this->service($kernel, SentMailRecorder::class);
        $exporter = $this->service($kernel, SentMailPersonalDataExporterInterface::class);
        $erasure  = $this->service($kernel, SentMailErasureInterface::class);
        $a        = SentMailPersonalDataSubject::fromEmail('worker-a@example.com');
        $b        = SentMailPersonalDataSubject::fromEmail('worker-b@example.com');
        $runs     = str_starts_with(MailKitTestKernel::databaseUrl(), 'sqlite') ? 1000 : 100;
        $baseline = 0;

        for ($i = 0; $i < $runs; ++$i) {
            $recorder->sent(null, (new Email())->from('app@example.com')->to('worker-a@example.com')->subject('A ' . $i)->text('t'), 'smtp', null, null);
            $recorder->sent(null, (new Email())->from('app@example.com')->to('worker-b@example.com')->subject('B ' . $i)->text('t'), 'smtp', null, null);

            $rowsA = $exporter->export($a);
            $rowsB = $exporter->export($b);
            self::assertSame(['A ' . $i], array_column($rowsA, 'subject'));
            self::assertSame(['B ' . $i], array_column($rowsB, 'subject'));
            self::assertSame(['deleted' => 1, 'unlinked' => 0, 'redacted' => 0], $erasure->forgetAddress($a->email));
            self::assertSame([], $exporter->export($a));
            self::assertSame(['B ' . $i], array_column($exporter->export($b), 'subject'));
            self::assertSame(['deleted' => 1, 'unlinked' => 0, 'redacted' => 0], $erasure->forgetAddress($b->email));

            if ($i === 50) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }
        gc_collect_cycles();
        self::assertLessThan(2 * 1024 * 1024, memory_get_usage() - $baseline);
        self::assertSame(0, (int) $this->connection($kernel)->fetchOne('SELECT COUNT(*) FROM mail_kit_sent_mail'));
    }

    private function insert(MailKitTestKernel $kernel, string $to, string $subject): void
    {
        $this->connection($kernel)->insert('mail_kit_sent_mail', [
            'id'              => Uuid::v7()->toRfc4122(),
            'status'          => 'sent',
            'tag'             => null,
            'subject'         => $subject,
            'sender'          => 'app@example.com',
            'recipients'      => '{"to":["' . $to . '"]}',
            'recipient_index' => SentMailRecorder::recipientIndex(['to' => [$to]]),
            'transport'       => 'smtp',
            'attempts'        => 1,
            'queued_at'       => '2026-11-11 10:00:00',
        ]);
    }
}
