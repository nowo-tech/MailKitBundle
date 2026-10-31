<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Nowo\MailKitBundle\Dsn\DsnSource;
use Nowo\MailKitBundle\Dsn\MailerDsnProviderInterface;
use Nowo\MailKitBundle\Dsn\RuntimeDsn;

/**
 * Host provider double: always returns the configured DSN (or null).
 */
final class StaticDsnProvider implements MailerDsnProviderInterface
{
    public function __construct(
        public ?string $dsn = null,
    ) {
    }

    public function getDsn(): ?RuntimeDsn
    {
        return $this->dsn === null ? null : new RuntimeDsn($this->dsn, DsnSource::Custom);
    }
}
