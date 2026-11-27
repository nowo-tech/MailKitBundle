<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Closure;
use DateTimeImmutable;
use Nowo\MailKitBundle\Mailbox\Gmail\GmailApiClient;
use Nowo\MailKitBundle\Mailbox\Gmail\GmailScopes;
use Nowo\MailKitBundle\Mailbox\Value\MailboxAccessToken;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function count;
use function is_array;
use function is_string;
use function json_encode;
use function preg_match;
use function sprintf;

/**
 * Simulated Google endpoints of a send-only Gmail mailbox (phase B2): the OpenID Connect userinfo and the
 * `messages.send` media upload. Every request is recorded with its Authorization header and raw body; an override
 * answers first when it returns a response. Fixture data only: fake tokens built at runtime, example.com addresses, a
 * made-up account id; nothing recorded from Google.
 */
final class MailboxGmailFake
{
    /** Made-up Google account id (`sub`). */
    public const SUB = '100000000000000000042';

    /** @var list<array{method: string, url: string, authorization: string, contentType: string, raw: string}> */
    public array $requests = [];

    /** @var (Closure(string, string, string): ?MockResponse)|null method, URL, raw body */
    public ?Closure $override = null;

    /** @var array<string, mixed> */
    public array $userinfo = ['sub' => self::SUB, 'email' => 'Ana@Example.com', 'email_verified' => true];

    /** @var list<string> raw messages Gmail accepted, in order */
    public array $sent = [];

    /**
     * A fake access token of a Gmail mailbox (built at runtime; never a real one).
     *
     * @param list<string> $scopes
     */
    public static function token(string $suffix = '1', array $scopes = ['openid', 'https://www.googleapis.com/auth/userinfo.email', GmailScopes::SEND]): MailboxAccessToken
    {
        return new MailboxAccessToken('fake-gmail-' . $suffix, new DateTimeImmutable('+1 hour'), $scopes);
    }

    public function client(): MockHttpClient
    {
        return new MockHttpClient(fn (string $method, string $url, array $options): MockResponse => $this->answer($method, $url, $options));
    }

    /**
     * Requests to one of the two endpoints.
     *
     * @return list<array{method: string, url: string, authorization: string, contentType: string, raw: string}>
     */
    public function to(string $url): array
    {
        $list = [];
        foreach ($this->requests as $request) {
            if ($request['url'] === $url) {
                $list[] = $request;
            }
        }

        return $list;
    }

    /**
     * @param array<mixed> $data
     * @param array<string, string> $headers
     */
    public static function json(array $data, int $status = 200, array $headers = []): MockResponse
    {
        return new MockResponse((string) json_encode($data), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json'] + $headers]);
    }

    /**
     * A Google error answer.
     */
    public static function error(int $status, string $reason, string $rpcStatus = 'FAILED_PRECONDITION'): MockResponse
    {
        return self::json(['error' => ['code' => $status, 'message' => 'Fixture error.', 'errors' => [['reason' => $reason, 'domain' => 'global']], 'status' => $rpcStatus]], $status);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function answer(string $method, string $url, array $options): MockResponse
    {
        $authorization = '';
        $contentType   = '';
        foreach (is_array($options['headers'] ?? null) ? $options['headers'] : [] as $line) {
            if (is_string($line) && preg_match('/^authorization:\s*(.*)$/i', $line, $m) === 1) {
                $authorization = $m[1];
            }
            if (is_string($line) && preg_match('/^content-type:\s*(.*)$/i', $line, $m) === 1) {
                $contentType = $m[1];
            }
        }
        $raw              = is_string($options['body'] ?? null) ? $options['body'] : '';
        $this->requests[] = ['method' => $method, 'url' => $url, 'authorization' => $authorization, 'contentType' => $contentType, 'raw' => $raw];
        if ($this->override instanceof Closure) {
            $response = ($this->override)($method, $url, $raw);
            if ($response instanceof MockResponse) {
                return $response;
            }
        }
        if ($method === 'GET' && $url === GmailApiClient::USERINFO_URL) {
            return self::json($this->userinfo);
        }
        if ($method === 'POST' && $url === GmailApiClient::SEND_URL) {
            $this->sent[] = $raw;
            $n            = count($this->sent);

            return self::json(['id' => sprintf('18c%013d', $n), 'threadId' => sprintf('18c%013d', $n), 'labelIds' => ['SENT']]);
        }

        return self::json(['error' => ['code' => 404, 'status' => 'NOT_FOUND']], 404);
    }
}
