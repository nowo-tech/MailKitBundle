<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Log;

use RuntimeException;

use function implode;
use function sprintf;

/**
 * A sent-mail log table is missing while the log entities are mapped (1.6, audit LL-MK-L3): a wrong
 * `doctrine.table_prefix`, entity manager or connection, or a schema that was never migrated. Erasure and retention
 * never report success then. When only the body table is missing, the rows of the main table were already erased and
 * {@see self::$deleted} / {@see self::$redacted} say how many.
 */
final class SentMailLogTableMissingException extends RuntimeException
{
    /**
     * @param list<string> $tables the missing tables
     * @param int $deleted main-table rows deleted before the failure
     * @param int $redacted rows redacted before the failure
     */
    public function __construct(
        public readonly array $tables,
        public readonly int $deleted = 0,
        public readonly int $redacted = 0,
    ) {
        parent::__construct(sprintf(
            'NowoMailKitBundle: the sent-mail log table(s) "%s" do not exist on the configured connection (%d row(s) deleted, %d redacted before stopping). Check nowo_mail_kit.doctrine.table_prefix / entity_manager and run your migrations; personal data may remain until this is fixed.',
            implode('", "', $tables),
            $deleted,
            $redacted,
        ));
    }
}
