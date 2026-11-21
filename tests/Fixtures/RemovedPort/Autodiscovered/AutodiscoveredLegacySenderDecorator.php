<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Fixtures\RemovedPort\Autodiscovered;

use Nowo\MailKitBundle\Sender\SenderProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Mime\Address;

/**
 * A 1.x host decorator registered by resource autodiscovery that still implements the port removed in 2.0: Symfony
 * cannot load it, keeps the definition with only an error and never reads the attribute (excluded from PHPStan and
 * Rector, which cannot resolve the removed interface).
 */
#[AsDecorator(SenderProviderInterface::class)]
final readonly class AutodiscoveredLegacySenderDecorator implements SenderProviderInterface
{
    public function __construct(#[AutowireDecorated] private SenderProviderInterface $inner)
    {
    }

    public function from(): ?Address
    {
        return new Address('decorated-legacy@example.com');
    }

    public function replyTo(): ?Address
    {
        return $this->inner->replyTo();
    }
}
