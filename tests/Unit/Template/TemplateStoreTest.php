<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Template;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\MailKitBundle\Template\Exception\TemplateKeyTaken;
use Nowo\MailKitBundle\Template\Infrastructure\Command\TemplateSchemaCommand;
use Nowo\MailKitBundle\Template\Infrastructure\Store\TemplateSchemaListener;
use Nowo\MailKitBundle\Template\Value\TemplateActor;
use Nowo\MailKitBundle\Template\Value\TemplateKind;
use Nowo\MailKitBundle\Template\Value\TemplatePermission;
use Nowo\MailKitBundle\Template\Value\TemplateScope;
use Nowo\MailKitBundle\Template\Value\TemplateSummary;
use Nowo\MailKitBundle\Tests\Support\TemplateHarness;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

use function array_map;
use function implode;

/**
 * 2.7.0 template store: schema (prefix, unique key, MEDIUMTEXT / exact collation on MySQL / MariaDB), the schema
 * command and ORM listener, savepoints that keep a host transaction usable after a duplicate key, odd stored values.
 */
final class TemplateStoreTest extends TestCase
{
    public function testSchemaCommandAndPrefix(): void
    {
        $h      = new TemplateHarness($this, prefix: 'app_');
        $schema = new CommandTester(new TemplateSchemaCommand($h->store));
        $schema->execute([]);
        self::assertSame("The template table exists.\n", $schema->getDisplay());
        self::assertSame('app_mail_kit_template', $h->store->table());
        $h->store->execute(['DROP TABLE app_mail_kit_template']);
        $schema->execute([]);
        self::assertStringContainsString('CREATE TABLE app_mail_kit_template', $schema->getDisplay());
        self::assertStringContainsString('app_uniq_mail_kit_template_key', $schema->getDisplay());
        $schema->execute(['--force' => true]);
        self::assertSame("Created the template table.\n", $schema->getDisplay());
        self::assertSame([], $h->store->missingTablesSql());
    }

    public function testTheMigrationSqlOfEachPlatform(): void
    {
        $h       = new TemplateHarness($this);
        $mariadb = new Schema();
        $h->store->defineSchema($mariadb, new MariaDBPlatform());
        $h->store->defineSchema($mariadb, new MariaDBPlatform());
        $sql = implode("\n", $mariadb->toSql(new MariaDBPlatform()));
        self::assertStringContainsString('body_html MEDIUMTEXT', $sql);
        self::assertStringContainsString('tenant VARCHAR(128) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_nopad_bin`', $sql);
        self::assertStringContainsString('UNIQUE INDEX uniq_mail_kit_template_key (tenant, kind, scope, owner_id, template_key, locale)', $sql);

        $postgres = new Schema();
        $h->store->defineSchema($postgres, new PostgreSQLPlatform());
        $sql = implode("\n", $postgres->toSql(new PostgreSQLPlatform()));
        self::assertStringContainsString('body_html TEXT NOT NULL', $sql);
        self::assertStringNotContainsString('COLLATE', $sql);
    }

    public function testSchemaListenerOnlyForItsEntityManager(): void
    {
        $h    = new TemplateHarness($this);
        $mine = $this->createStub(EntityManagerInterface::class);
        $mine->method('getConnection')->willReturn($h->connection);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($mine);
        $listener = new TemplateSchemaListener($h->store, $registry);

        $schema = new Schema();
        $listener->postGenerateSchema(new GenerateSchemaEventArgs($this->createStub(EntityManagerInterface::class), $schema));
        self::assertFalse($schema->hasTable('mail_kit_template'));
        $listener->postGenerateSchema(new GenerateSchemaEventArgs($mine, $schema));
        self::assertTrue($schema->hasTable('mail_kit_template'));
    }

    public function testADuplicateKeyKeepsTheHostTransactionUsable(): void
    {
        $h   = new TemplateHarness($this);
        $ana = new TemplateActor('t1', 'ana');
        $h->permission->grant('*', TemplatePermission::Personal);
        $h->connection->beginTransaction();
        $first = $h->library->create($ana, TemplateLibraryTest::draft());
        try {
            $h->library->create($ana, TemplateLibraryTest::draft());
            self::fail('taken');
        } catch (TemplateKeyTaken) {
        }
        $other = $h->library->create($ana, TemplateLibraryTest::draft(key: 'other'));
        try {
            $h->library->update($ana, $other->id, 1, TemplateLibraryTest::draft());
            self::fail('taken');
        } catch (TemplateKeyTaken) {
        }
        $h->library->update($ana, $first->id, 1, TemplateLibraryTest::draft(key: 'renamed'));
        $h->connection->commit();
        self::assertSame(['other', 'renamed'], array_map(static fn (TemplateSummary $s): string => $s->key, $h->library->list($ana, TemplateKind::Template)));
    }

    public function testOddStoredValuesAreReadSafely(): void
    {
        $h = new TemplateHarness($this);
        $h->connection->insert('mail_kit_template', [
            'id'           => '00000000-0000-7000-8000-000000000001', 'tenant' => 't1', 'kind' => 'snippet', 'scope' => 'shared', 'owner_id' => '',
            'template_key' => '123', 'locale' => 'es', 'name' => 'n', 'subject' => '', 'body_html' => '', 'body_text' => 't',
            'version'      => 1, 'created_by' => null, 'updated_by' => null, 'created_at' => 0, 'updated_at' => 0,
        ]);
        $row = $h->store->find('t1', '00000000-0000-7000-8000-000000000001');
        self::assertNotNull($row);
        self::assertSame('123', $row->key);
        self::assertNull($row->owner);
        self::assertNull($row->createdBy);
        self::assertSame(1, $h->store->count('t1', TemplateKind::Snippet, TemplateScope::Shared, ''));
        self::assertSame([], $h->store->summaries('t1', TemplateKind::Snippet, null, false, null, 10));
        self::assertNull($h->store->find('t2', '00000000-0000-7000-8000-000000000001'));
    }
}
