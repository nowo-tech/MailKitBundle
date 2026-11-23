<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Closure;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_key_exists;
use function count;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function parse_str;
use function parse_url;
use function preg_match;
use function rawurldecode;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;

use const PHP_URL_PATH;
use const PHP_URL_QUERY;

/**
 * Simulated Microsoft Graph for delegated mailboxes (`/me/…`): the account, messages, attachments, delta pages per
 * folder (next links, then a delta link), subscriptions. Every request is recorded with its Authorization header; an
 * override answers first when it returns a response. Fixture data only (fake tokens, example.com addresses).
 */
final class MailboxGraphFake
{
    public const BASE = 'https://graph.microsoft.com/v1.0';

    public const ACCOUNT_ID = '00000000-0000-4000-8000-0000000000aa';

    /** @var list<array{method: string, url: string, path: string, authorization: string, body: array<mixed>}> */
    public array $requests = [];

    /** @var (Closure(string, string, array<mixed>): ?MockResponse)|null method, path (+ query), JSON body */
    public ?Closure $override = null;

    /** @var array<string, mixed> */
    public array $account = ['id' => self::ACCOUNT_ID, 'mail' => 'Ana@Example.com', 'userPrincipalName' => 'ana@example.com'];

    /** @var array<string, array<string, mixed>> message id => resource */
    public array $messages = [];

    /** @var array<string, list<mixed>> message id => attachment items (`_bytes`, `contentId` kept back) */
    public array $attachments = [];

    /** @var array<string, list<list<mixed>>> folder => pages (items only; links are added) */
    public array $delta = [];

    /** @var array<string, list<mixed>> folder => items returned for the delta link (later changes) */
    public array $after = [];

    /** @var list<array<mixed>> bodies of POST /subscriptions */
    public array $subscribed = [];

    /** @var list<string> */
    public array $unsubscribed = [];

    /** @var list<string> */
    public array $renewed = [];

    /** @var array<string, true> subscriptions Graph no longer knows */
    public array $goneSubscriptions = [];

    public function client(): MockHttpClient
    {
        return new MockHttpClient(fn (string $method, string $url, array $options): MockResponse => $this->answer($method, $url, $options));
    }

