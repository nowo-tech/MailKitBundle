<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Dsn;

use Nowo\OutboundUrlGuardBundle\Dns\HostnameDnsLookup;
use Nowo\OutboundUrlGuardBundle\Network\PrivateNetworkTarget;

use function array_map;
use function array_unique;
use function array_values;
use function in_array;
use function is_array;
use function is_string;
use function rtrim;
use function strtolower;
use function trim;

use const DNS_A;
use const DNS_AAAA;

/**
 * SSRF guard of the host of a stored mailer DSN (the server the application will connect to): the private,
 * reserved and cloud-metadata rules of nowo-tech/outbound-url-guard-bundle applied to SMTP / provider hosts.
 *
 * Order: unparsable host → refused; explicitly allowed host → accepted; cloud metadata (name or address) → always
 * refused; `allow_private` → accepted; localhost / *.local / *.internal / private or reserved IP → refused; with
 * `resolve_dns`, a name that resolves to no address or to any private address → refused (bounded lookup).
 */
final readonly class DsnHostGuard
{
    /** Error key: the host is private, local, reserved or cloud metadata. */
    public const HOST_NOT_ALLOWED = 'dsn.host_not_allowed';

    /** Error key: the host name does not resolve. */
    public const HOST_UNRESOLVABLE = 'dsn.host_unresolvable';

    /** @var list<string> */
    private array $allowedHosts;

    /**
     * @param list<string> $allowedHosts hosts accepted even when private (exact, case-insensitive)
     * @param bool $allowPrivate accept private / loopback / link-local hosts (cloud metadata stays refused)
     * @param bool $resolveDns resolve names and refuse those pointing at private addresses
     */
    public function __construct(
        array $allowedHosts = [],
        private bool $allowPrivate = false,
        private bool $resolveDns = true,
        private HostnameDnsLookup $dns = new HostnameDnsLookup(),
    ) {
        $this->allowedHosts = array_values(array_map(static fn (string $host): string => strtolower(rtrim(trim($host), '.')), $allowedHosts));
    }

    /**
     * Null when the host is acceptable, else an error translation key.
     */
    public function check(string $host): ?string
    {
        $ascii = PrivateNetworkTarget::asciiHost(trim($host, '[] '));
        $ascii = $ascii === null ? '' : rtrim($ascii, '.');
        if ($ascii === '') {
            return self::HOST_NOT_ALLOWED;
        }
        if (in_array($ascii, $this->allowedHosts, true)) {
            return null;
        }

        $ip = PrivateNetworkTarget::canonicalIp($ascii);
        if ($ip === null && !PrivateNetworkTarget::isValidHostName($ascii)) {
            return self::HOST_NOT_ALLOWED;
        }
        if (PrivateNetworkTarget::isCloudMetadataHost($ascii) || ($ip !== null && PrivateNetworkTarget::isCloudMetadataIp($ip))) {
            return self::HOST_NOT_ALLOWED;
        }
        if ($this->allowPrivate) {
            return null;
        }
        if (PrivateNetworkTarget::isBlockedHostName($ascii)) {
            return self::HOST_NOT_ALLOWED;
        }
        if ($ip !== null) {
            return PrivateNetworkTarget::isBlockedIp($ip) ? self::HOST_NOT_ALLOWED : null;
        }
        if (!$this->resolveDns) {
            return null;
        }

        $addresses = $this->resolve($ascii);
        if ($addresses === []) {
            return self::HOST_UNRESOLVABLE;
        }
        foreach ($addresses as $address) {
            if (PrivateNetworkTarget::isBlockedIp($address) || PrivateNetworkTarget::isCloudMetadataIp($address)) {
                return self::HOST_NOT_ALLOWED;
            }
        }

        return null;
    }

    /**
     * A and AAAA addresses of a host name (gethostbynamel fallback), through the bounded lookup.
     *
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        $addresses = [];
        foreach ([DNS_A => 'ip', DNS_AAAA => 'ipv6'] as $type => $field) {
            $records = $this->dns->dnsGetRecord($host, $type);
            foreach (is_array($records) ? $records : [] as $record) {
                if (is_array($record) && is_string($record[$field] ?? null) && $record[$field] !== '') {
                    $addresses[] = $record[$field];
                }
            }
        }
        if ($addresses === []) {
            $fallback = $this->dns->hostByNameL($host);
            foreach (is_array($fallback) ? $fallback : [] as $address) {
                if (is_string($address) && $address !== '') {
                    $addresses[] = $address;
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
