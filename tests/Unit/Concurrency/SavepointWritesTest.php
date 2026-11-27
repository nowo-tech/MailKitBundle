<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Concurrency;

use Doctrine\DBAL\Connection;
use Nowo\MailKitBundle\Concurrency\SavepointWrites;
use Nowo\MailKitBundle\Tests\Support\StorageFailures;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Audit ZCZ-I1, lost-savepoint pattern: when the savepoint cannot be rolled back (MySQL / MariaDB dropped it on a
 * deadlock), the connection is closed and the original failure is rethrown (also a duplicate key, which is no longer
 * reported as "exists" because the transaction is gone). A savepoint that rolls back keeps the connection open.
 */
final class SavepointWritesTest extends TestCase
{
    public function testALostSavepointClosesTheConnectionAndRethrowsTheOriginalFailure(): void
    {
        foreach ([StorageFailures::deadlock(), StorageFailures::uniqueViolation()] as $original) {
            $connection = $this->createMock(Connection::class);
            $connection->method('isTransactionActive')->willReturn(true);
            $connection->method('insert')->willThrowException($original);
            $connection->method('executeStatement')->willThrowException($original);
            $connection->method('rollbackSavepoint')->willThrowException(StorageFailures::lostSavepoint());
            $connection->expects(self::exactly(2))->method('close');
            foreach ([
                static fn (): mixed => SavepointWrites::insertOnce($connection, 'sp', 't', ['a' => 1]),
                static fn (): mixed => SavepointWrites::write($connection, 'sp', 'UPDATE t SET a = 1', []),
            ] as $call) {
                try {
                    $call();
                    self::fail('rethrown');
                } catch (Throwable $failure) {
                    self::assertSame($original, $failure);
                }
            }
        }
    }

    public function testARolledBackSavepointKeepsTheConnection(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->method('insert')->willThrowException(StorageFailures::uniqueViolation());
        $connection->expects(self::once())->method('rollbackSavepoint');
        $connection->expects(self::never())->method('close');
        self::assertFalse(SavepointWrites::insertOnce($connection, 'sp', 't', ['a' => 1]));
    }
}
