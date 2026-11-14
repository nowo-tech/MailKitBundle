<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Nowo\MailKitBundle\Sender\MailAddress;
use Nowo\MailKitBundle\Sender\SenderIdentityProviderInterface;

/**
 * Host sender identity of the tests on the framework-free port (1.12): no Symfony Mime type anywhere.
 */
final class StaticSenderIdentityProvider implements SenderIdentityProviderInterface
{
    public function defaultFrom(): MailAddress
    {
        return new MailAddress('tenant@example.com', 'Tenant');
    }

    public function defaultReplyTo(): MailAddress
    {
        return MailAddress::fromString('tenant-help@example.com');
    }
}
