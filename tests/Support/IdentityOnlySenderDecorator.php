<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Nowo\MailKitBundle\Sender\MailAddress;
use Nowo\MailKitBundle\Sender\SenderIdentityProviderInterface;
use Nowo\MailKitBundle\Sender\SenderProviderInterface;

/**
 * Host decorator that implements only the new sender port (1.12.2 decorator tests, D-I1).
 */
final readonly class IdentityOnlySenderDecorator implements SenderIdentityProviderInterface
{
    public function __construct(public SenderIdentityProviderInterface|SenderProviderInterface $inner)
    {
    }

    public function defaultFrom(): MailAddress
    {
        return new MailAddress('identity-only@example.com');
    }

    public function defaultReplyTo(): ?MailAddress
    {
        return null;
    }
}
