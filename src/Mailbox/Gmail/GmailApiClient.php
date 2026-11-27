<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Gmail;

use JsonException;
use Nowo\MailKitBundle\Mailbox\Engine\MailboxSession;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxProviderFailure;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

use function hrtime;
use function in_array;
use function is_array;
use function is_numeric;
use function json_decode;
use function strlen;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Thin client of the two Google endpoints a send-only Gmail mailbox needs (phase B2): the OpenID Connect userinfo (the
 * account check) and `messages.send` (media upload). Only these two fixed HTTPS URLs ever receive the host's token: no
 * other URL is accepted and redirects are off. Every call is reported to the host's observer (with Gmail quota units);
 * answers are capped; failures carry the operation, status and Google's error reason only: never the token, the body,
 * the URL or a previous exception (the request options hold the `Authorization` header and the message, so they are
 * sensitive parameters). Stateless (FrankenPHP worker mode): the token comes from the per-operation session.
 *
 * @internal
 */
final readonly class GmailApiClient
{
    /** OpenID Connect userinfo (scopes `openid email`): `sub`, `email`, `email_verified`. */
    public const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    /** `users.messages.send`, media upload (the raw RFC 5322 message, up to 35 MB). */
    public const SEND_URL = 'https://gmail.googleapis.com/upload/gmail/v1/users/me/messages/send?uploadType=media';

    /**
     * @param float $timeout idle deadline of one call, in seconds (`mailboxes.timeout`)
     */
    public function __construct(
        private HttpClientInterface $client,
        private float $timeout = 30.0,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * One request with the session's token. Any HTTP answer is returned (the caller maps the status); a transport
     * failure or an answer over `$maxBytes` throws.
     *
     * @param string $url {@see USERINFO_URL} or {@see SEND_URL}
     * @param array<string, mixed> $options HttpClient options (headers, body)
     * @param string $operation observer label (`account` or `send`)
     * @param int $quotaUnits Gmail quota units of the call
     *
     * @throws MailboxProviderFailure transport error (TRANSIENT, no status), answer too large (TOO_LARGE, with the status)
     *                                or another URL (REFUSED)
     *
     * @return array{status: int, data: array<mixed>, retryAfter: int|null}
     */
    public function request(MailboxSession $session, string $method, string $url, #[SensitiveParameter] array $options, string $operation, int $quotaUnits, int $maxBytes): array
    {
        if (!in_array($url, [self::USERINFO_URL, self::SEND_URL], true)) {
            throw new MailboxProviderFailure('Gmail ' . $operation . ': only the fixed Google endpoints are called.', MailboxProviderFailure::REFUSED);
        }
        $headers                  = is_array($options['headers'] ?? null) ? $options['headers'] : [];
        $headers['Authorization'] = 'Bearer ' . $session->token()->reveal();
        $headers['Accept']        = 'application/json';
        $started                  = hrtime(true);
        $status                   = 0;
        $size                     = 0;

        try {
            $response = $this->client->request($method, $url, ['headers' => $headers] + $options + [
                'timeout'       => $this->timeout,
                'max_duration'  => $this->timeout * 4,
                'max_redirects' => 0,
            ]);
            $status  = $response->getStatusCode();
            $content = '';
            foreach ($this->client->stream($response) as $chunk) {
                $piece = $chunk->getContent();
                $size += strlen($piece);
                if ($size > $maxBytes) {
                    $response->cancel();
                    $session->called($operation, $status, $this->elapsedMs($started), $size, null, 1, $quotaUnits);

                    throw new MailboxProviderFailure('Gmail ' . $operation . ' answer exceeds the size limit.', MailboxProviderFailure::TOO_LARGE, $status);
                }
                $content .= $piece;
            }
            $retryAfter = $response->getHeaders(false)['retry-after'][0] ?? null;
        } catch (MailboxProviderFailure $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            // The client's frames hold the options (token, message): replaced, never chained (audit ZAT-L5 rule).
            $session->called($operation, 0, $this->elapsedMs($started), 0, null, 1, $quotaUnits);
            $this->logger?->warning('Gmail call failed.', ['bundle' => 'NowoMailKitBundle', 'mailbox' => $session->mailbox->value, 'operation' => $operation, 'error' => 'transport', 'exception' => $failure::class]);

            throw new MailboxProviderFailure('Gmail ' . $operation . ' failed (transport error or timeout).', MailboxProviderFailure::TRANSIENT);
        }
        $retryAfter = is_numeric($retryAfter) ? (int) $retryAfter : null;
        $session->called($operation, $status, $this->elapsedMs($started), $size, $retryAfter, 1, $quotaUnits);

        return ['status' => $status, 'data' => $this->decode($content), 'retryAfter' => $retryAfter];
    }

    /**
     * The JSON object of an answer, or an empty array.
     *
     * @return array<mixed>
     */
    private function decode(string $content): array
    {
        if (trim($content) === '') {
            return [];
        }

        try {
            $decoded = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Milliseconds since an hrtime() mark.
     */
    private function elapsedMs(int|float $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