    /**
     * Requests whose path contains a fragment.
     *
     * @return list<array{method: string, url: string, path: string, authorization: string, body: array<mixed>}>
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
     * @param array<string, mixed> $resource
     *
     * @return array<string, mixed>
     */
    public static function message(string $id, array $resource = []): array
    {
        return $resource + [
            'id'                     => $id,
            'internetMessageId'      => '<' . $id . '@client.example.net>',
            'conversationId'         => 'conv-' . $id,
            'subject'                => 'Subject ' . $id,
            'from'                   => ['emailAddress' => ['address' => 'pedro@example.org', 'name' => 'Pedro']],
            'sender'                 => ['emailAddress' => ['address' => 'pedro@example.org', 'name' => 'Pedro']],
            'toRecipients'           => [['emailAddress' => ['address' => 'ana@example.com', 'name' => 'Ana']]],
            'ccRecipients'           => [],
            'bccRecipients'          => [],
            'replyTo'                => [],
            'receivedDateTime'       => '2026-11-20T10:00:00Z',
            'sentDateTime'           => '2026-11-20T09:59:00Z',
            'body'                   => ['contentType' => 'html', 'content' => '<p>Hello <b>Ana</b></p><img src="https://tracker.example.net/p.gif">'],
            'hasAttachments'         => false,
            'internetMessageHeaders' => [['name' => 'List-Id', 'value' => 'News <news.example.org>']],
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function answer(string $method, string $url, array $options): MockResponse
    {
        $authorization = '';
        foreach (is_array($options['headers'] ?? null) ? $options['headers'] : [] as $line) {
            if (is_string($line) && preg_match('/^authorization:\s*(.*)$/i', $line, $m) === 1) {
                $authorization = $m[1];
            }
        }
        $body             = is_string($options['body'] ?? null) && $options['body'] !== '' ? json_decode($options['body'], true) : [];
        $body             = is_array($body) ? $body : [];
        $path             = rawurldecode((string) parse_url($url, PHP_URL_PATH));
        $query            = (string) parse_url($url, PHP_URL_QUERY);
        $request          = ['method' => $method, 'url' => $url, 'path' => $path, 'authorization' => $authorization, 'body' => $body];
        $this->requests[] = $request;

        if ($this->override instanceof Closure) {
            $response = ($this->override)($method, $path . ($query !== '' ? '?' . $query : ''), $body);
            if ($response instanceof MockResponse) {
                return $response;
            }
        }
        if (!str_starts_with($url, self::BASE . '/')) {
            return self::json(['error' => ['code' => 'NotGraph']], 400);
        }
        $path = substr($path, strlen('/v1.0'));

        if ($method === 'GET' && $path === '/me') {
            return self::json($this->account);
        }
        if ($method === 'POST' && $path === '/subscriptions') {
            $this->subscribed[] = $body;

            return self::json(['id' => '11111111-0000-4000-8000-' . sprintf('%012d', count($this->subscribed)), 'expirationDateTime' => $body['expirationDateTime'] ?? null], 201);
        }
        if (preg_match('#^/subscriptions/([^/]+)$#', $path, $m) === 1) {
            if (isset($this->goneSubscriptions[$m[1]])) {
                return self::json(['error' => ['code' => 'ResourceNotFound']], 404);
            }
            if ($method === 'DELETE') {
                $this->unsubscribed[] = $m[1];

                return new MockResponse('', ['http_code' => 204]);
            }
            $this->renewed[] = $m[1];

            return self::json(['id' => $m[1], 'expirationDateTime' => $body['expirationDateTime'] ?? null]);
        }
        if ($method === 'GET' && preg_match('#^/(?:me|users/[^/]+|users\(\'[^\']+\'\))/mail[fF]olders(?:/([^/]+)|\(\'([^\']+)\'\))/messages/delta$#', $path, $m) === 1) {
            $folder = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
            parse_str($query, $params);
            $skip = is_string($params['$skiptoken'] ?? null) ? (int) $params['$skiptoken'] : 0;
            if (isset($params['$deltatoken'])) {
                return $this->deltaPage($folder, $this->after[$folder] ?? [], null);
            }
            $pages = $this->delta[$folder] ?? [[]];

            return $this->deltaPage($folder, $pages[$skip] ?? [], $skip + 1 < count($pages) ? $skip + 1 : null);
        }
        if ($method === 'GET' && preg_match('#^/me/messages/([^/]+)$#', $path, $m) === 1) {
            return array_key_exists($m[1], $this->messages) ? self::json($this->messages[$m[1]]) : self::json(['error' => ['code' => 'ErrorItemNotFound']], 404);
        }
        if ($method === 'GET' && preg_match('#^/me/messages/([^/]+)/attachments$#', $path, $m) === 1) {
            $list = [];
            foreach ($this->attachments[$m[1]] ?? [] as $item) {
                if (!is_array($item)) {
                    $list[] = $item;

                    continue;
                }
                unset($item['_bytes'], $item['contentId']);
                $list[] = $item;
            }

            return self::json(['value' => $list]);
        }
        if ($method === 'GET' && preg_match('#^/me/messages/([^/]+)/attachments/([^/]+?)(/\$value)?$#', $path, $m) === 1) {
            foreach ($this->attachments[$m[1]] ?? [] as $item) {
                if (is_array($item) && ($item['id'] ?? null) === $m[2]) {
                    if (($m[3] ?? '') !== '') {
                        return new MockResponse((string) ($item['_bytes'] ?? ''), ['http_code' => 200, 'response_headers' => ['content-type' => 'application/octet-stream']]);
                    }
                    unset($item['_bytes']);

                    return self::json($item);
                }
            }

            return self::json(['error' => ['code' => 'ErrorItemNotFound']], 404);
        }

        return self::json(['error' => ['code' => 'UnexpectedCall']], 400);
    }

    /**
     * @param list<mixed> $items
     */
    private function deltaPage(string $folder, array $items, ?int $next): MockResponse
    {
        $link = self::BASE . "/me/mailFolders('" . strtolower($folder) . "')/messages/delta?";

        return self::json(['value' => $items] + ($next !== null ? ['@odata.nextLink' => $link . '$skiptoken=' . $next] : ['@odata.deltaLink' => $link . '$deltatoken=done']));
    }

    /**
     * @param array<mixed> $data
     * @param array<string, string> $headers
     */
    public static function json(array $data, int $status = 200, array $headers = []): MockResponse
    {
        return new MockResponse((string) json_encode($data), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json'] + $headers]);
    }
}
