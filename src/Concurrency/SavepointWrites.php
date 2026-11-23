<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Concurrency;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Throwable;

/**
 * Single-statement writes of the bundle's DBAL stores that never abort a caller's transaction (1.13 / audit UE2-MK-1,
 * shared by the Graph inbound store and the 2.5.0 mailbox store): inside an open transaction each statement runs in a
 * savepoint rolled back on **any** failure, so a duplicate key, a lock wait timeout, a deadlock or a serialization
 * failure leaves a PostgreSQL transaction usable. Stateless (static methods only).
 *
 * @internal
 */
final class SavepointWrites
{
    /**
     * Inserts one row; false when its key already exists (a concurrent writer stored it first, REQ-RUNTIME-003 rule 4).
     * Failures other than the duplicate key are rethrown after the savepoint rollback.
     *
     * @param array<string, mixed> $row
     */
    public static function insertOnce(Connection $connection, string $savepoint, string $table, array $row): bool
    {
        $nested = $connection->isTransactionActive();
        if ($nested) {
            $connection->createSavepoint($savepoint);
        }

        try {
            $connection->insert($table, $row);
            if ($nested) {
                $connection->releaseSavepoint($savepoint);
            }

            return true;
        } catch (Throwable $failure) {
            if ($nested) {
                try {
                    $connection->rollbackSavepoint($savepoint);
                } catch (Throwable) {
                    // The savepoint is gone (MySQL / MariaDB drop them on a deadlock): the original failure tells.
                }
            }
            if (!$failure instanceof UniqueConstraintViolationException) {
                throw $failure;
            }

            return false;
        }
    }

    /**
     * Runs one write statement and returns the affected rows; the failure is rethrown unchanged after the savepoint
     * rollback.
     *
     * @param list<mixed> $params
     */
    public static function write(Connection $connection, string $savepoint, string $sql, array $params): int
    {
        if (!$connection->isTransactionActive()) {
            return (int) $connection->executeStatement($sql, $params);
        }
        $connection->createSavepoint($savepoint);

        try {
            $count = (int) $connection->executeStatement($sql, $params);
        } catch (Throwable $failure) {
            try {
                $connection->rollbackSavepoint($savepoint);
            } catch (Throwable) {
                // The savepoint is gone (MySQL / MariaDB drop them on a deadlock): the original failure tells.
            }

            throw $failure;
        }
        $connection->releaseSavepoint($savepoint);

        return $count;
    }
}
