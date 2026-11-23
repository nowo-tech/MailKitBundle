<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Collation;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\MailKitBundle\Doctrine\MailKitExactKeyColumns;
use Nowo\MailKitBundle\Graph\Inbound\Store\GraphInboundStore;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Store\MailboxStore;
use Nowo\MailKitBundle\Tracking\Store\TrackingStore;
use Nowo\MigrationsKitBundle\Test\ExactKeyColumnsTestCase;

/**
 * REQ-SEC-006 on a real server (MAIL_KIT_TEST_DATABASE_URL: MySQL 8.4 and MariaDB 11.4 in CI): the bundle's own gate
 * over its own declaration.
 *
 * The tables are created by the stores themselves, the way a host creates them, under a non-empty table prefix;
 * the declared columns are then read back from `information_schema`. A key column that lost its binary NO PAD
 * collation — or a new one nobody collated — fails *this bundle's* pipeline, long before any host installs it, and
 * the prefix proves the declaration follows the configured table name instead of a literal.
 *
 * Skips on PostgreSQL and SQLite, which compare exactly by default.
 */
final class ExactKeyColumnsGateTest extends ExactKeyColumnsTestCase
{
    /** A non-empty prefix: the declaration must follow the configured table name. */
    private const PREFIX = 'exactkey_';

    /**
     * {@inheritdoc}
     */
    protected function databaseUrlVariable(): string
    {
        return 'MAIL_KIT_TEST_DATABASE_URL';
    }

    /**
     * {@inheritdoc}
     */
    protected function exactKeyColumnProviders(): array
    {
        return [new MailKitExactKeyColumns(self::PREFIX, true, true, true)];
    }

    /**
     * {@inheritdoc}
     */
    protected function createSchema(Connection $connection): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getConnection')->willReturn($connection);

        $tracking = new TrackingStore($registry, null, self::PREFIX);
        $inbound  = new GraphInboundStore($registry, null, self::PREFIX);
        $mailbox  = new MailboxStore($registry, null, self::PREFIX);

        $manager = $connection->createSchemaManager();
        foreach ([$tracking->table(), $inbound->subscriptionTable(), $inbound->deltaTable(), $inbound->inboundTable(), $mailbox->subscriptionTable(), $mailbox->cursorTable(), $mailbox->mailboxTable()] as $table) {
            if ($manager->tablesExist([$table])) {
                $connection->executeStatement('DROP TABLE ' . $table);
            }
        }

        $tracking->execute($tracking->missingTablesSql());
        $inbound->execute($inbound->missingTablesSql());
        $mailbox->execute($mailbox->missingTablesSql());
    }
}
