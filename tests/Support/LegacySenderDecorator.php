<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Nowo\MailKitBundle\Sender\SenderIdentityProviderInterface;
use Nowo\MailKitBundle\Sender\SenderProviderInterface;
use Symfony\Component\Mime\Address;

/**
 * Host decorator of a sender service that implements only the deprecated port (1.12.1 decorator tests).
 */
final readonly class LegacySenderDecorator implements SenderProviderInterface
{
    public function __construct(public SenderIdentityProviderInterface|SenderProviderInterface $inner)
    {
    }

    public function from(): Address
    {
        return new Address('decorated@example.com', 'Decorated');
    }

    public function replyTo(): ?Address
    {
        return null;
    }
}
