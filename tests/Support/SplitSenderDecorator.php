<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Nowo\MailKitBundle\Sender\MailAddress;
use Nowo\MailKitBundle\Sender\SenderIdentityProviderInterface;
use Nowo\MailKitBundle\Sender\SenderProviderInterface;
use Symfony\Component\Mime\Address;

/**
 * Host decorator that implements both sender ports with different values (1.12.2 decorator tests, D-L1): the new
 * port returns `split-new@example.com`, the deprecated one `split-old@example.com`.
 */
final readonly class SplitSenderDecorator implements SenderIdentityProviderInterface, SenderProviderInterface
{
    public function __construct(public SenderIdentityProviderInterface|SenderProviderInterface $inner)
    {
    }

    public function defaultFrom(): MailAddress
    {
        return new MailAddress('split-new@example.com');
    }

    public function defaultReplyTo(): MailAddress
    {
        return new MailAddress('split-new-help@example.com');
    }

    public function from(): Address
    {
        return new Address('split-old@example.com');
    }

    public function replyTo(): Address
    {
        return new Address('split-old-help@example.com');
    }
}
