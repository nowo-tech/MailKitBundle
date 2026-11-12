<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph;

use JsonException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function in_array;
use function sprintf;
use function ftell;
use function fwrite;
use function is_array;
use function is_numeric;
use function is_string;
use function parse_url;
use function preg_match;
use function rawurlencode;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Thin Microsoft Graph v1.0 client (1.8, internal): bearer token from {@see GraphTokenProvider} (one retry with a fresh
 * token on 401), no redirects, a deadline per call and errors mapped to {@see GraphException} (status, Graph error code
 * and request id only — never the body or the URL).
 *
 * Only `https://graph.microsoft.com` is called with a token; upload sessions (`PUT` of attachment chunks) go only to
 * the configured upload hosts, over HTTPS, and never carry the token (the upload URL is pre-authenticated).
 *
 * @internal
 */
final class GraphApiClient
{
    /** Graph v1.0 endpoint. */
    public const BASE_URL = 'https://graph.microsoft.com/v1.0';

    /** The only host that receives a Graph bearer token. */
    public const GRAPH_HOST = 'graph.microsoft.com';

    /** Largest JSON answer read by default (16 MiB). */
    public const MAX_RESPONSE_BYTES = 16_777_216;

    /** Hosts of attachment upload sessions (Outlook REST). */
    public const DEFAULT_UPLOAD_HOSTS = ['outlook.office.com', 'outlook.office365.com'];

    /**
     * @param list<string> $uploadHosts hosts accepted for upload session URLs (exact, case-insensitive)
     * @param float $timeout idle deadline of one call, in seconds
     */
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly GraphTokenProvider $tokens,
        private readonly array $uploadHosts = self::DEFAULT_UPLOAD_HOSTS,
        private readonly float $timeout = 30.0,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Graph path of a mailbox (`/users/{address}`), URL-encoded.
     */
    public static function userPath(GraphMailbox $mailbox): string
    {
        return '/users/' . rawurlencode($mailbox->address);
    }

    /**
     * Calls Graph and returns the decoded JSON body (an empty array for an empty body).
     *
     * @param string $path path under /v1.0, starting with "/"
     * @param array<string, mixed> $options HttpClient options (json, body, headers)
     * @param list<int> $expected accepted statuses
     * @param string $operation fixed label for errors and logs ("create draft", "send"...)
     * @param int $maxBytes largest response body read (a longer one fails with {@see GraphException::REASON_TOO_LARGE})
     *
     * @throws GraphException
     *
     * @return array<mixed>
     */
    public function call(GraphMailbox $mailbox, string $method, string $path, array $options, array $expected, string $operation, int $maxBytes = self::MAX_RESPONSE_BYTES): array
    {
        if (!str_starts_with($path, '/')) {
            throw GraphException::untrustedUrl($operation);
        }

        return $this->authorized($mailbox, $method, self::BASE_URL . $path, $options, $expected, $operation, $maxBytes, null);
    }

    /**
     * Calls an absolute URL returned by Graph (`@odata.nextLink`, `@odata.deltaLink`) after {@see assertGraphUrl()}.
     *
     * @param array<string, mixed> $options
     * @param list<int> $expected
     *
     * @throws GraphException
     *
     * @return array<mixed>
     */
    public function callUrl(GraphMailbox $mailbox, string $method, string $url, array $options, array $expected, string $operation, int $maxBytes = self::MAX_RESPONSE_BYTES): array
    {
        $this->assertGraphUrl($url, $operation);

        return $this->authorized($mailbox, $method, $url, $options, $expected, $operation, $maxBytes, null);
    }

    /**
     * Streams a 2xx response body (e.g. an attachment's `$value`) into `$sink`, at most `$maxBytes` (a longer body
     * fails with {@see GraphException::REASON_TOO_LARGE}); returns the bytes written.
     *
     * @param resource $sink writable stream
     *
     * @throws GraphException
     */
    public function download(GraphMailbox $mailbox, string $path, mixed $sink, int $maxBytes, string $operation): int
    {
        if (!str_starts_with($path, '/')) {
            throw GraphException::untrustedUrl($operation);
        }
        $start = (int) ftell($sink);
        $this->authorized($mailbox, 'GET', self::BASE_URL . $path, ['headers' => ['Accept' => '*/*']], [200], $operation, $maxBytes, $sink);

        return (int) ftell($sink) - $start;
    }

