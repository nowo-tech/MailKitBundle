<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Concurrency;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use SensitiveParameter;
use Throwable;

/**
 * Single-statement writes of the bundle's DBAL stores that never abort a caller's transaction (1.13 / audit UE2-MK-1,
 * shared by the Graph inbound store and the 2.5.0 mailbox store): inside an open transaction each statement runs in a
 * savepoint rolled back on **any** failure, so a duplicate key, a lock wait timeout, a deadlock or a serialization
 * failure leaves a PostgreSQL transaction usable. When the savepoint itself is lost the connection is closed and the
 * original failure rethrown (lost-savepoint pattern, 2.7.0 audit ZCZ-I1). Rows and parameters are sensitive parameters (a delta link, audit
 * ZAT-R5): they never appear in the trace arguments of a DBAL failure. Stateless (static methods only).
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
    public static function insertOnce(Connection $connection, string $savepoint, string $table, #[SensitiveParameter] array $row): bool
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
            if (($nested && !self::rollBackToEntry($connection, $savepoint)) || !$failure instanceof UniqueConstraintViolationException) {
                throw $failure;
            }

            return false;
        }
    }

    /**
     * Lost-savepoint pattern (REQ-RUNTIME-003, audit ZCZ-I1): roll back to the savepoint, i.e. the transaction level the
     * write was entered at; when that fails (MySQL / MariaDB drop every savepoint on a deadlock, so the host's
     * transaction is already gone on the server) the connection is closed, so the host cannot commit half of it and the
     * next use reconnects. The caller then rethrows the original failure. False when the connection was closed.
     */
    private static function rollBackToEntry(Connection $connection, string $savepoint): bool
    {
        try {
            $connection->rollbackSavepoint($savepoint);

            return true;
        } catch (Throwable) {
            $connection->close();

            return false;
        }
    }

    /**
     * Runs one write statement and returns the affected rows; the failure is rethrown unchanged after the savepoint
     * rollback.
     *
     * @param list<mixed> $params
     */
    public static function write(Connection $connection, string $savepoint, string $sql, #[SensitiveParameter] array $params): int
    {
        if (!$connection->isTransactionActive()) {
            return (int) $connection->executeStatement($sql, $params);
        }
        $connection->createSavepoint($savepoint);

        try {
            $count = (int) $connection->executeStatement($sql, $params);
        } catch (Throwable $failure) {
            self::rollBackToEntry($connection, $savepoint);

            throw $failure;
        }
        $connection->releaseSavepoint($savepoint);

        return $count;
    }
}
