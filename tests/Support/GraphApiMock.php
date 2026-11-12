<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Closure;
use Nowo\MailKitBundle\Graph\GraphMailbox;
use Nowo\MailKitBundle\Graph\GraphSecret;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_shift;
use function base64_decode;
use function base64_encode;
use function count;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function parse_str;
use function preg_match;
use function preg_replace;
use function rawurldecode;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strtolower;

/**
 * Simulated Microsoft Graph + Entra token endpoint for MockHttpClient: tokens per tenant (`tok-<tenant>-<n>`), drafts
 * from base64 MIME (internetMessageId from the MIME Message-ID), attachments, upload sessions on
 * outlook.office.com, send. Every request is recorded; an override closure answers first when it returns a response.
 * Fixture identifiers only (no real tenant, app, secret or mailbox).
 */
final class GraphApiMock
{
    public const TENANT_A = '00000000-0000-4000-8000-00000000000a';

    public const TENANT_B = '00000000-0000-4000-8000-00000000000b';

    public const CLIENT_A = '00000000-0000-4000-8000-0000000000c1';

    public const CLIENT_B = '00000000-0000-4000-8000-0000000000c2';

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string}> */
    public array $requests = [];

    /** Tokens issued so far. */
    public int $tokensIssued = 0;

    /** @var array<string, string> mailbox address (lower-case) => tenant whose token may access it */
    public array $mailboxTenants = ['projects@example.com' => self::TENANT_A, 'support@example.org' => self::TENANT_B];

    /** @var array<string, string> token => tenant */
    private array $tokens = [];

    private int $drafts = 0;

    /** @var array<string, int> upload URL => next expected byte */
    private array $uploadOffsets = [];

    /** @var (Closure(string, string, array<string, string>, string): ?MockResponse)|null */
    public ?Closure $override = null;

    /** @var list<bool|null> isDraft answers consumed one per read before falling back to */
    public array $draftStates = [];

    /** @var array<string, array<string, array<string, mixed>>> mailbox => Graph id => message resource (+ `_attachments`) */
    public array $inbox = [];

    /** @var list<array<mixed>> bodies of POST /subscriptions */
    public array $subscriptionRequests = [];

    private int $subscriptionCount = 0;

    /** isDraft reported by GET …/messages/{id}?$select=isDraft (null = answer 500). */
    public ?bool $draftState = true;

    /** internetMessageId Graph reports for drafts (null = the MIME Message-ID). */
    public ?string $reportedMessageId = null;

    public static function secret(string $suffix = 'a'): string
    {
        return 'REDACTED-TEST-graph-secret-' . $suffix;
    }

    public static function mailboxA(string $secretSuffix = 'a'): GraphMailbox
    {
        return new GraphMailbox('acme', self::TENANT_A, self::CLIENT_A, new GraphSecret(self::secret($secretSuffix)), 'projects@example.com');
    }

    public static function mailboxB(): GraphMailbox
    {
        return new GraphMailbox('globex', self::TENANT_B, self::CLIENT_B, new GraphSecret(self::secret('b')), 'support@example.org');
    }

    public function client(): MockHttpClient
    {
        return new MockHttpClient(fn (string $method, string $url, array $options): MockResponse => $this->answer($method, $url, $options));
    }

    /**
     * Requests whose URL contains a fragment.
     *
     * @return list<array{method: string, url: string, headers: array<string, string>, body: string}>
     */
    public function matching(string $fragment, ?string $method = null): array
    {
        $list = [];
        foreach ($this->requests as $request) {
            if (str_contains($request['url'], $fragment) && ($method === null || $request['method'] === $method)) {
                $list[] = $request;
            }
        }

        return $list;
    }

    /**
     * Decoded MIME of the n-th draft.
     */
    public function draftMime(int $index = 0): string
    {
        $drafts = [];
        foreach ($this->requests as $request) {
            if ($request['method'] === 'POST' && preg_match('#/users/[^/]+/messages$#', $request['url']) === 1) {
                $drafts[] = (string) base64_decode($request['body'], true);
            }
        }

        return $drafts[$index] ?? '';
    }

    /**
     * @param array<string, mixed> $options
     */
    private function answer(string $method, string $url, array $options): MockResponse
    {
        $headers = [];
        foreach (is_array($options['headers'] ?? null) ? $options['headers'] : [] as $line) {
            if (is_string($line) && preg_match('/^([^:]+):\s*(.*)$/', $line, $m) === 1) {
                $headers[strtolower($m[1])] = $m[2];
            }
        }
        $body = $options['body'] ?? '';
        if (!is_string($body)) {
            $chunks = '';
            foreach ($body as $piece) {
                $chunks .= $piece;
            }
            $body = $chunks;
        }
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        if ($this->override instanceof Closure) {
            $response = ($this->override)($method, $url, $headers, $body);
            if ($response !== null) {
                return $response;
            }
        }

        if (preg_match('#^https://login\.microsoftonline\.com/([^/]+)/oauth2/v2\.0/token$#', $url, $m) === 1) {
            parse_str($body, $form);
            $secret = is_string($form['client_secret'] ?? null) ? $form['client_secret'] : '';
            if (!str_starts_with($secret, 'REDACTED-TEST-')) {
                return self::json(['error' => 'invalid_client', 'error_description' => 'AADSTS7000215: Invalid client secret ' . $secret], 401);
            }
            ++$this->tokensIssued;
            $token                = 'tok-' . rawurldecode($m[1]) . '-' . $this->tokensIssued;
            $this->tokens[$token] = rawurldecode($m[1]);

            return self::json(['token_type' => 'Bearer', 'expires_in' => 3599, 'access_token' => $token]);
        }

        if (str_starts_with($url, 'https://outlook.office.com/upload/')) {
            if (isset($headers['authorization'])) {
                return self::json(['error' => ['code' => 'TokenOnUploadUrl']], 400);
            }
            if ($method === 'DELETE') {
                return new MockResponse('', ['http_code' => 204]);
            }
            preg_match('/bytes (\d+)-(\d+)\/(\d+)/', $headers['content-range'] ?? '', $range);
            $start    = (int) ($range[1] ?? -1);
            $end      = (int) ($range[2] ?? 0);
            $total    = (int) ($range[3] ?? 0);
            $expected = $this->uploadOffsets[$url] ?? 0;
            if ($start !== $expected || $end + 1 !== $start + strlen($body)) {
                return self::json(['error' => ['code' => 'InvalidRange']], 400);
            }
            $this->uploadOffsets[$url] = $end + 1;

            return $end + 1 >= $total
                ? new MockResponse('', ['http_code' => 201, 'response_headers' => ['Location' => 'https://outlook.office.com/api/v2.0/attachments/1']])
                : self::json(['nextExpectedRanges' => [($end + 1) . '-']]);
        }

        if (preg_match('#^https://graph\.microsoft\.com/v1\.0/subscriptions(?:/([^/?]+))?$#', $url, $m) === 1) {
            $token = (string) preg_replace('/^Bearer /', '', $headers['authorization'] ?? '');
            if (!isset($this->tokens[$token])) {
                return self::json(['error' => ['code' => 'InvalidAuthenticationToken']], 401);
            }
            $sub = isset($m[1]) ? rawurldecode($m[1]) : null;
            if ($method === 'POST' && $sub === null) {
                ++$this->subscriptionCount;
                $payload                      = json_decode($body, true);
                $this->subscriptionRequests[] = is_array($payload) ? $payload : [];

                return self::json(['id' => 'sub-' . $this->subscriptionCount . '-0000', 'expirationDateTime' => is_array($payload) ? ($payload['expirationDateTime'] ?? null) : null], 201);
            }
            if ($method === 'PATCH' && $sub !== null) {
                if (!str_starts_with($sub, 'sub-')) {
                    return self::json(['error' => ['code' => 'ResourceNotFound']], 404);
                }
                $payload = json_decode($body, true);

                return self::json(['id' => $sub, 'expirationDateTime' => is_array($payload) ? ($payload['expirationDateTime'] ?? null) : null]);
            }
            if ($method === 'DELETE' && $sub !== null) {
                return new MockResponse('', ['http_code' => 204]);
            }

            return self::json(['error' => ['code' => 'NotSupported']], 400);
        }

        if (preg_match('#^https://graph\.microsoft\.com/v1\.0/users/([^/]+)(/.*)?$#', $url, $m) !== 1) {
            return self::json(['error' => ['code' => 'NotFound']], 404);
        }
        $mailbox = rawurldecode($m[1]);
        $path    = $m[2] ?? '';
        $token   = (string) preg_replace('/^Bearer /', '', $headers['authorization'] ?? '');
        if (!isset($this->tokens[$token]) || ($this->mailboxTenants[$mailbox] ?? null) !== $this->tokens[$token]) {
            return self::json(['error' => ['code' => 'InvalidAuthenticationToken']], 401, ['request-id' => 'req-401']);
        }

        if ($method === 'POST' && $path === '/messages') {
            ++$this->drafts;
            $mime = (string) base64_decode($body, true);
            preg_match('/^Message-ID: (<[^>]+>)/mi', $mime, $id);

            return self::json([
                'id'                => 'draft-' . $mailbox . '-' . $this->drafts,
                'internetMessageId' => $this->reportedMessageId ?? ($id[1] ?? ''),
                'conversationId'    => 'conv-' . $this->drafts,
                'isDraft'           => true,
            ], 201);
        }
        if ($method === 'GET' && preg_match('#^/messages/[^/?]+\?\$select=isDraft$#', $path) === 1) {
            if ($this->draftStates !== []) {
                $this->draftState = array_shift($this->draftStates);
            }

            return $this->draftState === null ? self::json(['error' => ['code' => 'ErrorInternalServerError']], 500) : self::json(['isDraft' => $this->draftState]);
        }
        if ($method === 'GET' && preg_match('#^/messages/([^/?]+)(?:/attachments(?:/([^/?]+)(/\$value)?)?)?(?:\?.*)?$#', $path, $parts) === 1) {
            $id      = rawurldecode($parts[1]);
            $message = $this->inbox[$mailbox][$id] ?? null;
            if ($message === null) {
                return self::json(['error' => ['code' => 'ErrorItemNotFound']], 404);
            }
            $attachments = $message['_attachments'] ?? [];
            unset($message['_attachments']);
            if (!str_contains($path, '/attachments')) {
                return self::json($message);
            }
            if (!isset($parts[2]) || $parts[2] === '') {
                $list = [];
                foreach ($attachments as $attachment) {
                    if (is_array($attachment)) {
                        unset($attachment['_bytes'], $attachment['contentId']);
                    }
                    $list[] = $attachment;
                }

                return self::json(['value' => $list]);
            }
            foreach ($attachments as $attachment) {
                if (is_array($attachment) && ($attachment['id'] ?? null) === rawurldecode($parts[2])) {
                    if (isset($parts[3])) {
                        return new MockResponse((string) ($attachment['_bytes'] ?? ''), ['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/octet-stream']]);
                    }
                    $attachment['contentBytes'] ??= base64_encode((string) ($attachment['_bytes'] ?? ''));
                    unset($attachment['_bytes']);

                    return self::json($attachment);
                }
            }

            return self::json(['error' => ['code' => 'ErrorItemNotFound']], 404);
        }
        if ($method === 'PATCH' && preg_match('#^/messages/[^/]+$#', $path) === 1) {
            return self::json(['id' => 'x'], 200);
        }
        if ($method === 'POST' && preg_match('#^/messages/[^/]+/attachments$#', $path) === 1) {
            return self::json(['id' => 'att-' . count($this->requests)], 201);
        }
        if ($method === 'POST' && preg_match('#^/messages/[^/]+/attachments/createUploadSession$#', $path) === 1) {
            return self::json(['uploadUrl' => 'https://outlook.office.com/upload/session-' . count($this->requests) . '?authtoken=REDACTED-TEST', 'nextExpectedRanges' => ['0-']], 201);
        }
        if ($method === 'POST' && preg_match('#^/messages/[^/]+/send$#', $path) === 1) {
            return new MockResponse('', ['http_code' => 202]);
        }
        if ($method === 'DELETE' && preg_match('#^/messages/[^/]+$#', $path) === 1) {
            return new MockResponse('', ['http_code' => 204]);
        }

        return self::json(['error' => ['code' => 'NotSupported']], 400);
    }

    /**
     * @param array<mixed> $data
     * @param array<string, string> $headers
     */
    public static function json(array $data, int $status = 200, array $headers = []): MockResponse
    {
        return new MockResponse((string) json_encode($data), ['http_code' => $status, 'response_headers' => ['Content-Type' => 'application/json'] + $headers]);
    }
}
