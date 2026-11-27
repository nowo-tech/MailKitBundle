<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Template\Infrastructure\Store;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\MailKitBundle\Concurrency\SavepointWrites;
use Nowo\MailKitBundle\Doctrine\ExactCollation;
use Nowo\MailKitBundle\Template\Exception\TemplateKeyTaken;
use Nowo\MailKitBundle\Template\Value\StoredTemplate;
use Nowo\MailKitBundle\Template\Value\TemplateKind;
use Nowo\MailKitBundle\Template\Value\TemplateScope;
use Nowo\MailKitBundle\Template\Value\TemplateSummary;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

use function array_values;
use function assert;
use function implode;
use function is_numeric;
use function is_string;

/**
 * Stored e-mail templates and snippets (2.7.0), through DBAL on the configured connection (resolved per call; never
 * the host's entity manager, never a flush). One table, `<prefix>mail_kit_template`:
 *
 * - `id` (UUID v7), `tenant`, `kind`, `scope`, `owner_id` (the owner of a personal row, `''` for a shared one, so the
 *   unique key also works where NULLs never collide), `template_key`, `locale`, `name`, `subject`, `body_html`
 *   (sanitised), `body_text`, `version` (optimistic), `created_by` / `updated_by` (authors, cleared by a GDPR
 *   erasure), `created_at` / `updated_at` (UNIX seconds);
 * - unique (`tenant`, `kind`, `scope`, `owner_id`, `template_key`, `locale`).
 *
 * Every read and write names the tenant (tenant isolation is in SQL, not only in PHP). Writes are single conditional
 * statements (REQ-RUNTIME-003): an insert with a caught duplicate key, updates and deletes guarded by the tenant, the
 * scope, the owner and the expected version. Inside an open host transaction each statement runs in a savepoint
 * ({@see SavepointWrites}); the GDPR erasure (several statements) runs through transaction-doctrine. Stateless.
 *
 * @internal
 */
final class TemplateStore
{
    /** Unprefixed table name. */
    public const TABLE = 'mail_kit_template';

    /** Column list of a full row. */
    private const COLUMNS = 'id, tenant, kind, scope, owner_id, template_key, locale, name, subject, body_html, body_text, version, created_by, updated_by, created_at, updated_at';

    /** Column list of a summary. */
    private const SUMMARY_COLUMNS = 'id, kind, scope, owner_id, template_key, locale, name, subject, version, updated_by, updated_at';

    /** Column length of the bodies: MEDIUMTEXT on MySQL / MariaDB (the limits are checked before). */
    private const BODY_LENGTH = 16_777_215;

    private const SAVEPOINT = 'nowo_mail_kit_template';

    /**
     * @param string|null $connectionName DBAL connection (null = default)
     * @param string $tablePrefix `doctrine.table_prefix`
     */
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly ?string $connectionName = null,
        private readonly string $tablePrefix = '',
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Prefixed table name.
     */
    public function table(): string
    {
        return $this->tablePrefix . self::TABLE;
    }

