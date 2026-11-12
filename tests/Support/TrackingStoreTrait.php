<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\MailKitBundle\Tracking\Store\TrackingStore;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * In-memory SQLite {@see TrackingStore} with its table, for unit tests.
 */
trait TrackingStoreTrait
{
    private ?Connection $trackingConnection = null;

    private function trackingStore(string $prefix = '', ?ClockInterface $clock = null, ?LoggerInterface $logger = null): TrackingStore
    {
        $this->trackingConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $registry                 = $this->createStub(ManagerRegistry::class);
        $registry->method('getConnection')->willReturn($this->trackingConnection);
        $store = new TrackingStore($registry, null, $prefix, $clock, $logger);
        $store->execute($store->missingTablesSql());

        return $store;
    }

    private function trackingDb(): Connection
    {
        self::assertInstanceOf(Connection::class, $this->trackingConnection);

        return $this->trackingConnection;
    }
}
