<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Nowo\MailKitBundle\Sender\SenderProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mime\Address;

use function is_string;

/**
 * Host sender port of a multi-tenant DDD application: the sender of the tenant of the current request (route attribute
 * `tenant`), `cli@example.com` outside a request. Reads per call, keeps nothing (worker mode).
 */
final readonly class RequestTenantSenderProvider implements SenderProviderInterface
{
    public function __construct(
        private RequestStack $requests,
    ) {
    }

    public function from(): Address
    {
        return new Address($this->tenant() . '@example.com', 'Tenant ' . $this->tenant());
    }

    public function replyTo(): ?Address
    {
        return null;
    }

    private function tenant(): string
    {
        $request = $this->requests->getCurrentRequest();
        $tenant  = $request instanceof Request ? $request->attributes->get('tenant') : null;

        return is_string($tenant) && $tenant !== '' ? $tenant : 'cli';
    }
}
