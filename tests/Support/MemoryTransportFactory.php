<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use InvalidArgumentException;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Factory of {@see MemoryTransport} (scheme `memory`).
 */
final class MemoryTransportFactory implements TransportFactoryInterface
{
    public function __construct(
        private readonly MemoryMailStore $store,
    ) {
    }

    public function create(Dsn $dsn): TransportInterface
    {
        if ($dsn->getHost() === 'broken') {
            throw new InvalidArgumentException('Broken test transport.');
        }

        if ($dsn->getHost() === 'weird') {
            // A transport whose endpoint cannot be read (no scheme separator).
            return new class($this->store) extends MemoryTransport {
                public function __construct(MemoryMailStore $store)
                {
                    parent::__construct('weird', $store);
                }

                public function __toString(): string
                {
                    return 'weird';
                }
            };
        }

        return new MemoryTransport($dsn->getHost(), $this->store);
    }

    public function supports(Dsn $dsn): bool
    {
        return $dsn->getScheme() === 'memory';
    }
}
