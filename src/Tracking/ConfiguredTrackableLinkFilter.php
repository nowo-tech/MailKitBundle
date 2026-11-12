<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tracking;

use function in_array;
use function is_string;
use function parse_url;
use function rtrim;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;

use const PHP_URL_HOST;
use const PHP_URL_PATH;

/**
 * Default {@see TrackableLinkFilterInterface} (1.10): `tracking.links.allowed_hosts` and
 * `tracking.links.allowed_path_prefixes`. Deny by default: with no host listed nothing is rewritten.
 *
 * - Host: exact (case-insensitive), `*.example.com` (sub-domains only, not `example.com` itself) or `*` (any host).
 * - Path prefix: empty list = any path; `/issues` matches `/issues` and `/issues/…`, not `/issues-old`.
 */
final readonly class ConfiguredTrackableLinkFilter implements TrackableLinkFilterInterface
{
    /** @var list<string> */
    private array $hosts;

    /** @var list<string> */
    private array $prefixes;

    /**
     * @param list<string> $allowedHosts
     * @param list<string> $allowedPathPrefixes
     */
    public function __construct(array $allowedHosts = [], array $allowedPathPrefixes = [])
    {
        $hosts = [];
        foreach ($allowedHosts as $host) {
            $hosts[] = strtolower($host);
        }
        $prefixes = [];
        foreach ($allowedPathPrefixes as $prefix) {
            $prefixes[] = $prefix === '/' ? '/' : rtrim($prefix, '/');
        }
        $this->hosts    = $hosts;
        $this->prefixes = $prefixes;
    }

    /**
     * Host and path in the allow-list.
     */
    public function mayRewrite(string $url, ?string $templateId): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '' || !$this->hostAllowed(strtolower($host))) {
            return false;
        }
        if ($this->prefixes === []) {
            return true;
        }
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';
        foreach ($this->prefixes as $prefix) {
            if ($prefix === '/' || $path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    private function hostAllowed(string $host): bool
    {
        if (in_array('*', $this->hosts, true) || in_array($host, $this->hosts, true)) {
            return true;
        }
        foreach ($this->hosts as $allowed) {
            if (str_starts_with($allowed, '*.') && str_ends_with($host, substr($allowed, 1)) && strlen($host) > strlen($allowed) - 1) {
                return true;
            }
        }

        return false;
    }
}
