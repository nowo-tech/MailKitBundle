<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger;

use Closure;
use InvalidArgumentException;
use Nowo\MailKitBundle\Mailbox\Engine\MailboxSyncEngine;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxGrantFailure;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxProviderFailure;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxTokenUnavailable;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\MessageOrigin;
use Nowo\MailKitBundle\Mailbox\Value\ProviderMessageId;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

use function min;

/**
 * Messenger handler of the three delegated-mailbox messages (2.5.0; one `messenger.message_handler` tag per message).
 * Malformed ids are dropped with a warning. Failures become Messenger's retry decisions (design section 13):
 *
 * - a grant failure (the mailbox was paused, one event) is **unrecoverable**: nothing retries until resume();
 * - throttling / a busy token endpoint with Retry-After is retried after that delay (at most one hour);
 * - other transient failures are rethrown for the transport's retry strategy (backoff);
 * - any other provider refusal (untrusted link, oversized answer, missing id) is unrecoverable.
 *
 * Stateless.
 */
final readonly class MailboxMessageHandler
{
    /** Longest Retry-After honoured, in seconds. */
    public const MAX_RETRY_AFTER = 3600;

    /**
     * Wires the engine.
     */
    public function __construct(
        private MailboxSyncEngine $engine,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Sweeps one folder.
     */
    public function sync(SyncMailboxFolder $message): void
    {
        $mailbox = $this->mailbox($message->mailboxId);
        if ($mailbox instanceof MailboxId) {
            $this->run(fn () => $this->engine->sweepFolder($mailbox, $message->folderKey));
        }
    }

    /**
     * Fetches one message.
     */
    public function fetch(FetchMailboxMessage $message): void
    {
        $mailbox = $this->mailbox($message->mailboxId);
        $origin  = MessageOrigin::tryFrom($message->origin);

        try {
            $id = new ProviderMessageId($message->messageId);
        } catch (InvalidArgumentException) {
            $id = null;
        }
        if (!$mailbox instanceof MailboxId || !$id instanceof ProviderMessageId || $origin === null) {
            $this->logger?->warning('Mailbox fetch message dropped: malformed ids.', ['bundle' => 'NowoMailKitBundle']);

            return;
        }
        $this->run(fn () => $this->engine->fetchMessage($mailbox, $message->folderKey, $id, $origin));
    }

    /**
     * Renews the subscriptions of one mailbox, or of every active one (a failing mailbox never stops the others).
     */
    public function renew(RenewMailboxSubscriptions $message): void
    {
        if ($message->mailboxId === null) {
            $this->engine->renewAll();

            return;
        }
        $mailbox = $this->mailbox($message->mailboxId);
        if ($mailbox instanceof MailboxId) {
            $this->run(fn () => $this->engine->renew($mailbox, $message->reason, $message->subscriptionId));
        }
    }

    /**
     * @param Closure(): void $work
     */
    private function run(Closure $work): void
    {
        try {
            $work();
        } catch (MailboxGrantFailure $failure) {
            throw new UnrecoverableMessageHandlingException($failure->getMessage(), $failure->getCode(), $failure);
        } catch (MailboxTokenUnavailable $failure) {
            if ($failure->retryAfter === null) {
                throw $failure;
            }

            throw new RecoverableMessageHandlingException($failure->getMessage(), 0, null, min(self::MAX_RETRY_AFTER, $failure->retryAfter) * 1000);
        } catch (MailboxProviderFailure $failure) {
            if (!$failure->isRetryable()) {
                throw new UnrecoverableMessageHandlingException($failure->getMessage(), $failure->getCode(), $failure);
            }
            if ($failure->retryAfter === null) {
                throw $failure;
            }

            throw new RecoverableMessageHandlingException($failure->getMessage(), 0, null, min(self::MAX_RETRY_AFTER, $failure->retryAfter) * 1000);
        }
    }

    private function mailbox(string $id): ?MailboxId
    {
        try {
            return new MailboxId($id);
        } catch (InvalidArgumentException) {
            $this->logger?->warning('Mailbox message dropped: malformed mailbox id.', ['bundle' => 'NowoMailKitBundle']);

            return null;
        }
    }
}
