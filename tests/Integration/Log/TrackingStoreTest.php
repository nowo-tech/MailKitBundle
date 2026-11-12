<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Log;

use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Nowo\MailKitBundle\Integration\UserKit\EmailTrackingPersonalDataEraser;
use Nowo\MailKitBundle\Integration\UserKit\EmailTrackingPersonalDataExportProvider;
use Nowo\MailKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\MailKitBundle\Tests\Integration\Kernel\MailKitTestKernel;
use Nowo\MailKitBundle\Tests\Support\TrackingKernelTrait;
use Nowo\MailKitBundle\Tracking\DefaultTrackingRecipientResolver;
use Nowo\MailKitBundle\Tracking\EmailTrackingPersonalDataInterface;
use Nowo\MailKitBundle\Tracking\EmailTrackingReaderInterface;
use Nowo\MailKitBundle\Tracking\Store\TrackingStore;
use Nowo\MailKitBundle\Tracking\TrackingTokenSigner;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\PersonalDataExporter;
use stdClass;
use Symfony\Component\Clock\MockClock;

use function array_column;
use function sort;

/**
 * 1.10 tracking table on the configured database (CI: SQLite, MySQL 8.4, MariaDB 11, PostgreSQL 17 through
 * MAIL_KIT_TEST_DATABASE_URL): created with the ORM schema, opens counted with one UPDATE (human / machine apart),
 * exact recipient ids, GDPR export / erasure (also through the UserKit bridge), purge in batches, and a failing
 * write inside the host transaction that leaves it usable (savepoint, PostgreSQL).
 */
final class TrackingStoreTest extends KernelTestCase
{
    use TrackingKernelTrait;

    public function testOpensAreCountedAndReadBack(): void
    {
        $store = $this->trackingStoreOf($this->boot());
        self::assertSame([], $store->missingTablesSql(), 'The ORM schema listener created the table.');

        $a = TrackingTokenSigner::newSendId();
        $b = TrackingTokenSigner::newSendId();
        self::assertTrue($store->createSend($a, 'issue_alert', 'UserA', 'acme', 'log-a'));
        self::assertTrue($store->createSend($b, null, null, null));
        self::assertFalse($store->createSend($a, null, null, null), 'Duplicate send id.');

        self::assertTrue($store->recordOpen($a, false, '203.0.113.0', 'apple_mail', 1_000));
        self::assertTrue($store->recordOpen($a, false, '203.0.113.0', 'firefox', 2_000));
        self::assertTrue($store->recordOpen($a, true, '17.58.0.0', 'apple_mail', 1_500));
        self::assertFalse($store->recordOpen(TrackingTokenSigner::newSendId(), false, null, null, 3_000), 'Unknown send.');

        $stats = $store->find($a);
        self::assertNotNull($stats);
        self::assertSame(['log-a', 'issue_alert', 'UserA', 'acme', 2, 1_000, 2_000, 1, 1_500, 1_500], [
            $stats->logId, $stats->templateId, $stats->recipientId, $stats->tenant, $stats->opens,
            $stats->firstOpenAt?->getTimestamp(), $stats->lastOpenAt?->getTimestamp(), $stats->machineOpens,
            $stats->machineFirstOpenAt?->getTimestamp(), $stats->machineLastOpenAt?->getTimestamp(),
        ]);
        self::assertSame(['17.58.0.0', 'apple_mail'], [$stats->lastOpenIp, $stats->lastOpenFamily]);
        self::assertTrue($stats->opened());

        $many = $store->findMany([$a, $b, 'not-an-id', TrackingTokenSigner::newSendId()]);
        $keys = array_keys($many);
        sort($keys);
        $expected = [$a, $b];
        sort($expected);
        self::assertSame($expected, $keys);
        self::assertSame(0, $many[$b]->opens);
        self::assertNull($store->find('not-an-id'));

        $store->attachLog($b, 'log-b');
        self::assertSame('log-b', $store->find($b)?->logId);
        $store->discard($b);
        self::assertNull($store->find($b));
    }

