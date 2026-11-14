<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Nowo\MailKitBundle\Sender\MailAddress;
use Nowo\MailKitBundle\Sender\SenderIdentityProviderInterface;
use Nowo\MailKitBundle\Sender\SenderProviderInterface;
use Symfony\Component\Mime\Address;

/**
 * Host decorator of a sender service that implements both ports (1.12.1 decorator tests).
 */
final readonly class IdentitySenderDecorator implements SenderIdentityProviderInterface, SenderProviderInterface
{
    public function __construct(public SenderIdentityProviderInterface|SenderProviderInterface $inner)
    {
    }

    public function defaultFrom(): MailAddress
    {
        return new MailAddress('decorated-identity@example.com');
    }

    public function defaultReplyTo(): ?MailAddress
    {
        return null;
    }

    public function from(): Address
    {
        return new Address('decorated-identity@example.com');
    }

    public function replyTo(): ?Address
    {
        return null;
    }
}
