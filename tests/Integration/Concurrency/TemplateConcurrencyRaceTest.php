<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Concurrency;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Nowo\MailKitBundle\Concurrency\StorageConflicts;
use Nowo\MailKitBundle\Template\Exception\TemplateKeyTaken;
use Nowo\MailKitBundle\Template\Exception\TemplateVersionConflict;
use Nowo\MailKitBundle\Template\Value\TemplateActor;
use Nowo\MailKitBundle\Template\Value\TemplateDraft;
use Nowo\MailKitBundle\Template\Value\TemplateKind;
use Nowo\MailKitBundle\Template\Value\TemplatePermission;
use Nowo\MailKitBundle\Template\Value\TemplateScope;
use Nowo\MailKitBundle\Tests\Support\TemplateHarness;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Throwable;

use function getenv;
use function is_string;
use function str_starts_with;

/**
 * 2.7.0 REQ-RUNTIME-003 races of the template store: two real connections on the same MySQL 8.4 / MariaDB 11 /
 * PostgreSQL database (MAIL_KIT_TEST_DATABASE_URL; skipped on SQLite). Two people saving the same key: one wins, the
 * other gets TemplateKeyTaken (caught unique key, never check-then-insert); two editors of one version: one wins, the
 * other gets TemplateVersionConflict (no lost update); both also inside open host transactions, which stay usable
 * (savepoints). Lock waits are capped at one second.
 */
#[Group('database')]
final class TemplateConcurrencyRaceTest extends TestCase
{
    private const PREFIX = 'race_';

    private Connection $a;

    private Connection $b;

    private TemplateHarness $ha;

    private TemplateHarness $hb;

    protected function setUp(): void
    {
        $url = getenv('MAIL_KIT_TEST_DATABASE_URL');
        if (!is_string($url) || $url === '' || str_starts_with($url, 'sqlite')) {
            self::markTestSkipped('Needs MAIL_KIT_TEST_DATABASE_URL on MySQL, MariaDB or PostgreSQL.');
        }
        $params  = (new DsnParser(['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql', 'pgsql' => 'pdo_pgsql']))->parse($url);
        $this->a = DriverManager::getConnection($params);
        $this->b = DriverManager::getConnection($params);
        foreach ([$this->a, $this->b] as $connection) {
            $connection->executeStatement($connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? "SET lock_timeout = '1s'" : 'SET SESSION innodb_lock_wait_timeout = 1');
        }
        $this->a->executeStatement('DROP TABLE IF EXISTS ' . self::PREFIX . 'mail_kit_template');
        $this->ha = new TemplateHarness($this, $this->a, self::PREFIX);
        $this->hb = new TemplateHarness($this, $this->b, self::PREFIX);
        foreach ([$this->ha, $this->hb] as $h) {
            $h->permission->grant('*', TemplatePermission::Personal, TemplatePermission::ManageShared);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->a)) {
            $this->a->close();
            $this->b->close();
        }
    }

    public function testTwoSavesOfOneKeyHaveOneWinner(): void
    {
        $draft = $this->draft('proposal');
        $this->ha->library->create(new TemplateActor('t1', 'ana'), $draft);
        try {
            $this->hb->library->create(new TemplateActor('t1', 'bob'), $draft);
            self::fail('one winner');
        } catch (TemplateKeyTaken) {
        }
        self::assertSame(1, (int) $this->a->fetchOne('SELECT COUNT(*) FROM ' . self::PREFIX . 'mail_kit_template'));
    }

    public function testTwoEditorsOfOneVersionHaveOneWinner(): void
    {
        $row = $this->ha->library->create(new TemplateActor('t1', 'ana'), $this->draft('proposal'));
        $this->ha->library->update(new TemplateActor('t1', 'ana'), $row->id, 1, $this->draft('proposal', 'Ana'));
        try {
            $this->hb->library->update(new TemplateActor('t1', 'bob'), $row->id, 1, $this->draft('proposal', 'Bob'));
            self::fail('no lost update');
        } catch (TemplateVersionConflict) {
        }
        self::assertSame('Ana', $this->hb->library->get(new TemplateActor('t1', 'bob'), $row->id)->name);
    }

    public function testInsideHostTransactionsTheLoserKeepsItsTransaction(): void
    {
        $this->a->beginTransaction();
        $this->b->beginTransaction();
        $this->ha->library->create(new TemplateActor('t1', 'ana'), $this->draft('proposal'));
        $this->a->commit();
        try {
            $this->hb->library->create(new TemplateActor('t1', 'bob'), $this->draft('proposal'));
            self::fail('one winner');
        } catch (TemplateKeyTaken) {
        }
        // The duplicate key was rolled back to the savepoint: the host transaction goes on and commits.
        $this->hb->library->create(new TemplateActor('t1', 'bob'), $this->draft('other'));
        $this->b->commit();
        self::assertSame(2, (int) $this->a->fetchOne('SELECT COUNT(*) FROM ' . self::PREFIX . 'mail_kit_template'));
    }

    public function testAnOverlappingSaveGetsATransientConflictAndKeepsItsTransaction(): void
    {
        $this->a->beginTransaction();
        $this->ha->library->create(new TemplateActor('t1', 'ana'), $this->draft('proposal'));
        $this->b->beginTransaction();
        try {
            // Ana's row is not committed: Bob's insert waits on the unique key until the lock timeout.
            $this->hb->library->create(new TemplateActor('t1', 'bob'), $this->draft('proposal'));
            self::fail('lock wait');
        } catch (TemplateKeyTaken) {
            self::fail('a lock wait, not a duplicate, while the other transaction is open');
        } catch (Throwable $wait) {
            self::assertTrue(StorageConflicts::isConflict($wait), $wait::class . ': ' . $wait->getMessage());
        }
        $this->a->commit();
        // The savepoint rollback kept Bob's transaction usable: the retry now sees the committed key.
        try {
            $this->hb->library->create(new TemplateActor('t1', 'bob'), $this->draft('proposal'));
            self::fail('one winner');
        } catch (TemplateKeyTaken) {
        }
        $this->b->commit();
        self::assertSame(1, (int) $this->a->fetchOne('SELECT COUNT(*) FROM ' . self::PREFIX . 'mail_kit_template'));
    }

    private function draft(string $key, string $name = 'Proposal'): TemplateDraft
    {
        return new TemplateDraft(TemplateKind::Template, TemplateScope::Shared, $key, $name, 'en', 'Subject', '<p>Body</p>', '');
    }
}
