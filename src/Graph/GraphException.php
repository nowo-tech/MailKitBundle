<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph;

use RuntimeException;

use function preg_match;
use function sprintf;

/**
 * Failure of a Microsoft Graph call (1.8). The message is built by MailKit from fixed text plus a short reason, the
 * HTTP status, the Graph / Entra error code and the `request-id` — never the response body, a URL (the upload URL
 * carries a token), a token, the client secret or message content. The original exception is never kept as
 * `previous`, so the chain is safe in logs, flashes and the Messenger failure transport.
 */
final class GraphException extends RuntimeException
{
    /** The mailbox profile is unknown or its credentials are invalid. */
    public const REASON_CONFIGURATION = 'configuration';

    /** The token endpoint refused the client credentials (or answered without a token). */
    public const REASON_AUTHENTICATION = 'authentication';

    /** Graph answered with an error status. */
    public const REASON_API = 'api';

    /** 429 / 503: retry later (see {@see $retryAfter}). */
    public const REASON_THROTTLED = 'throttled';

    /** Network failure, timeout or an unreadable answer. */
    public const REASON_TRANSPORT = 'transport';

    /** The message breaks a configured limit or the MIME / attachment rules. */
    public const REASON_INVALID_MESSAGE = 'invalid_message';

    /** Graph returned a link outside the allowed hosts (SSRF guard). */
    public const REASON_UNTRUSTED_URL = 'untrusted_url';

    /**
     * @param string $reason one of the REASON_* constants
     * @param int|null $status HTTP status of the failed call
     * @param string|null $graphCode Graph `error.code` or Entra `error` (letters, digits, `.`, `_`, `-` only)
     * @param string|null $requestId Graph `request-id` / `client-request-id` header (for Microsoft support)
     * @param int|null $retryAfter seconds from `Retry-After` on 429 / 503
     */
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly ?int $status = null,
        public readonly ?string $graphCode = null,
        public readonly ?string $requestId = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Configuration error (unknown profile, invalid tenant / client id / mailbox).
     */
    public static function configuration(string $message): self
    {
        return new self($message, self::REASON_CONFIGURATION);
    }

    /**
     * The message cannot be sent as built (limits, MIME size, attachment rules).
     */
    public static function invalidMessage(string $message): self
    {
        return new self($message, self::REASON_INVALID_MESSAGE);
    }

    /**
     * Network failure or timeout of a call (`$operation`: fixed label such as "create draft").
     */
    public static function transport(string $operation): self
    {
        return new self(sprintf('Microsoft Graph %s failed: network error or timeout.', $operation), self::REASON_TRANSPORT);
    }

    /**
     * A link returned by Graph points outside the allowed hosts.
     */
    public static function untrustedUrl(string $operation): self
    {
        return new self(sprintf('Microsoft Graph %s returned a URL outside the allowed hosts; refused.', $operation), self::REASON_UNTRUSTED_URL);
    }

    /**
     * Error status of a call.
     */
    public static function status(string $operation, int $status, ?string $graphCode, ?string $requestId, ?int $retryAfter = null): self
    {
        $code      = self::safeToken($graphCode);
        $requestId = self::safeToken($requestId);
        $throttled = $status === 429 || $status === 503;

        return new self(
            sprintf('Microsoft Graph %s failed with HTTP %d%s%s.', $operation, $status, $code !== null ? ' (' . $code . ')' : '', $requestId !== null ? ', request-id ' . $requestId : ''),
            $throttled ? self::REASON_THROTTLED : ($operation === 'token request' ? self::REASON_AUTHENTICATION : self::REASON_API),
            $status,
            $code,
            $requestId,
            $throttled ? $retryAfter : null,
        );
    }

    /**
     * True when retrying later may succeed (throttling, transient network failure, 5xx).
     */
    public function isRetryable(): bool
    {
        return $this->reason === self::REASON_THROTTLED
            || $this->reason === self::REASON_TRANSPORT
            || ($this->reason === self::REASON_API && $this->status !== null && $this->status >= 500);
    }

    /**
     * Keeps an error code / request id only when it is a short token (no free text from the response).
     */
    public static function safeToken(?string $value): ?string
    {
        if ($value === null || preg_match('/^[A-Za-z0-9._-]{1,80}\z/', $value) !== 1) {
            return null;
        }

        return $value;
    }
}