    /**
     * Refuses a URL that is not `https://graph.microsoft.com/v1.0/…` (port 443, no user info): links in Graph answers
     * are followed only on Graph itself, so the bearer token never leaves it (SSRF guard).
     *
     * @throws GraphException
     */
    public function assertGraphUrl(string $url, string $operation = 'link'): void
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || strtolower($parts['host'] ?? '') !== self::GRAPH_HOST
            || !str_starts_with($parts['path'] ?? '', '/v1.0/')
            || isset($parts['fragment'])
        ) {
            throw GraphException::untrustedUrl($operation);
        }
    }

    /**
     * @param array<string, mixed> $options
     * @param list<int> $expected
     * @param resource|null $sink
     *
     * @return array<mixed>
     */
    private function authorized(GraphMailbox $mailbox, string $method, string $url, array $options, array $expected, string $operation, int $maxBytes, mixed $sink): array
    {
        for ($attempt = 0;; ++$attempt) {
            $headers                  = is_array($options['headers'] ?? null) ? $options['headers'] : [];
            $headers['Authorization'] = 'Bearer ' . $this->tokens->token($mailbox);
            $headers['Accept'] ??= 'application/json';
            [$status, $data, $requestId, $retryAfter] = $this->send($method, $url, ['headers' => $headers] + $options, $operation, $mailbox, $maxBytes, $sink);
            if ($status === 401 && $attempt === 0) {
                $this->tokens->forget($mailbox);

                continue;
            }
            if (in_array($status, $expected, true)) {
                return $data;
            }
            $error = is_array($data['error'] ?? null) ? $data['error'] : [];
            $code  = is_string($error['code'] ?? null) ? $error['code'] : null;
            $this->logger?->warning('Microsoft Graph call failed.', [
                'bundle'     => 'NowoMailKitBundle',
                'profile'    => $mailbox->profile,
                'operation'  => $operation,
                'status'     => $status,
                'code'       => GraphException::safeToken($code),
                'request_id' => GraphException::safeToken($requestId),
            ]);

            throw GraphException::status($operation, $status, $code, $requestId, $retryAfter);
        }
    }

    /**
     * PUTs one chunk to an upload session URL (no Authorization header) and returns the status (200 or 201).
     *
     * @throws GraphException
     */
    public function uploadChunk(GraphMailbox $mailbox, string $uploadUrl, string $chunk, int $start, int $total): int
    {
        $this->assertUploadUrl($uploadUrl);
        $end                                      = $start + strlen($chunk) - 1;
        [$status, $data, $requestId, $retryAfter] = $this->send('PUT', $uploadUrl, [
            'headers' => [
                'Content-Type'  => 'application/octet-stream',
                'Content-Range' => 'bytes ' . $start . '-' . $end . '/' . $total,
            ],
            'body' => $chunk,
        ], 'attachment upload', $mailbox);
        if ($status === 200 || $status === 201) {
            return $status;
        }
        $error = is_array($data['error'] ?? null) ? $data['error'] : [];
        $code  = is_string($error['code'] ?? null) ? $error['code'] : null;

        throw GraphException::status('attachment upload', $status, $code, $requestId, $retryAfter);
    }

    /**
     * Cancels an upload session (best effort, errors ignored).
     */
    public function cancelUpload(GraphMailbox $mailbox, string $uploadUrl): void
    {
        try {
            $this->assertUploadUrl($uploadUrl);
            $this->send('DELETE', $uploadUrl, [], 'attachment upload cancel', $mailbox);
        } catch (GraphException) {
            // Best effort: the session expires by itself.
        }
    }

    /**
     * Refuses an upload URL outside HTTPS and the configured upload hosts (SSRF guard: the URL comes from Graph's answer).
     *
     * @throws GraphException
     */
    public function assertUploadUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || !$this->defaultHttpsPort($url, $parts)
            || !in_array($this->host($parts['host'] ?? ''), $this->uploadHosts, true)
        ) {
            throw GraphException::untrustedUrl('upload session');
        }
    }

    /**
     * True when the URL has no port or the literal ":443" (`:0443` and other spellings are refused, audit NK-4).
     *
     * @param array<string, mixed> $parts parse_url() result
     */
    private function defaultHttpsPort(string $url, array $parts): bool
    {
        if (!isset($parts['port'])) {
            return true;
        }

        return $parts['port'] === 443 && preg_match('#^https://[^/?\#]*:443(?:[/?\#]|\z)#i', $url) === 1;
    }

    /**
     * Lower-cased host with at most one trailing dot removed; '' for a leading dot or an empty label (audit NK-4).
     */
    private function host(string $host): string
    {
        $host = strtolower($host);
        if (str_ends_with($host, '.')) {
            $host = substr($host, 0, -1);
        }

        return $host === '' || str_starts_with($host, '.') || str_contains($host, '..') || str_ends_with($host, '.') ? '' : $host;
    }

    /**
     * @param array<string, mixed> $options
     * @param resource|null $sink receives a 2xx body instead of the JSON decoding
     *
     * @return array{0: int, 1: array<mixed>, 2: string|null, 3: int|null} status, JSON body, request id, Retry-After
     */
    private function send(string $method, string $url, array $options, string $operation, GraphMailbox $mailbox, int $maxBytes = self::MAX_RESPONSE_BYTES, mixed $sink = null): array
    {
        try {
            $response = $this->client->request($method, $url, $options + [
                'timeout'       => $this->timeout,
                'max_duration'  => $this->timeout * 4,
                'max_redirects' => 0,
            ]);
            $status  = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $toSink  = $sink !== null && $status >= 200 && $status < 300;
            $content = '';
            $size    = 0;
            foreach ($this->client->stream($response) as $chunk) {
                $piece = $chunk->getContent();
                $size += strlen($piece);
                if ($size > $maxBytes) {
                    $response->cancel();

                    throw new GraphException(sprintf('Microsoft Graph %s answer exceeds the size limit (%d bytes).', $operation, $maxBytes), GraphException::REASON_TOO_LARGE, $status);
                }
                if ($toSink) {
                    fwrite($sink, $piece);
                } else {
                    $content .= $piece;
                }
            }
        } catch (HttpClientException) {
            $this->logger?->warning('Microsoft Graph call failed.', ['bundle' => 'NowoMailKitBundle', 'profile' => $mailbox->profile, 'operation' => $operation, 'error' => 'transport']);

            throw GraphException::transport($operation);
        }

        $data = [];
        if (trim($content) !== '') {
            try {
                $decoded = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
                $data    = is_array($decoded) ? $decoded : [];
            } catch (JsonException) {
                $data = [];
            }
        }
        $requestId  = $headers['request-id'][0] ?? $headers['client-request-id'][0] ?? null;
        $retryAfter = $headers['retry-after'][0] ?? null;

        return [$status, $data, $requestId, is_numeric($retryAfter) ? (int) $retryAfter : null];
    }
}
