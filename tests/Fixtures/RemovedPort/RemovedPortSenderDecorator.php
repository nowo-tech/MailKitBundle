<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Fixtures\RemovedPort;

use Nowo\MailKitBundle\Sender\SenderProviderInterface;
use Symfony\Component\Mime\Address;

/**
 * A 1.x host decorator of the sender that still implements the Mime-typed port removed in 2.0: it cannot be loaded any more, so the
 * container build must name it (excluded from PHPStan and Rector, which cannot resolve the removed interface).
 */
final class RemovedPortSenderDecorator implements SenderProviderInterface
{
    public function from(): Address
    {
        return new Address('legacy@example.com');
    }

    public function replyTo(): ?Address
    {
        return null;
    }
}
