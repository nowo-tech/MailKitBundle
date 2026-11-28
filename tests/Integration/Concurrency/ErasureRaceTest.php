<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Concurrency;

use Closure;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\MailKitBundle\Concurrency\StorageConflicts;
use Nowo\MailKitBundle\Log\SentMailPersonalDataSubject;
use Nowo\MailKitBundle\Log\SentMailRecorder;
use Nowo\MailKitBundle\Tests\Support\StatementHookMiddleware;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;
use Throwable;

use function getenv;
use function is_string;
use function json_decode;
use function json_encode;
use function str_contains;
use function str_starts_with;

use const JSON_THROW_ON_ERROR;

/**
 * Audit ZDL-MK-L1 (2.9.0): two GDPR erasures of people who share one sent-mail log row, on two real connections to the
 * same MySQL 8.4 / MariaDB 11 / PostgreSQL database (MAIL_KIT_TEST_DATABASE_URL; skipped on SQLite, which has no row
 * locks). Connection A erases one person at a chosen point of connection B's erasure of the other (a hook on B's
 * statements). Before 2.9.0 B wrote back the values it had read without a lock and restored the address A had just
 * erased. Lock waits are capped at one second.
 */
#[Group('database')]
final class ErasureRaceTest extends TestCase
{
    private const PREFIX = 'race_';

    private Connection $a;

    private Connection $b;

    /** @var (Closure(string): void)|null */
    private ?Closure $hook = null;

    protected function setUp(): void
    {
        $url = getenv('MAIL_KIT_TEST_DATABASE_URL');
        if (!is_string($url) || $url === '' || str_starts_with($url, 'sqlite')) {
            self::markTestSkipped('Needs MAIL_KIT_TEST_DATABASE_URL on MySQL, MariaDB or PostgreSQL.');
        }
        $params  = (new DsnParser(['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql', 'pgsql' => 'pdo_pgsql']))->parse($url);
        $this->a = DriverManager::getConnection($params);
        $this->b = DriverManager::getConnection($params, (new Configuration())->setMiddlewares([new StatementHookMiddleware(function (string $sql): void {
            if ($this->hook instanceof Closure) {
                ($this->hook)($sql);
            }
        })]));
        foreach ([$this->a, $this->b] as $connection) {
            $connection->executeStatement($this->isPostgres() ? "SET lock_timeout = '1s'" : 'SET SESSION innodb_lock_wait_timeout = 1');
        }
        foreach (['mail_kit_sent_mail_body', 'mail_kit_sent_mail'] as $table) {
            $this->a->executeStatement('DROP TABLE IF EXISTS ' . self::PREFIX . $table);
        }
        $schema = new Schema();
        $main   = $schema->createTable(self::PREFIX . 'mail_kit_sent_mail');
        $main->addColumn('id', Types::STRING, ['length' => 36]);
        $main->addColumn('status', Types::STRING, ['length' => 16]);
        $main->addColumn('tag', Types::STRING, ['length' => 64, 'notnull' => false]);
        $main->addColumn('subject', Types::STRING, ['length' => 255]);
        $main->addColumn('sender', Types::STRING, ['length' => 255]);
        $main->addColumn('recipients', Types::JSON);
        $main->addColumn('recipient_index', Types::TEXT);
        $main->addColumn('transport', Types::STRING, ['length' => 32]);
        $main->addColumn('provider_message_id', Types::STRING, ['length' => 255, 'notnull' => false]);
        $main->addColumn('size', Types::INTEGER, ['notnull' => false]);
        $main->addColumn('error', Types::TEXT, ['notnull' => false]);
        $main->addColumn('attempts', Types::INTEGER, ['default' => 0]);
        $main->addColumn('queued_at', Types::DATETIME_IMMUTABLE);
        $main->addColumn('sent_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $main->addColumn('failed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $main->setPrimaryKey(['id']);
        $body = $schema->createTable(self::PREFIX . 'mail_kit_sent_mail_body');
        $body->addColumn('id', Types::STRING, ['length' => 36]);
        $body->addColumn('html_body', Types::TEXT, ['notnull' => false]);
        $body->addColumn('text_body', Types::TEXT, ['notnull' => false]);
        $body->setPrimaryKey(['id']);
        foreach ($schema->toSql($this->a->getDatabasePlatform()) as $sql) {
            $this->a->executeStatement($sql);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->a)) {
            $this->hook = null;
            foreach (['mail_kit_sent_mail_body', 'mail_kit_sent_mail'] as $table) {
                $this->a->executeStatement('DROP TABLE IF EXISTS ' . self::PREFIX . $table);
            }
            $this->a->close();
            $this->b->close();
        }
    }

