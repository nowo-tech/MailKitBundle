<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * DBAL storage failures with real vendor codes / SQLSTATEs, for the conflict mapping tests (REQ-RUNTIME-003).
 */
final class StorageFailures
{
    public static function deadlock(): DeadlockException
    {
        return new DeadlockException(self::driver('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock', '40001', 1213), null);
    }

    public static function lockWaitTimeout(): LockWaitTimeoutException
    {
        return new LockWaitTimeoutException(self::driver('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded', 'HY000', 1205), null);
    }

    public static function serializationFailure(): DriverException
    {
        return new DriverException(self::driver('SQLSTATE[40001]: could not serialize access', '40001', 0), null);
    }

    public static function lostSavepoint(): DriverException
    {
        return new DriverException(self::driver('SQLSTATE[42000]: Syntax error or access violation: 1305 SAVEPOINT DOCTRINE_2 does not exist', '42000', 1305), null);
    }

    public static function uniqueViolation(): UniqueConstraintViolationException
    {
        return new UniqueConstraintViolationException(self::driver('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry', '23000', 1062), null);
    }

    public static function connectionLost(): ConnectionLost
    {
        return new ConnectionLost(self::driver('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away', 'HY000', 2006), null);
    }

    private static function driver(string $message, string $sqlState, int $code): AbstractException
    {
        return new class($message, $sqlState, $code) extends AbstractException {
        };
    }
}
