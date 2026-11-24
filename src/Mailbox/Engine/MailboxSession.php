<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Engine;

use Nowo\MailKitBundle\Mailbox\Port\MailboxCallObserverInterface;
use Nowo\MailKitBundle\Mailbox\Port\MailboxTokenProviderInterface;
use Nowo\MailKitBundle\Mailbox\Value\MailboxAccessToken;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\MailboxProvider;
use Nowo\MailKitBundle\Mailbox\Value\ProviderCall;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The provider calls of **one** operation on one mailbox (a Messenger message or a service call, 2.5.0): built per
 * operation and dropped with it, never kept by a service (FrankenPHP worker mode). It asks the host's token port once
 * and keeps the token for the operation only; a 401 drops it (the host is told the fingerprint) so the retry gets a
 * fresh one. Every provider call is reported to the host's observer; an observer failure is logged and ignored.
 *
 * @internal
 */
final class MailboxSession
{
    private ?MailboxAccessToken $token = null;

    /** @var array<string, string> folder key => provider folder id, for this operation */
    private array $folderIds = [];

    /**
     * @param string|null $address the directory's address (lower-case), when known
     * @param string|null $accountHash SHA-256 of the provider account id MailKit bound to the mailbox, when known
     */
    public function __construct(
        public readonly MailboxId $mailbox,
        public readonly MailboxProvider $provider,
        public readonly ?string $address,
        public readonly ?string $accountHash,
        private readonly MailboxTokenProviderInterface $tokens,
        private readonly MailboxCallObserverInterface $observer,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * The token of this operation (asked once).
     */
    public function token(): MailboxAccessToken
    {
        // @igor-ignore - A session is built per operation (one Messenger message or service call) and never shared.
        return $this->token ??= $this->tokens->accessToken($this->mailbox);
    }

    /**
     * The provider refused the current token: tell the host and forget it.
     */
    public function rejected(): void
    {
        if ($this->token instanceof MailboxAccessToken) {
            $this->tokens->rejected($this->mailbox, $this->token->fingerprint());
        }
        // @igor-ignore - A session is built per operation (one Messenger message or service call) and never shared.
        $this->token = null;
    }

    /**
     * The provider id of a folder already read in this operation, or null.
     */
    public function folderId(string $key): ?string
    {
        return $this->folderIds[$key] ?? null;
    }

    /**
     * Remembers a folder's provider id for the rest of this operation.
     */
    public function rememberFolderId(string $key, string $id): void
    {
        // @igor-ignore - A session is built per operation (one Messenger message or service call) and never shared.
        $this->folderIds[$key] = $id;
    }

    /**
     * Reports one provider call to the host's observer (never throws).
     *
     * @param string $operation one of {@see ProviderCall::OPERATIONS}
     */
    public function called(string $operation, int $status, int $durationMs, int $bytes, ?int $retryAfter, int $attempt, int $quotaUnits = 0): void
    {
        try {
            $this->observer->called(new ProviderCall($this->provider, $this->mailbox, $operation, $status, $durationMs, $bytes, $retryAfter, $quotaUnits, $attempt));
        } catch (Throwable $failure) {
            $this->logger?->warning('Mailbox call observer failed; ignored.', ['bundle' => 'NowoMailKitBundle', 'mailbox' => $this->mailbox->value, 'error' => $failure::class]);
        }
    }
}