    public function testAnErasureCommittedBeforeTheLockedReadIsNotUndone(): void
    {
        $id = $this->row(['ana@example.com', 'bob@example.com'], 'Team', null, 'Hi ana@example.com and bob@example.com');

        // B has pre-filtered the shared row (it still lists both people); A erases Ana and commits before B's locked
        // read. B must see A's version: Bob was the last recipient, so the row goes.
        $erasedByA  = null;
        $this->hook = function (string $sql) use (&$erasedByA): void {
            if (str_contains($sql, 'FOR UPDATE')) {
                $this->hook = null;
                $erasedByA  = $this->recorder($this->a)->forgetAddress('ana@example.com');
            }
        };
        $erasedByB = $this->recorder($this->b)->forgetAddress('bob@example.com');

        self::assertSame(['deleted' => 0, 'unlinked' => 1, 'redacted' => 1], $erasedByA);
        self::assertSame(['deleted' => 1, 'unlinked' => 0, 'redacted' => 0], $erasedByB);
        self::assertFalse($this->a->fetchOne('SELECT id FROM ' . self::PREFIX . 'mail_kit_sent_mail WHERE id = ?', [$id]));
        self::assertSame(0, (int) $this->a->fetchOne('SELECT COUNT(*) FROM ' . self::PREFIX . 'mail_kit_sent_mail_body'));
        $this->assertUsable($this->b);
    }

    public function testAnErasureThatMeetsTheLockedRowWaitsAndReplays(): void
    {
        $id = $this->row(['ana@example.com', 'bob@example.com', 'cy@example.com'], 'Team', null, 'Hi ana@example.com and bob@example.com');

        // B holds the shared row (locked read done, about to write it back): A's erasure waits on the lock, times out
        // and rolls back with nothing committed (CONFLICT_001, a retry is safe). B finishes without A's change lost.
        $failure    = null;
        $this->hook = function (string $sql) use (&$failure): void {
            if (str_starts_with($sql, 'UPDATE ' . self::PREFIX . 'mail_kit_sent_mail ')) {
                $this->hook = null;
                try {
                    $this->recorder($this->a)->forgetAddress('ana@example.com');
                } catch (Throwable $exception) {
                    $failure = $exception;
                }
            }
        };
        self::assertSame(['deleted' => 0, 'unlinked' => 1, 'redacted' => 1], $this->recorder($this->b)->forgetAddress('bob@example.com'));

        self::assertInstanceOf(TransientStorageFailure::class, $failure);
        self::assertTrue(StorageConflicts::isConflict($failure));
        self::assertSame(ConflictCode::RetrySafe, StorageConflicts::code(new Request(), $failure));
        self::assertSame(0, $this->a->getTransactionNestingLevel());
        $this->assertUsable($this->a);
        self::assertSame(['to' => ['ana@example.com', 'cy@example.com']], $this->recipients($id));

        // A's replay sees B's committed row and removes Ana from it: only Cy is left.
        self::assertSame(['deleted' => 0, 'unlinked' => 1, 'redacted' => 1], $this->recorder($this->a)->forgetAddress('ana@example.com'));
        self::assertSame(['to' => ['cy@example.com']], $this->recipients($id));
        self::assertSame(['Hi [redacted] and [redacted]'], $this->a->fetchFirstColumn('SELECT text_body FROM ' . self::PREFIX . 'mail_kit_sent_mail_body'));
    }

