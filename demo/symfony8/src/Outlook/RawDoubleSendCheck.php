<?php

declare(strict_types=1);

namespace App\Outlook;

use Closure;
use Nowo\MailKitBundle\Mailbox\Port\MailboxTokenProviderInterface;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function is_array;
use function is_bool;
use function is_string;
use function json_decode;
use function rawurlencode;
use function usleep;

/**
 * DEMO ONLY (Outlook manual test harness), guide check ZAV-L1: does Microsoft refuse a second
 * `POST /me/messages/{id}/send` of the same draft? MailKit's reconcile relies on it (it re-submits a draft only while
 * Graph still reports `isDraft: true`). Outside MailKit on purpose: raw Graph calls with the mailbox token.
 *
 * Steps: create a text draft (immutable id) → first send (expect 202) → read `isDraft` (expect false) → second send
 * (expect 4xx). The result tells whether the second POST was refused; the recipient must also see one copy only.
 */
final class RawDoubleSendCheck
{
    private const GRAPH = 'https://graph.microsoft.com/v1.0';

    /**
     * @param Closure(int): void|null $sleep microseconds sleeper (tests pass a no-op)
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly MailboxTokenProviderInterface $tokens,
        private readonly ?Closure $sleep = null,
    ) {
    }

    /**
     * @return array{draft_id: string, first_status: int, is_draft_after_first: bool|null, second_status: int, second_error: string|null, refused: bool}
     */
    public function run(MailboxId $mailbox, string $to, string $subject): array
    {
        $draft = $this->call($mailbox, 'POST', '/me/messages', [
            'json' => [
                'subject'      => $subject,
                'body'         => ['contentType' => 'Text', 'content' => 'MailKit 2.5.0 manual test (ZAV-L1): this draft is submitted twice; you should receive it once.'],
                'toRecipients' => [['emailAddress' => ['address' => $to]]],
            ],
        ]);
        if ($draft['status'] !== 201 || !is_string($draft['data']['id'] ?? null)) {
            throw new RuntimeException('Creating the draft failed: HTTP ' . $draft['status'] . ' ' . self::error($draft['data']));
        }
        $id   = $draft['data']['id'];
        $path = '/me/messages/' . rawurlencode($id);

        $first = $this->call($mailbox, 'POST', $path . '/send', []);
        if ($first['status'] !== 202) {
            throw new RuntimeException('The first send failed: HTTP ' . $first['status'] . ' ' . self::error($first['data']));
        }

        $isDraft = null;
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $read = $this->call($mailbox, 'GET', $path . '?$select=isDraft', []);
            if ($read['status'] === 200 && is_bool($read['data']['isDraft'] ?? null)) {
                $isDraft = $read['data']['isDraft'];
                if ($isDraft === false) {
                    break;
                }
            }
            ($this->sleep ?? static fn (int $us) => usleep($us))(1_000_000);
        }

        $second = $this->call($mailbox, 'POST', $path . '/send', []);

        return [
            'draft_id'             => $id,
            'first_status'         => $first['status'],
            'is_draft_after_first' => $isDraft,
            'second_status'        => $second['status'],
            'second_error'         => $second['status'] >= 400 ? self::error($second['data']) : null,
            'refused'              => $second['status'] >= 400 && $second['status'] < 500,
        ];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{status: int, data: array<mixed>}
     */
    private function call(MailboxId $mailbox, string $method, string $path, array $options): array
    {
        $token                    = $this->tokens->accessToken($mailbox);
        $options['headers']       = ['Authorization' => 'Bearer ' . $token->reveal(), 'Prefer' => 'IdType="ImmutableId"'];
        $options['timeout']       = 30;
        $options['max_redirects'] = 0;

        try {
            $response = $this->httpClient->request($method, self::GRAPH . $path, $options);
            $status   = $response->getStatusCode();
            $content  = $response->getContent(false);
        } catch (ExceptionInterface $exception) {
            throw new RuntimeException('Graph unreachable (' . $exception::class . ').');
        }
        $data = $content !== '' ? json_decode($content, true) : [];

        return ['status' => $status, 'data' => is_array($data) ? $data : []];
    }

    /**
     * Graph error code and message only.
     *
     * @param array<mixed> $data
     */
    private static function error(array $data): string
    {
        $error = $data['error'] ?? null;
        if (!is_array($error)) {
            return '';
        }
        $code    = $error['code'] ?? '';
        $message = $error['message'] ?? '';

        return (is_string($code) ? $code : '') . (is_string($message) && $message !== '' ? ': ' . $message : '');
    }
}
