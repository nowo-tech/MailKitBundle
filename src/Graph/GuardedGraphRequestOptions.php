<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph;

use Nowo\OutboundUrlGuardBundle\Exception\UnsafeOutboundUrlException;
use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuard;
use SensitiveParameter;

/**
 * Pins the Microsoft Graph host to the public address OutboundUrlGuard resolved (2.5.0, the CrmKit 0.10 pattern):
 * defence in depth against DNS tampering that points `graph.microsoft.com` at an internal address while a bearer
 * token rides on the request. Private addresses are never allowed here, whatever the guard's own default; an unsafe
 * answer refuses the request ({@see GraphException::REASON_UNTRUSTED_URL}).
 *
 * @internal
 */
final readonly class GuardedGraphRequestOptions implements GraphRequestOptionsInterface
{
    /**
     * Wires the guard.
     */
    public function __construct(private OutboundUrlGuard $guard)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function options(#[SensitiveParameter] string $url): array
    {
        try {
            return $this->guard->httpClientOptions($url, false, true);
        } catch (UnsafeOutboundUrlException) {
            throw GraphException::untrustedUrl('host pin');
        }
    }
}
