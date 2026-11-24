<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Value;

use InvalidArgumentException;

use function in_array;

/**
 * One provider call, for the host's metering (2.5.0, design section 14, Beacon spec 223): mailbox, a fixed operation
 * label ({@see OPERATIONS}), HTTP status (0 = transport error), duration, bytes read, Retry-After, quota units (Gmail)
 * and the attempt number. Never a URL, a message id, an address or a body.
 */
final readonly class ProviderCall
{
    /** Closed list of operation labels. */
    public const OPERATIONS = ['account', 'subscribe', 'renew', 'unsubscribe', 'watch', 'stop', 'delta', 'history', 'list', 'get_message', 'get_attachment', 'create_draft', 'update_draft', 'upload', 'send', 'delete_draft'];

    /**
     * @param string $operation one of {@see OPERATIONS}
     * @param int $status HTTP status, 0 for a transport error
     * @param int $durationMs wall time of the call
     * @param int $bytes response bytes read
     * @param int|null $retryAfter seconds from Retry-After, when given
     * @param int $quotaUnits provider quota units (Gmail; 0 for Graph)
     * @param int $attempt 1 for the first try, 2 for the retry with a fresh token
     *
     * @throws InvalidArgumentException for an unknown operation label
     */
    public function __construct(
        public MailboxProvider $provider,
        public MailboxId $mailbox,
        public string $operation,
        public int $status,
        public int $durationMs,
        public int $bytes,
        public ?int $retryAfter,
        public int $quotaUnits,
        public int $attempt,
    ) {
        if (!in_array($operation, self::OPERATIONS, true)) {
            throw new InvalidArgumentException('Unknown provider call operation.');
        }
    }
}
