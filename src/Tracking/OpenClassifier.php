<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tracking;

use function explode;
use function filter_var;
use function inet_ntop;
use function inet_pton;
use function intdiv;
use function is_string;
use function ord;
use function str_contains;
use function str_repeat;
use function strlen;
use function strtolower;
use function substr;

use const FILTER_VALIDATE_IP;

/**
 * Classifies a pixel request (1.10) without keeping it: machine open or not (Apple Mail Privacy Protection, image
 * proxies, scanners: `tracking.machine_opens.user_agents` substrings or `ip_ranges`, and an empty user agent), the
 * user-agent family (a fixed short list, never the string itself) and the truncated client address (IPv4 /24, IPv6
 * /48). Immutable (FrankenPHP worker mode).
 */
final readonly class OpenClassifier
{
    /** Families {@see self::family()} returns. */
    public const FAMILIES = ['unknown', 'apple_mail', 'outlook', 'gmail_proxy', 'yahoo_proxy', 'thunderbird', 'edge', 'firefox', 'chrome', 'safari', 'bot', 'other'];

    /** @var list<string> */
    private array $agents;

    /** @var list<array{0: string, 1: int}> */
    private array $ranges;

    /**
     * @param list<string> $machineUserAgents case-insensitive substrings
     * @param list<string> $machineIpRanges addresses or CIDR ranges (validated by the configuration)
     */
    public function __construct(array $machineUserAgents = [], array $machineIpRanges = [])
    {
        $agents = [];
        foreach ($machineUserAgents as $agent) {
            $agents[] = strtolower($agent);
        }
        $ranges = [];
        foreach ($machineIpRanges as $range) {
            $parts  = explode('/', $range, 2);
            $packed = $this->pack($parts[0]);
            if (is_string($packed)) {
                $ranges[] = [$packed, isset($parts[1]) ? (int) $parts[1] : strlen($packed) * 8];
            }
        }
        $this->agents = $agents;
        $this->ranges = $ranges;
    }

    /**
     * True for an automated fetch (proxy, privacy protection, scanner).
     */
    public function isMachine(?string $ip, ?string $userAgent): bool
    {
        $agent = strtolower((string) $userAgent);
        if ($agent === '') {
            return true;
        }
        foreach ($this->agents as $needle) {
            if (str_contains($agent, $needle)) {
                return true;
            }
        }
        $packed = $this->pack($ip);
        if (!is_string($packed)) {
            return false;
        }
        foreach ($this->ranges as [$network, $bits]) {
            if (strlen($network) === strlen($packed) && $this->inRange($packed, $network, $bits)) {
                return true;
            }
        }

        return false;
    }

    /**
     * User-agent family (one of {@see self::FAMILIES}).
     */
    public function family(?string $userAgent): string
    {
        $agent = strtolower((string) $userAgent);

        return match (true) {
            $agent === ''                                                                                                                                                      => 'unknown',
            str_contains($agent, 'googleimageproxy')                                                                                                                           => 'gmail_proxy',
            str_contains($agent, 'yahoomailproxy')                                                                                                                             => 'yahoo_proxy',
            str_contains($agent, 'outlook') || str_contains($agent, 'microsoft office') || str_contains($agent, 'ms-office')                                                   => 'outlook',
            str_contains($agent, 'thunderbird')                                                                                                                                => 'thunderbird',
            str_contains($agent, 'bot') || str_contains($agent, 'crawler') || str_contains($agent, 'spider') || str_contains($agent, 'curl') || str_contains($agent, 'python') => 'bot',
            str_contains($agent, 'edg/')                                                                                                                                       => 'edge',
            str_contains($agent, 'firefox')                                                                                                                                    => 'firefox',
            str_contains($agent, 'chrome') || str_contains($agent, 'crios')                                                                                                    => 'chrome',
            str_contains($agent, 'safari')                                                                                                                                     => 'safari',
            str_contains($agent, 'applewebkit')                                                                                                                                => 'apple_mail',
            default                                                                                                                                                            => 'other',
        };
    }

    /**
     * Client address with the host part cleared (IPv4 /24, IPv6 /48), or null when it is not an address.
     */
    public function truncate(?string $ip): ?string
    {
        $packed = $this->pack($ip);
        if (!is_string($packed)) {
            return null;
        }
        $keep      = strlen($packed) === 4 ? 3 : 6;
        $truncated = inet_ntop(substr($packed, 0, $keep) . str_repeat("\0", strlen($packed) - $keep));

        return is_string($truncated) ? $truncated : null;
    }

    private function pack(?string $ip): string|false
    {
        return $ip !== null && filter_var($ip, FILTER_VALIDATE_IP) !== false ? inet_pton($ip) : false;
    }

    private function inRange(string $address, string $network, int $bits): bool
    {
        $bytes = intdiv($bits, 8);
        if (substr($address, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($address[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }
}
