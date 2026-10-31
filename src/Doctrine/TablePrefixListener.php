<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Doctrine;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Nowo\MailKitBundle\Entity\SentMail;
use Nowo\MailKitBundle\Entity\SentMailBody;

use function array_merge;
use function is_array;
use function is_string;
use function str_starts_with;

/**
 * Applies `doctrine.table_prefix` to the bundle tables and index names (REQ-ORM-002). Only the bundle entities
 * (namespace `Nowo\MailKitBundle\Entity\`) are touched; an empty prefix is a no-op.
 */
final readonly class TablePrefixListener
{
    private const ENTITY_NAMESPACE = 'Nowo\\MailKitBundle\\Entity\\';

    /**
     * @param string $prefix table prefix (e.g. `app_`); empty = unprefixed names
     */
    public function __construct(
        private string $prefix = '',
    ) {
    }

    /**
     * Doctrine `loadClassMetadata` listener.
     */
    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();
        if ($this->prefix === '' || !str_starts_with($metadata->getName(), self::ENTITY_NAMESPACE)) {
            return;
        }

        $table         = $metadata->table;
        $table['name'] = $this->prefix . $metadata->getTableName();

        foreach (['uniqueConstraints', 'indexes'] as $kind) {
            $definitions = $table[$kind] ?? null;
            if (!is_array($definitions)) {
                continue;
            }
            $renamed = [];
            foreach ($definitions as $name => $definition) {
                $renamed[is_string($name) ? $this->prefix . $name : $name] = $definition;
            }
            $table[$kind] = $renamed;
        }

        $metadata->setPrimaryTable(array_merge($metadata->table, $table));
    }

    /**
     * Effective table names (for docs and schema tooling).
     *
     * @return array{log: string, body: string}
     */
    public function tableNames(): array
    {
        return ['log' => $this->prefix . SentMail::TABLE, 'body' => $this->prefix . SentMailBody::TABLE];
    }
}
