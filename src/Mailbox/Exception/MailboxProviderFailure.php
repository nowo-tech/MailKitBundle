<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Exception;

/**
 * A provider call failed without a grant problem (2.5.0): throttling, 5xx, a timeout (`$retryable`, with Retry-After
 * when given), an answer over a size limit, an untrusted link, a missing message or attachment, or an attachment that
 * is not downloadable. The message holds the operation, the status, the provider error code and the request id only.
 */
final class MailboxProviderFailure extends MailboxException
{
    /** Throttled, 5xx, timeout or network error: retry later. */
    public const TRANSIENT = 'transient';

    /** Over a size limit (answer, message or attachment). */
    public const TOO_LARGE = 'too_large';

    /** The message or attachment no longer exists. */
    public const NOT_FOUND = 'not_found';

    /** The attachment is an attached e-mail or a cloud link (Q-MB-22). */
    public const NOT_DOWNLOADABLE = 'not_downloadable';

    /** Any other refusal or an unusable answer. */
    public const REFUSED = 'refused';

    /**
     * @param string $reason one of the constants
     * @param int|null $status HTTP status of the failed call
     * @param int|null $retryAfter seconds from Retry-After (throttling)
     * @param string|null $providerCode provider error code (a short token), when given
     */
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly ?int $status = null,
        public readonly ?int $retryAfter = null,
        public readonly ?string $providerCode = null,
    ) {
        parent::__construct($message);
    }

    /**
     * True when a later retry may succeed.
     */
    public function isRetryable(): bool
    {
        return $this->reason === self::TRANSIENT;
    }
}
