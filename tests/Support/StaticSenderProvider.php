<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Nowo\MailKitBundle\Sender\SenderProviderInterface;
use Symfony\Component\Mime\Address;

/**
 * Host sender provider of the tests (tenant identity outside Instance Settings).
 */
final class StaticSenderProvider implements SenderProviderInterface
{
    public function from(): Address
    {
        return new Address('tenant@example.com', 'Tenant');
    }

    public function replyTo(): Address
    {
        return new Address('tenant-help@example.com');
    }
}