    /**
     * Inserts a row; false when its unique key exists (caught duplicate key, never check-then-insert).
     *
     * @param array{id: string, tenant: string, kind: string, scope: string, owner_id: string, template_key: string, locale: string, name: string, subject: string, body_html: string, body_text: string, created_by: string, created_at: int} $row
     */
    public function insert(#[SensitiveParameter] array $row): bool
    {
        return SavepointWrites::insertOnce($this->connection(), self::SAVEPOINT, $this->table(), $row + [
            'version'    => 1,
            'updated_by' => $row['created_by'],
            'updated_at' => $row['created_at'],
        ]);
    }

    /**
     * The row of that id in that tenant, or null.
     */
    public function find(string $tenant, string $id): ?StoredTemplate
    {
        $row = $this->connection()->fetchAssociative('SELECT ' . self::COLUMNS . ' FROM ' . $this->table() . ' WHERE id = ? AND tenant = ?', [$id, $tenant]);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * The row of that key and locale, or null.
     *
     * @param string $ownerKey the owner of a personal row, `''` for a shared one
     */
    public function findByKey(string $tenant, TemplateKind $kind, TemplateScope $scope, string $ownerKey, string $key, string $locale): ?StoredTemplate
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT ' . self::COLUMNS . ' FROM ' . $this->table() . ' WHERE tenant = ? AND kind = ? AND scope = ? AND owner_id = ? AND template_key = ? AND locale = ?',
            [$tenant, $kind->value, $scope->value, $ownerKey, $key, $locale],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Summaries of one tenant and kind: the personal rows of `$owner` (when not null) and the shared rows (when
     * `$shared`), ordered by scope, key and locale, at most `$limit`.
     *
     * @return list<TemplateSummary>
     */
    public function summaries(string $tenant, TemplateKind $kind, ?string $owner, bool $shared, ?string $locale, int $limit): array
    {
        $scopes = [];
        $params = [$tenant, $kind->value];
        if ($owner !== null) {
            $scopes[] = '(scope = ? AND owner_id = ?)';
            $params[] = TemplateScope::Personal->value;
            $params[] = $owner;
        }
        if ($shared) {
            $scopes[] = '(scope = ? AND owner_id = ?)';
            $params[] = TemplateScope::Shared->value;
            $params[] = '';
        }
        if ($scopes === []) {
            return [];
        }
        $sql = 'SELECT ' . self::SUMMARY_COLUMNS . ' FROM ' . $this->table() . ' WHERE tenant = ? AND kind = ? AND (' . implode(' OR ', $scopes) . ')';
        if ($locale !== null) {
            $sql .= ' AND locale = ?';
            $params[] = $locale;
        }
        $connection = $this->connection();
        $rows       = $connection->fetchAllAssociative($connection->getDatabasePlatform()->modifyLimitQuery($sql . ' ORDER BY scope, template_key, locale, id', $limit), $params);
        $summaries  = [];
        foreach ($rows as $row) {
            $summaries[] = new TemplateSummary(
                $this->string($row, 'id'),
                TemplateKind::from($this->string($row, 'kind')),
                TemplateScope::from($this->string($row, 'scope')),
                $this->nullable($this->string($row, 'owner_id')),
                $this->string($row, 'template_key'),
                $this->string($row, 'name'),
                $this->string($row, 'locale'),
                $this->string($row, 'subject'),
                $this->int($row, 'version'),
                $this->nullable($this->string($row, 'updated_by')),
                $this->time($row, 'updated_at'),
            );
        }

        return $summaries;
    }

    /**
     * Rows of one tenant, kind, scope and owner key (the per-scope cap).
     */
    public function count(string $tenant, TemplateKind $kind, TemplateScope $scope, string $ownerKey): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM ' . $this->table() . ' WHERE tenant = ? AND kind = ? AND scope = ? AND owner_id = ?', [$tenant, $kind->value, $scope->value, $ownerKey]);

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Replaces the editable fields of a row when it still has `$version` (compare-and-set; the version always moves,
     * so MySQL's changed-rows count equals the matched rows). Returns the affected rows (0 or 1).
     *
     * @param array{template_key: string, locale: string, name: string, subject: string, body_html: string, body_text: string, updated_by: string, updated_at: int} $fields
     *
     * @throws TemplateKeyTaken when another row has the new key and locale
     */
    public function update(string $tenant, string $id, TemplateScope $scope, string $ownerKey, int $version, #[SensitiveParameter] array $fields): int
    {
        try {
            return SavepointWrites::write(
                $this->connection(),
                self::SAVEPOINT,
                'UPDATE ' . $this->table() . ' SET template_key = ?, locale = ?, name = ?, subject = ?, body_html = ?, body_text = ?, updated_by = ?, updated_at = ?, version = version + 1 WHERE id = ? AND tenant = ? AND scope = ? AND owner_id = ? AND version = ?',
                [$fields['template_key'], $fields['locale'], $fields['name'], $fields['subject'], $fields['body_html'], $fields['body_text'], $fields['updated_by'], $fields['updated_at'], $id, $tenant, $scope->value, $ownerKey, $version],
            );
        } catch (UniqueConstraintViolationException) {
            throw new TemplateKeyTaken();
        }
    }

    /**
     * Deletes a row when it still has `$version`; returns the affected rows (0 or 1).
     */
    public function delete(string $tenant, string $id, TemplateScope $scope, string $ownerKey, int $version): int
    {
        return SavepointWrites::write(
            $this->connection(),
            self::SAVEPOINT,
            'DELETE FROM ' . $this->table() . ' WHERE id = ? AND tenant = ? AND scope = ? AND owner_id = ? AND version = ?',
            [$id, $tenant, $scope->value, $ownerKey, $version],
        );
    }

    /**
     * The personal rows of an owner (every tenant), exact match re-checked in PHP.
     *
     * @return list<StoredTemplate>
     */
    public function personalRows(string $owner): array
    {
        $rows = $this->connection()->fetchAllAssociative('SELECT ' . self::COLUMNS . ' FROM ' . $this->table() . ' WHERE scope = ? AND owner_id = ? ORDER BY tenant, kind, template_key, locale, id', [TemplateScope::Personal->value, $owner]);
        $out  = [];
        foreach ($rows as $row) {
            $template = $this->hydrate($row);
            if ($template->owner === $owner) {
                $out[] = $template;
            }
        }

        return $out;
    }

    /**
     * The shared rows an owner created or last changed (every tenant), exact match re-checked in PHP.
     *
     * @return list<StoredTemplate>
     */
    public function authoredSharedRows(string $owner): array
    {
        $rows = $this->connection()->fetchAllAssociative('SELECT ' . self::COLUMNS . ' FROM ' . $this->table() . ' WHERE scope = ? AND (created_by = ? OR updated_by = ?) ORDER BY tenant, kind, template_key, locale, id', [TemplateScope::Shared->value, $owner, $owner]);
        $out  = [];
        foreach ($rows as $row) {
            $template = $this->hydrate($row);
            if ($template->createdBy === $owner || $template->updatedBy === $owner) {
                $out[] = $template;
            }
        }

        return $out;
    }

    /**
     * GDPR erasure of an owner reference in one transaction: deletes the owner's personal rows and clears the owner
     * as author / last author of shared rows. Returns the deleted rows (for the events) and the anonymised count.
     *
     * @return array{deleted: list<StoredTemplate>, anonymised: int}
     */
    public function eraseOwner(string $owner): array
    {
        $connection = $this->connection();
        $result     = ['deleted' => [], 'anonymised' => 0];
        // @igor-ignore - Closure runs synchronously inside run(); it is not stored on the service.
        DoctrineTransactionRunner::forConnection($connection, null, $this->logger)->run(function () use ($connection, $owner, &$result): void {
            $result = ['deleted' => [], 'anonymised' => 0];
            foreach ($this->personalRows($owner) as $template) {
                if ((int) $connection->executeStatement('DELETE FROM ' . $this->table() . ' WHERE id = ? AND scope = ? AND owner_id = ?', [$template->id, TemplateScope::Personal->value, $owner]) === 1) {
                    $result['deleted'][] = $template;
                }
            }
            foreach ($this->authoredSharedRows($owner) as $template) {
                $changed = 0;
                if ($template->createdBy === $owner) {
                    $changed += (int) $connection->executeStatement('UPDATE ' . $this->table() . ' SET created_by = NULL WHERE id = ? AND created_by = ?', [$template->id, $owner]);
                }
                if ($template->updatedBy === $owner) {
                    $changed += (int) $connection->executeStatement('UPDATE ' . $this->table() . ' SET updated_by = NULL WHERE id = ? AND updated_by = ?', [$template->id, $owner]);
                }
                if ($changed > 0) {
                    ++$result['anonymised'];
                }
            }
        });

        return $result;
    }

    /**
     * Adds the table to a schema (ORM `postGenerateSchema`, migrations, the schema command). Key columns get a binary
     * NO PAD collation on MySQL / MariaDB (REQ-SEC-006).
     *
     * @param AbstractPlatform|Connection|null $source the connection the schema is built for, its platform, or null for
     *                                                 the store's connection
     */
    public function defineSchema(Schema $schema, AbstractPlatform|Connection|null $source = null): void
    {
        if ($schema->hasTable($this->table())) {
            return;
        }
        $exact = ExactCollation::columnOptions($source ?? $this->connection(), $this->logger);
        $table = $schema->createTable($this->table());
        $table->addColumn('id', 'string', ['length' => 36] + $exact);
        $table->addColumn('tenant', 'string', ['length' => 128] + $exact);
        $table->addColumn('kind', 'string', ['length' => 16]);
        $table->addColumn('scope', 'string', ['length' => 16]);
        $table->addColumn('owner_id', 'string', ['length' => 128, 'default' => ''] + $exact);
        $table->addColumn('template_key', 'string', ['length' => 64] + $exact);
        $table->addColumn('locale', 'string', ['length' => 16] + $exact);
        $table->addColumn('name', 'string', ['length' => 190]);
        $table->addColumn('subject', 'string', ['length' => 255, 'default' => '']);
        $table->addColumn('body_html', 'text', ['length' => self::BODY_LENGTH]);
        $table->addColumn('body_text', 'text', ['length' => self::BODY_LENGTH]);
        $table->addColumn('version', 'integer', ['default' => 1]);
        $table->addColumn('created_by', 'string', ['length' => 128, 'notnull' => false] + $exact);
        $table->addColumn('updated_by', 'string', ['length' => 128, 'notnull' => false] + $exact);
        $table->addColumn('created_at', 'bigint');
        $table->addColumn('updated_at', 'bigint');
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['tenant', 'kind', 'scope', 'owner_id', 'template_key', 'locale'], $this->tablePrefix . 'uniq_mail_kit_template_key');
        $table->addIndex(['owner_id'], $this->tablePrefix . 'idx_mail_kit_template_owner');
        $table->addIndex(['created_by'], $this->tablePrefix . 'idx_mail_kit_template_created_by');
        $table->addIndex(['updated_by'], $this->tablePrefix . 'idx_mail_kit_template_updated_by');
    }

    /**
     * CREATE statements when the table is missing on the connection.
     *
     * @return list<string>
     */
    public function missingTablesSql(): array
    {
        $connection = $this->connection();
        if ($connection->createSchemaManager()->tablesExist([$this->table()])) {
            return [];
        }
        $schema = new Schema();
        $this->defineSchema($schema, $connection);

        return array_values($connection->getDatabasePlatform()->getCreateTableSQL($schema->getTable($this->table())));
    }

    /**
     * Runs SQL statements on the store connection (the schema command).
     *
     * @param list<string> $statements
     */
    public function execute(array $statements): void
    {
        $connection = $this->connection();
        foreach ($statements as $statement) {
            $connection->executeStatement($statement);
        }
    }

    /**
     * The DBAL connection the store writes to (resolved per call).
     */
    public function connection(): Connection
    {
        $connection = $this->doctrine->getConnection($this->connectionName);
        assert($connection instanceof Connection);

        return $connection;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): StoredTemplate
    {
        return new StoredTemplate(
            $this->string($row, 'id'),
            TemplateKind::from($this->string($row, 'kind')),
            TemplateScope::from($this->string($row, 'scope')),
            $this->string($row, 'tenant'),
            $this->nullable($this->string($row, 'owner_id')),
            $this->string($row, 'template_key'),
            $this->string($row, 'name'),
            $this->string($row, 'locale'),
            $this->string($row, 'subject'),
            $this->string($row, 'body_html'),
            $this->string($row, 'body_text'),
            $this->int($row, 'version'),
            $this->nullable($this->string($row, 'created_by')),
            $this->nullable($this->string($row, 'updated_by')),
            $this->time($row, 'created_at'),
            $this->time($row, 'updated_at'),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function string(array $row, string $column): string
    {
        $value = $row[$column] ?? null;

        return is_string($value) ? $value : (is_numeric($value) ? (string) $value : '');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function int(array $row, string $column): int
    {
        $value = $row[$column] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function time(array $row, string $column): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . $this->int($row, $column));
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
