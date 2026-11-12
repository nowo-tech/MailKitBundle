<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tracking;

use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use SensitiveParameter;
use Symfony\Component\Clock\NativeClock;

use function array_slice;
use function base64_decode;
use function base64_encode;
use function bin2hex;
use function hash_equals;
use function hash_hmac;
use function hex2bin;
use function pack;
use function preg_match;
use function random_bytes;
use function rtrim;
use function strlen;
use function strtr;
use function substr;
use function unpack;

/**
 * Open-pixel tokens (1.10): `base64url(send id (16 bytes) . issued at (uint32, UNIX seconds) . MAC (16 bytes))`, 48
 * characters, where MAC = the first 16 bytes of HMAC-SHA256 under a key derived from `tracking.secret` (or the kernel
 * secret). The token carries no address and nothing about the recipient; it only proves that MailKit issued this send
 * id. A token is accepted until `tracking.retention` after it was issued (and up to 5 minutes in the future, clock
 * skew); `tracking.previous_secrets` keep older tokens valid while a secret is rotated.
 *
 * {@see self::verify()} does the same work for every input (one MAC per key, constant-time comparison): a malformed
 * token is checked against a fixed dummy payload. Immutable (FrankenPHP worker mode).
 */
final readonly class TrackingTokenSigner
{
    /** Token length (base64url, no padding). */
    public const TOKEN_LENGTH = 48;

    /** Accepted clock skew of the issue time, in seconds. */
    public const MAX_SKEW = 300;

    private const DOMAIN = 'nowo_mail_kit.tracking.open.v1';

    private const DUMMY = "\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0";

    /** @var non-empty-list<string> */
    private array $keys;

    /**
     * @param string $secret current secret (at least 32 bytes; the extension derives one from the kernel secret when
     *                       `tracking.secret` is null)
     * @param list<string> $previousSecrets secrets still accepted for verification (rotation)
     * @param int $ttl seconds a token stays valid (`tracking.retention`)
     *
     * @throws InvalidArgumentException for a secret shorter than 32 bytes
     */
    public function __construct(
        #[SensitiveParameter]
        string $secret,
        #[SensitiveParameter]
        array $previousSecrets = [],
        private int $ttl = 7_776_000,
        private ?ClockInterface $clock = null,
    ) {
        $keys = [];
        foreach ([$secret, ...$previousSecrets] as $value) {
            if (strlen($value) < 32) {
                throw new InvalidArgumentException('A tracking secret must be at least 32 bytes long.');
            }
            $keys[] = hash_hmac('sha256', self::DOMAIN, $value, true);
        }
        $this->keys = $keys;
    }

    /**
     * Signer keyed from the kernel secret (`tracking.secret: null`): a domain-separated HMAC of it, so a short
     * `APP_SECRET` still gives a 32-byte key and the pixel key differs from every other use of the kernel secret. The
     * previous secrets are previous kernel secrets and are derived the same way (audit NU-M1).
     *
     * @param list<string> $previousSecrets previous kernel secrets (rotation of `APP_SECRET`)
     *
     * @throws InvalidArgumentException for an empty kernel or previous secret
     */
    public static function fromKernelSecret(
        #[SensitiveParameter]
        string $kernelSecret,
        #[SensitiveParameter]
        array $previousSecrets = [],
        int $ttl = 7_776_000,
        ?ClockInterface $clock = null,
    ): self {
        $derived = [];
        foreach ([$kernelSecret, ...$previousSecrets] as $secret) {
            if ($secret === '') {
                throw new InvalidArgumentException('nowo_mail_kit.tracking.secret is null and kernel.secret (or a previous secret) is empty: set them.');
            }
            $derived[] = hash_hmac('sha256', 'nowo_mail_kit.tracking.secret', $secret);
        }

        return new self($derived[0], array_slice($derived, 1), $ttl, $clock);
    }

    /**
     * Hides the derived keys from dumps.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['ttl' => $this->ttl, 'keys' => '***'];
    }

    /**
     * New opaque send id: 32 lower-case hex characters (128 random bits).
     */
    public static function newSendId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Token of a send id, issued now.
     *
     * @throws InvalidArgumentException for a malformed send id
     */
    public function issue(string $sendId): string
    {
        if (!TrackingIds::isSendId($sendId)) {
            throw new InvalidArgumentException('A tracking send id must be 32 lower-case hexadecimal characters.');
        }
        $payload = hex2bin($sendId) . pack('N', $this->now());

        return $this->encode($payload . $this->mac($this->keys[0], $payload));
    }

    /**
     * The send id of a valid, unexpired token, else null.
     */
    public function verify(string $token): ?string
    {
        $raw        = strlen($token) === self::TOKEN_LENGTH && preg_match('/^[A-Za-z0-9_-]+\z/', $token) === 1 ? base64_decode(strtr($token, '-_', '+/'), true) : false;
        $wellFormed = $raw !== false && strlen($raw) === 36;
        $payload    = $wellFormed ? substr($raw, 0, 20) : self::DUMMY;
        $mac        = $wellFormed ? substr($raw, 20) : self::DUMMY;
        $valid      = false;
        foreach ($this->keys as $key) {
            $valid = hash_equals($this->mac($key, $payload), substr($mac, 0, 16)) || $valid;
        }
        if (!$wellFormed || !$valid) {
            return null;
        }
        /** @var array{1: int} $issued */
        $issued = unpack('N', substr($payload, 16, 4));
        $now    = $this->now();
        if ($issued[1] > $now + self::MAX_SKEW || $now - $issued[1] > $this->ttl) {
            return null;
        }

        return bin2hex(substr($payload, 0, 16));
    }

    private function mac(string $key, string $payload): string
    {
        return substr(hash_hmac('sha256', $payload, $key, true), 0, 16);
    }

    private function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function now(): int
    {
        return ($this->clock ?? new NativeClock())->now()->getTimestamp();
    }
}
