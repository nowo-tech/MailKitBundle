<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function hash;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function max;
use function min;
use function rawurlencode;
use function strcasecmp;
use function strlen;

/**
 * App-only access tokens for Microsoft Graph (1.8): OAuth 2.0 client credentials against the Entra token endpoint of
 * the mailbox's tenant (`https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token`, scope
 * `https://graph.microsoft.com/.default`).
 *
 * Tokens are cached in a PSR-6 pool (`nowo_mail_kit.graph.token_cache`, default `cache.app`) under a key derived from
 * the tenant, the client id and the SHA-256 of the secret, until five minutes before they expire; the expiry is also
 * stored and checked on read. The service holds no token, tenant or secret in a property (FrankenPHP worker mode,
 * kernel reset false): two mailboxes alternating on one worker each get their own token.
 *
 * Neither the secret nor the token ever reaches a log or an exception message; the token request goes through the
 * configured HTTP client (the decorated `http_client` by default, so it is counted by HttpLog with redacted bodies).
 */
final class GraphTokenProvider
{
    /** Entra ID (global cloud) authority. */
    public const AUTHORITY = 'https://login.microsoftonline.com';

    /** Scope of an app-only Graph token (the app's application permissions). */
    public const SCOPE = 'https://graph.microsoft.com/.default';

    /** Seconds before the real expiry when a cached token stops being used. */
    public const EXPIRY_MARGIN = 300;

    /** Longest accepted access token (JWTs of app tokens are a few KiB). */
    private const MAX_TOKEN_LENGTH = 16_384;

    /**
     * @param HttpClientInterface $client HTTP client (the decorated `http_client` by default)
     * @param CacheItemPoolInterface $cache token cache
     * @param float $timeout deadline of the token request, in seconds
     */
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly CacheItemPoolInterface $cache,
        private readonly float $timeout = 30.0,
        private readonly ?ClockInterface $clock = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * A valid access token for the mailbox's app registration (cached or freshly requested).
     *
     * @throws GraphException
     */
    public function token(GraphMailbox $mailbox): string
    {
        $now  = $this->now();
        $item = $this->cache->getItem($this->key($mailbox));
        if ($item->isHit()) {
            $cached = $item->get();
            if (is_array($cached) && is_string($cached['token'] ?? null) && is_int($cached['expires'] ?? null) && $cached['expires'] > $now) {
                return $cached['token'];
            }
        }

        [$token, $lifetime] = $this->request($mailbox);
        $ttl                = max(30, $lifetime - self::EXPIRY_MARGIN);
        $item->set(['token' => $token, 'expires' => $now + $ttl]);
        $item->expiresAfter($ttl);
        $this->cache->save($item);

        return $token;
    }

    /**
     * Drops the cached token (Graph answered 401: the token was revoked or the app's permissions changed).
     */
    public function forget(GraphMailbox $mailbox): void
    {
        $this->cache->deleteItem($this->key($mailbox));
    }

    /**
     * Cache key of a mailbox's token: tenant, client id and secret fingerprint, hashed (no identifier in clear).
     */
    public function key(GraphMailbox $mailbox): string
    {
        return 'nowo_mail_kit.graph_token.' . hash('sha256', $mailbox->tenantId . "\n" . $mailbox->clientId . "\n" . $mailbox->secret->fingerprint());
    }

    /**
     * @return array{0: string, 1: int} token and lifetime in seconds
     */
    private function request(GraphMailbox $mailbox): array
    {
        try {
            $response = $this->client->request('POST', self::AUTHORITY . '/' . rawurlencode($mailbox->tenantId) . '/oauth2/v2.0/token', [
                'headers' => ['Accept' => 'application/json'],
                'body'    => [
                    'client_id'     => $mailbox->clientId,
                    'client_secret' => $mailbox->secret->reveal(),
                    'scope'         => self::SCOPE,
                    'grant_type'    => 'client_credentials',
                ],
                'timeout'       => $this->timeout,
                'max_duration'  => $this->timeout * 2,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            $data   = $response->toArray(false);
        } catch (HttpClientException) {
            $this->logger?->warning('Microsoft Graph token request failed.', ['bundle' => 'NowoMailKitBundle', 'profile' => $mailbox->profile, 'error' => 'transport']);

            throw GraphException::transport('token request');
        }

        if ($status !== 200) {
            $code = is_string($data['error'] ?? null) ? $data['error'] : null;
            $this->logger?->warning('Microsoft Graph token request refused.', ['bundle' => 'NowoMailKitBundle', 'profile' => $mailbox->profile, 'status' => $status, 'code' => GraphException::safeToken($code)]);

            throw GraphException::status('token request', $status, $code, null);
        }

        $token     = $data['access_token'] ?? null;
        $type      = $data['token_type'] ?? 'Bearer';
        $expiresIn = $data['expires_in'] ?? null;
        if (!is_string($token) || $token === '' || strlen($token) > self::MAX_TOKEN_LENGTH || !is_string($type) || strcasecmp($type, 'Bearer') !== 0 || !is_numeric($expiresIn)) {
            throw new GraphException('Microsoft Graph token request returned no usable bearer token.', GraphException::REASON_AUTHENTICATION, $status);
        }

        return [$token, min(86_400, max(60, (int) $expiresIn))];
    }

    private function now(): int
    {
        return ($this->clock ?? new NativeClock())->now()->getTimestamp();
    }
}
