<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\MailKitBundle\Graph\Inbound\Store\GraphInboundStore;
use Psr\Clock\ClockInterface;

/**
 * In-memory SQLite {@see GraphInboundStore} with its tables, for unit tests.
 */
trait InboundStoreTrait
{
    private ?Connection $inboundConnection = null;

    private function inboundStore(string $prefix = '', ?ClockInterface $clock = null): GraphInboundStore
    {
        $this->inboundConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $registry                = $this->createStub(ManagerRegistry::class);
        $registry->method('getConnection')->willReturn($this->inboundConnection);
        $store = new GraphInboundStore($registry, null, $prefix, $clock);
        $store->execute($store->missingTablesSql());

        return $store;
    }
}
