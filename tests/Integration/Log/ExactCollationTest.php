<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Log;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use InvalidArgumentException;
use Nowo\MailKitBundle\Doctrine\ExactCollation;
use Nowo\MailKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\MailKitBundle\Tests\Integration\Kernel\MailKitTestKernel;
use Nowo\MailKitBundle\Tests\Support\TrackingKernelTrait;
use Nowo\MailKitBundle\Tracking\EmailTrackingReaderInterface;
use Nowo\MailKitBundle\Tracking\Store\TrackingStore;
use Nowo\MailKitBundle\Tracking\TrackingDecision;
use Nowo\MailKitBundle\Tracking\TrackingIds;
use Nowo\MailKitBundle\Tracking\TrackingTokenSigner;

/**
 * REQ-SEC-006 (1.11.0): the tracking identifiers never fold a trailing space.
 *
 * Two independent guarantees, both asserted here because either one alone decays silently:
 *
 * 1. {@see TrackingIds::check()} refuses `trim($value) !== $value` on the write path
 *    ({@see TrackingDecision::track()}) **and** on both read paths ({@see TrackingStore::eraseRecipient()},
 *    {@see TrackingStore::exportRecipient()}). A refactor that drops the read-side validation fails here.
 * 2. The column collation itself separates the values, for every path that does not go through that check — a host
 *    import, a data migration or hand-written SQL. Only then is the GDPR export / erasure boundary a property of the
 *    database rather than of one PHP guard.
 *
 * The collation half runs on MySQL 8.4 / MariaDB 11.4 in CI (MAIL_KIT_TEST_DATABASE_URL) and is skipped on SQLite and
 * PostgreSQL, which compare exactly already.
 */
final class ExactCollationTest extends KernelTestCase
{
    use TrackingKernelTrait;

    public function testTrailingSpaceIdentifiersAreRefusedOnWriteAndOnRead(): void
    {
        $store = $this->trackingStoreOf($this->boot());
        $store->createSend(TrackingTokenSigner::newSendId(), 'alert', 'UserA', 'acme');

        // Write path: the host's policy cannot build a decision with a padded recipient id or tenant.
        foreach ([['UserA ', 'acme'], [' UserA', 'acme'], ['UserA', 'acme '], ['UserA', " acme\t"]] as [$recipient, $tenant]) {
            try {
                TrackingDecision::track($recipient, $tenant);
                self::fail('Padded identifier accepted on write: ' . $recipient . ' / ' . $tenant);
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        // Read paths: both re-validate, so a padded id can never select another person's rows.
        foreach (['UserA ', ' UserA', "UserA\n"] as $padded) {
            try {
                $store->exportRecipient($padded);
                self::fail('Padded recipient id accepted by exportRecipient(): ' . $padded);
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }

            try {
                $store->eraseRecipient($padded);
                self::fail('Padded recipient id accepted by eraseRecipient(): ' . $padded);
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        self::assertCount(1, $store->exportRecipient('UserA'), 'The exact id still matches.');
    }

    public function testTheColumnCollationSeparatesTrailingSpacesWithoutThePhpGuard(): void
    {
        $kernel     = $this->boot();
        $store      = $this->trackingStoreOf($kernel);
        $connection = $this->connection($kernel);
        if (!$connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('PostgreSQL and SQLite compare exactly by default.');
        }
        $collation = ExactCollation::forConnection($connection);
        self::assertNotNull($collation);
        self::assertNotSame(ExactCollation::MYSQL_PAD_FALLBACK, $collation, 'CI runs MySQL 8.4 and MariaDB 11.4.');

        // The PAD SPACE collation the table used before 1.11.0 would have matched; the new one must not.
        self::assertSame(1, (int) $connection->fetchOne("SELECT 'UserA' = _utf8mb4'UserA ' COLLATE utf8mb4_bin"), 'utf8mb4_bin is PAD SPACE');
        self::assertSame(0, (int) $connection->fetchOne("SELECT 'UserA' = _utf8mb4'UserA ' COLLATE " . $collation), $collation . ' is NO PAD');

        // A row written around TrackingIds (host import, data migration, hand-written SQL).
        $sendId = TrackingTokenSigner::newSendId();
        $connection->insert($store->table(), ['send_id' => $sendId, 'recipient_id' => 'UserA', 'tenant' => 'acme', 'sent_at' => 1, 'open_count' => 0, 'machine_open_count' => 0]);
        $table = $store->table();
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . $table . ' WHERE recipient_id = ?', ['UserA']));
        foreach (['UserA ', 'usera', 'UserA  '] as $lookAlike) {
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . $table . ' WHERE recipient_id = ?', [$lookAlike]), 'recipient: ' . $lookAlike);
        }
        foreach (['acme ', 'ACME', 'acmé'] as $lookAlike) {
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . $table . ' WHERE tenant = ?', [$lookAlike]), 'tenant: ' . $lookAlike);
        }
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . $table . ' WHERE send_id = ?', [$sendId]));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . $table . ' WHERE send_id = ?', [$sendId . ' ']));
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