    public function testRecipientIdsMatchExactlyForExportAndErasure(): void
    {
        $kernel = $this->boot();
        $store  = $this->trackingStoreOf($kernel);
        foreach (['UserA', 'usera', 'UserA', 'Usera '] as $i => $recipient) {
            self::assertTrue($store->createSend(TrackingTokenSigner::newSendId(), 'alert_' . $i, $recipient === 'Usera ' ? null : $recipient, 'acme'));
        }

        $export    = $store->exportRecipient('UserA');
        $templates = array_column($export, 'template');
        sort($templates);
        self::assertSame(['alert_0', 'alert_2'], $templates, 'Same second: ordered by random send id.');
        self::assertSame(0, $export[0]['opens']);
        self::assertNull($export[0]['first_open_at']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT/', $export[0]['sent_at']);

        self::assertSame(2, $store->eraseRecipient('UserA'));
        self::assertSame(0, $store->eraseRecipient('UserA'), 'Idempotent.');
        self::assertSame(['alert_1'], array_column($store->exportRecipient('usera'), 'template'));

        try {
            $store->eraseRecipient(' padded');
            self::fail('Unacceptable id.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $this->expectException(InvalidArgumentException::class);
        $store->exportRecipient('');
    }

    public function testTheUserKitBridgeExportsAndErasesByRecipientId(): void
    {
        $kernel = $this->boot();
        $store  = $this->trackingStoreOf($kernel);
        $port   = $this->service($kernel, EmailTrackingPersonalDataInterface::class);
        $user   = new class {
            public function getId(): string
            {
                return 'UserA';
            }
        };
        $store->createSend(TrackingTokenSigner::newSendId(), 'alert', 'UserA', null);
        $store->createSend(TrackingTokenSigner::newSendId(), 'alert', 'usera', null);

        $provider = new EmailTrackingPersonalDataExportProvider($port, new DefaultTrackingRecipientResolver());
        self::assertSame('email_tracking', $provider->getSectionKey());
        self::assertTrue($provider->supports(new stdClass()));
        $document = (new PersonalDataExporter([$provider], new MockClock()))->export($user);
        self::assertIsArray($document['sections']['email_tracking']);
        self::assertCount(1, $document['sections']['email_tracking']);
        self::assertSame([], $provider->export(new stdClass()));

        $eraser = new EmailTrackingPersonalDataEraser($port, new DefaultTrackingRecipientResolver());
        self::assertSame('email_tracking', $eraser->getKey());
        self::assertTrue($eraser->supports(new stdClass()));
        // UserKit runs every eraser inside one transaction: the DBAL statements join it.
        $db = $this->connection($kernel);
        $db->beginTransaction();
        $eraser->erase(new ErasureRequest($user));
        $db->commit();
        self::assertSame([], $store->exportRecipient('UserA'));
        self::assertCount(1, $store->exportRecipient('usera'));
    }

    public function testPurgeDeletesOldSendsInBatches(): void
    {
        $clock    = new MockClock('2026-01-01 00:00:00');
        $kernel   = $this->boot();
        $doctrine = $this->container($kernel)->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $store = new TrackingStore($doctrine, null, '', $clock);
        for ($i = 0; $i < 7; ++$i) {
            $store->createSend(TrackingTokenSigner::newSendId(), null, null, null);
        }
        $clock->modify('+10 days');
        $fresh = TrackingTokenSigner::newSendId();
        $store->createSend($fresh, null, null, null);

        self::assertSame(7, $store->purge($clock->now()->getTimestamp() - 86_400, 3));
        self::assertSame(0, $store->purge($clock->now()->getTimestamp() - 86_400, 3));
        self::assertNotNull($store->find($fresh));
    }

    public function testAFailingWriteInsideTheHostTransactionLeavesItUsable(): void
    {
        $kernel = $this->boot();
        $store  = $this->trackingStoreOf($kernel);
        $db     = $this->connection($kernel);
        $id     = TrackingTokenSigner::newSendId();
        $store->createSend($id, null, null, null);

        $db->beginTransaction();
        self::assertFalse($store->createSend($id, null, null, null), 'Duplicate key, rolled back to the savepoint.');
        $other = TrackingTokenSigner::newSendId();
        self::assertTrue($store->createSend($other, null, null, null), 'The host transaction still works (PostgreSQL).');
        self::assertSame(1, (int) $db->fetchOne('SELECT 1'));
        $db->commit();
        self::assertNotNull($store->find($other));
    }

    private function boot(): MailKitTestKernel
    {
        return $this->bootKernel(['tracking' => self::tracking()], extra: self::trackingServices());
    }

    private function trackingStoreOf(MailKitTestKernel $kernel): TrackingStore
    {
        $reader = $this->service($kernel, EmailTrackingReaderInterface::class);
        self::assertInstanceOf(TrackingStore::class, $reader);

        return $reader;
    }
}