    public function testMaskingRereadsSubjectsErrorsAndBodiesUnderTheLock(): void
    {
        $id = $this->row(['ops@example.com'], 'About ana@example.com and bob@example.com', '550 <ana@example.com> <bob@example.com>', 'Ana: ana@example.com, Bob: bob@example.com');

        // Neither person received the row; both are masked in it. A masks Ana between B's pre-filter (which read the old
        // texts) and B's locked read: B masks Bob in A's version, so Ana's address does not come back.
        $this->hook = function (string $sql): void {
            if (str_contains($sql, 'FOR UPDATE')) {
                $this->hook = null;
                self::assertSame(['deleted' => 0, 'unlinked' => 0, 'redacted' => 2], $this->recorder($this->a)->eraseTagged(SentMailPersonalDataSubject::fromEmail('ana@example.com'), 'crm:aaa')->toArray());
            }
        };
        self::assertSame(['deleted' => 0, 'unlinked' => 0, 'redacted' => 2], $this->recorder($this->b)->eraseTagged(SentMailPersonalDataSubject::fromEmail('bob@example.com'), 'crm:aaa')->toArray());

        $row = $this->a->fetchAssociative('SELECT m.subject, m.error, b.text_body FROM ' . self::PREFIX . 'mail_kit_sent_mail m JOIN ' . self::PREFIX . 'mail_kit_sent_mail_body b ON b.id = m.id WHERE m.id = ?', [$id]);
        self::assertSame(['subject' => 'About [redacted] and [redacted]', 'error' => '550 <[redacted]> <[redacted]>', 'text_body' => 'Ana: [redacted], Bob: [redacted]'], $row);
        self::assertSame(['to' => ['ops@example.com']], $this->recipients($id));
        $this->assertUsable($this->b);
    }

    public function testAnErasureInsideTheCallersTransactionWaitsOnTheLock(): void
    {
        $id = $this->row(['ana@example.com', 'bob@example.com'], 'Team', null, null);

        // A holds the row in an open transaction: B's erasure inside B's own (host) transaction meets the lock; the
        // database error reaches B's code, which rolls back. After A commits, the replay works on A's version.
        $this->a->beginTransaction();
        $this->a->executeStatement('UPDATE ' . self::PREFIX . 'mail_kit_sent_mail SET subject = ? WHERE id = ?', ['Team (edited)', $id]);
        $this->b->beginTransaction();
        try {
            $this->recorder($this->b)->forgetAddress('bob@example.com');
            self::fail('The erasure was expected to meet the lock held by connection A.');
        } catch (Throwable $failure) {
            self::assertTrue(StorageConflicts::isConflict($failure), $failure::class);
        }
        $this->b->rollBack();
        $this->a->commit();

        self::assertSame(['deleted' => 0, 'unlinked' => 1, 'redacted' => 0], $this->recorder($this->b)->forgetAddress('bob@example.com'));
        self::assertSame(['to' => ['ana@example.com']], $this->recipients($id));
        self::assertSame(['Team (edited)'], $this->a->fetchFirstColumn('SELECT subject FROM ' . self::PREFIX . 'mail_kit_sent_mail'));
    }

    /**
     * @param list<string> $to
     */
    private function row(array $to, string $subject, ?string $error, ?string $text): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->a->insert(self::PREFIX . 'mail_kit_sent_mail', [
            'id'              => $id,
            'status'          => 'sent',
            'tag'             => 'crm:aaa',
            'subject'         => $subject,
            'sender'          => 'app@example.com',
            'recipients'      => json_encode(['to' => $to], JSON_THROW_ON_ERROR),
            'recipient_index' => SentMailRecorder::recipientIndex(['to' => $to]),
            'transport'       => 'smtp',
            'error'           => $error,
            'attempts'        => 1,
            'queued_at'       => '2026-11-28 10:00:00',
        ]);
        $this->a->insert(self::PREFIX . 'mail_kit_sent_mail_body', ['id' => $id, 'html_body' => null, 'text_body' => $text]);

        return $id;
    }

    /**
     * @return array<mixed>
     */
    private function recipients(string $id): array
    {
        $decoded = json_decode((string) $this->a->fetchOne('SELECT recipients FROM ' . self::PREFIX . 'mail_kit_sent_mail WHERE id = ?', [$id]), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function recorder(Connection $connection): SentMailRecorder
    {
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);

        return new SentMailRecorder($registry, null, self::PREFIX, true);
    }

    private function isPostgres(): bool
    {
        return $this->a->getDatabasePlatform() instanceof PostgreSQLPlatform;
    }

    private function assertUsable(Connection $connection): void
    {
        self::assertSame(1, (int) $connection->fetchOne('SELECT 1'));
    }
}
