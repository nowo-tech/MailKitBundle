<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Nowo\MailKitBundle\Sender\MailAddress;
use Nowo\MailKitBundle\Sender\SenderIdentityProviderInterface;
use Nowo\MailKitBundle\Sender\SenderProviderInterface;
use Symfony\Component\Mime\Address;

/**
 * Host sender of the tests that implements both ports with **different** values (1.12): the framework-free port must
 * win (`identity@…`), the deprecated one (`legacy@…`) must never be asked.
 */
final class DualSenderProvider implements SenderIdentityProviderInterface, SenderProviderInterface
{
    public function defaultFrom(): MailAddress
    {
        return new MailAddress('identity@example.com', 'Identity');
    }

    public function defaultReplyTo(): MailAddress
    {
        return new MailAddress('identity-help@example.com');
    }

    public function from(): Address
    {
        return new Address('legacy@example.com', 'Legacy');
    }

    public function replyTo(): Address
    {
        return new Address('legacy-help@example.com');
    }
}
