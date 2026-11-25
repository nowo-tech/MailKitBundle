<?php

declare(strict_types=1);

namespace App\Outlook;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

use function basename;
use function chmod;
use function dirname;
use function fclose;
use function fflush;
use function file_exists;
use function filemtime;
use function flock;
use function fopen;
use function ftruncate;
use function fwrite;
use function is_array;
use function is_dir;
use function is_int;
use function is_numeric;
use function is_string;
use function json_decode;
use function json_encode;
use function realpath;
use function rewind;
use function rtrim;
use function str_starts_with;
use function stream_get_contents;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const LOCK_EX;
use const LOCK_UN;

/**
 * DEMO ONLY (Outlook manual test harness): the scratch JSON file that holds the delegated token of the test
 * Outlook.com account. Accepted shapes: the raw Microsoft token response (`access_token`, `refresh_token`,
 * `expires_in`, `scope`; the expiry is then counted from the file's mtime) or the same with `expires_at` (Unix time),
 * which is what this harness writes back after a refresh.
 *
 * The file must live outside every repository: a path inside a forbidden root (the demo, the bundle checkout) or
 * inside any git work tree is refused. Token values never appear in an exception message.
 */
final class TokenFile
{
    /** Seconds before the expiry at which the access token counts as expired. */
    public const EXPIRY_MARGIN = 120;

    private readonly string $path;

    /**
     * @param list<string> $forbiddenRoots directories the file must not be in (resolved with realpath when they exist)
     */
    public function __construct(string $path, array $forbiddenRoots)
    {
        $this->path = self::guard($path, $forbiddenRoots);
    }

    /**
     * Resolves the path and refuses one inside a forbidden root or a git work tree.
     *
     * @param list<string> $forbiddenRoots
     *
     * @throws InvalidArgumentException
     */
    public static function guard(string $path, array $forbiddenRoots): string
    {
        if ($path === '') {
            throw new InvalidArgumentException('Set MAILKIT_OUTLOOK_TOKEN_FILE (or --token-file) to a JSON file outside any repository.');
        }
        $dir = realpath(dirname($path));
        if ($dir === false || !is_dir($dir)) {
            throw new InvalidArgumentException('The directory of the token file does not exist.');
        }
        $file = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($path);
        if (file_exists($file)) {
            $resolved = realpath($file);
            if ($resolved === false) {
                throw new InvalidArgumentException('The token file cannot be resolved.');
            }
            $file = $resolved;
            $dir  = dirname($file);
        }
        foreach ($forbiddenRoots as $root) {
            $real = realpath($root);
            $root = rtrim($real !== false ? $real : $root, DIRECTORY_SEPARATOR);
            if ($root !== '' && ($dir === $root || str_starts_with($dir, $root . DIRECTORY_SEPARATOR))) {
                throw new InvalidArgumentException('Refused: the token file is inside the repository tree. Keep it in a scratch directory outside any repository.');
            }
        }
        for ($current = $dir;; $current = dirname($current)) {
            if (file_exists($current . DIRECTORY_SEPARATOR . '.git')) {
                throw new InvalidArgumentException('Refused: the token file is inside a git work tree. Keep it in a scratch directory outside any repository.');
            }
            if (dirname($current) === $current) {
                break;
            }
        }

        return $file;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return file_exists($this->path);
    }

    /**
     * @return array{access_token: string, refresh_token: string|null, expires_at: int, scope: string|null}
     */
    public function read(): array
    {
        return $this->update(static fn (): ?array => null);
    }

    /**
     * Reads the file under an exclusive lock and, when `$change` returns data, writes it back (mode 0600) before the
     * lock is released, so two processes never refresh the same refresh token at once.
     *
     * @param callable(array{access_token: string, refresh_token: string|null, expires_at: int, scope: string|null}): (array{access_token: string, refresh_token: string|null, expires_at: int, scope: string|null}|null) $change
     *
     * @return array{access_token: string, refresh_token: string|null, expires_at: int, scope: string|null}
     */
    public function update(callable $change): array
    {
        if (!file_exists($this->path)) {
            throw new RuntimeException('The token file does not exist yet: run the device-code login first (make outlook-login).');
        }
        $handle = @fopen($this->path, 'r+');
        if ($handle === false) {
            throw new RuntimeException('The token file is not readable and writable.');
        }

        try {
            flock($handle, LOCK_EX);
            $raw  = stream_get_contents($handle);
            $data = self::parse(is_string($raw) ? $raw : '', (int) filemtime($this->path));
            $new  = $change($data);
            if ($new !== null) {
                rewind($handle);
                ftruncate($handle, 0);
                fwrite($handle, self::encode($new));
                fflush($handle);
                $data = $new;
            }
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
        @chmod($this->path, 0o600);

        return $data;
    }

    /**
     * Creates or replaces the file (device-code login), mode 0600.
     *
     * @param array{access_token: string, refresh_token: string|null, expires_at: int, scope: string|null} $data
     */
    public function write(array $data): void
    {
        $handle = @fopen($this->path, 'c');
        if ($handle === false) {
            throw new RuntimeException('The token file cannot be written.');
        }
        @chmod($this->path, 0o600);

        try {
            flock($handle, LOCK_EX);
            ftruncate($handle, 0);
            fwrite($handle, self::encode($data));
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array{access_token: string, refresh_token: string|null, expires_at: int, scope: string|null}
     */
    public static function parse(string $json, int $mtime): array
    {
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('The token file is not valid JSON.');
        }
        if (!is_array($data) || !is_string($data['access_token'] ?? null) || $data['access_token'] === '') {
            throw new RuntimeException('The token file has no access_token.');
        }
        $refresh = $data['refresh_token'] ?? null;
        if (is_int($data['expires_at'] ?? null)) {
            $expiresAt = $data['expires_at'];
        } elseif (is_numeric($data['expires_in'] ?? null)) {
            $expiresAt = $mtime + (int) $data['expires_in'];
        } else {
            $expiresAt = 0;
        }
        $scope = $data['scope'] ?? null;

        return [
            'access_token'  => $data['access_token'],
            'refresh_token' => is_string($refresh) && $refresh !== '' ? $refresh : null,
            'expires_at'    => $expiresAt,
            'scope'         => is_string($scope) ? $scope : null,
        ];
    }

    /**
     * @param array{access_token: string, refresh_token: string|null, expires_at: int, scope: string|null} $data
     */
    private static function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    }
}
