<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Nowo\MailKitBundle\Sender\MailAddress;
use Nowo\MailKitBundle\Sender\SenderIdentityProviderInterface;

/**
 * Host decorator of the sender port (decorator tests).
 */
final readonly class IdentitySenderDecorator implements SenderIdentityProviderInterface
{
    public function __construct(public SenderIdentityProviderInterface $inner)
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
}
