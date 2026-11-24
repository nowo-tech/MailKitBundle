<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger;

use Closure;
use InvalidArgumentException;
use Nowo\MailKitBundle\Mailbox\Engine\MailboxSender;
use Nowo\MailKitBundle\Mailbox\Engine\MailboxSyncEngine;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxGrantFailure;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxProviderFailure;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxTokenUnavailable;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\MessageOrigin;
use Nowo\MailKitBundle\Mailbox\Value\OutgoingMailboxMail;
use Nowo\MailKitBundle\Mailbox\Value\ProviderMessageId;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

use function max;
use function min;
use function preg_match;

/**
 * Messenger handler of the delegated-mailbox messages (2.5.0; one `messenger.message_handler` tag per message): sync,
 * fetch and renewal, and from the send path (part 2) the sent copy and the reconcile of an ambiguous send.
 * Malformed ids are dropped with a warning. Failures become Messenger's retry decisions (design section 13):
 *
 * - a grant failure has already paused the mailbox (one event): the message is **acknowledged** (logged, not thrown),
 *   so a `doctrine_transaction` middleware around the handler commits the pause instead of rolling it back (audit
 *   ZAT-M1); nothing retries until resume();
 * - a transient failure of a **sweep** acknowledges it: the next scheduled sweep covers the folder, so sweeps never
 *   pile up during throttling (audit ZAT-L2);
 * - a transient failure of a fetch or a renewal with Retry-After is re-enqueued after that delay (at most one hour)
 *   for at most {@see MAX_DELAYED_ATTEMPTS} attempts; then, and without Retry-After, it is rethrown for the transport's
 *   bounded retry strategy and failure transport (on a synchronous bus the DelayStamp is ignored, so these attempts run
 *   back to back);
 * - any other provider refusal (untrusted link, oversized answer, missing id) is unrecoverable;
 * - a sent copy the provider has not filed yet is tried again after `send.sent_copy.delay_ms`, at most
 *   `send.sent_copy.attempts` times; a send still being reconciled is tried again after its stale delay, at most
 *   {@see MAX_DELAYED_ATTEMPTS} times (then the next send() with the key reconciles it).
 *
 * Stateless.
 */
final readonly class MailboxMessageHandler
{
    /** Longest Retry-After honoured, in seconds. */
    public const MAX_RETRY_AFTER = 3600;

    /** Delayed re-enqueues of one fetch or renewal after Retry-After answers. */
    public const MAX_DELAYED_ATTEMPTS = 10;

    /**
     * @param MessageBusInterface $bus bus of the delayed re-enqueues (`mailboxes.bus`)
     * @param int $sentCopyAttempts `send.sent_copy.attempts`
     * @param int $sentCopyDelayMs `send.sent_copy.delay_ms`
     * @param int $reconcileDelayMs delay before a send is reconciled again (`send.stale_claim_seconds` + 1 s)
     */
    public function __construct(
        private MailboxSyncEngine $engine,
        private MessageBusInterface $bus,
        private ?LoggerInterface $logger = null,
        private ?MailboxSender $sender = null,
        private int $sentCopyAttempts = 3,
        private int $sentCopyDelayMs = 5000,
        private int $reconcileDelayMs = 301_000,
    ) {
    }

    /**
     * Sweeps one folder.
     */
    public function sync(SyncMailboxFolder $message): void
    {
        $mailbox = $this->mailbox($message->mailboxId);
        if ($mailbox instanceof MailboxId) {
            $this->run($mailbox, fn () => $this->engine->sweepFolder($mailbox, $message->folderKey), null, 0);
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
        if (!$mailbox instanceof MailboxId || !$id instanceof ProviderMessageId || !$origin instanceof MessageOrigin) {
            $this->logger?->warning('Mailbox fetch message dropped: malformed ids.', ['bundle' => 'NowoMailKitBundle']);

            return;
        }
        $this->run(
            $mailbox,
            fn () => $this->engine->fetchMessage($mailbox, $message->folderKey, $id, $origin),
            static fn (int $attempt): FetchMailboxMessage => new FetchMailboxMessage($message->mailboxId, $message->folderKey, $message->messageId, $message->origin, $attempt),
            $message->attempt,
        );
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
            $this->run(
                $mailbox,
                fn () => $this->engine->renew($mailbox, $message->reason, $message->subscriptionId),
                static fn (int $attempt): RenewMailboxSubscriptions => new RenewMailboxSubscriptions($message->mailboxId, $message->reason, $message->subscriptionId, $attempt),
                $message->attempt,
            );
        }
    }

    /**
     * Delivers the copy of a sent message to the sink (tried again later while the provider has not filed it).
     */
    public function sentCopy(DeliverSentCopy $message): void
    {
        $mailbox = $this->mailbox($message->mailboxId);

        try {
            $id = new ProviderMessageId($message->messageId);
        } catch (InvalidArgumentException) {
            $id = null;
        }
        if (!$mailbox instanceof MailboxId || !$id instanceof ProviderMessageId) {
            $this->logger?->warning('Mailbox sent copy dropped: malformed ids.', ['bundle' => 'NowoMailKitBundle']);

            return;
        }
        $again = static fn (int $attempt): DeliverSentCopy => new DeliverSentCopy($message->mailboxId, $message->messageId, $attempt);
        $this->run($mailbox, function () use ($mailbox, $id, $message, $again): void {
            if ($this->engine->deliverSentCopy($mailbox, $id)) {
                return;
            }
            $attempt = max(0, $message->attempt) + 1;
            if ($attempt >= $this->sentCopyAttempts) {
                $this->logger?->notice('Mailbox sent copy not found after the last attempt; a sweep of a followed Sent Items delivers it.', ['bundle' => 'NowoMailKitBundle', 'mailbox' => $mailbox->value]);

                return;
            }
            $this->bus->dispatch($again($attempt), [new DelayStamp($this->sentCopyDelayMs)]);
        }, $again, $message->attempt);
    }

    /**
     * Reconciles a send whose outcome was ambiguous (tried again later while it is not settled).
     */
    public function reconcile(ReconcileMailboxSend $message): void
    {
        $mailbox = $this->mailbox($message->mailboxId);
        $sender  = $this->sender;
        if (!$mailbox instanceof MailboxId || preg_match(OutgoingMailboxMail::KEY_PATTERN, $message->idempotencyKey) !== 1 || !$sender instanceof MailboxSender) {
            $this->logger?->warning('Mailbox send reconcile dropped: malformed ids or no sender.', ['bundle' => 'NowoMailKitBundle']);

            return;
        }
        $again = static fn (int $attempt): ReconcileMailboxSend => new ReconcileMailboxSend($message->mailboxId, $message->idempotencyKey, $attempt);
        $this->run($mailbox, function () use ($mailbox, $sender, $message, $again): void {
            if ($sender->reconcile($mailbox, $message->idempotencyKey)) {
                return;
            }
            $attempt = max(0, $message->attempt) + 1;
            if ($attempt >= self::MAX_DELAYED_ATTEMPTS) {
                $this->logger?->notice('Mailbox send still unsettled after the last background reconcile; the next send() with the key reconciles it.', ['bundle' => 'NowoMailKitBundle', 'mailbox' => $mailbox->value]);

                return;
            }
            $this->bus->dispatch($again($attempt), [new DelayStamp($this->reconcileDelayMs)]);
        }, $again, $message->attempt);
    }

    /**
     * @param Closure(): void $work
     * @param (Closure(int): object)|null $again the message for a delayed re-enqueue; null = acknowledge transient failures
     * @param int $attempt delayed re-enqueues so far
     */
    private function run(MailboxId $mailbox, Closure $work, ?Closure $again, int $attempt): void
    {
        try {
            $work();
        } catch (MailboxGrantFailure $failure) {
            // The engine recorded the pause (and its winner dispatched the event): acknowledge, so it commits.
            $this->logger?->warning('Mailbox message acknowledged: the grant failed and the mailbox is paused.', ['bundle' => 'NowoMailKitBundle', 'mailbox' => $mailbox->value, 'reason' => $failure->reason->value]);
        } catch (MailboxTokenUnavailable|MailboxProviderFailure $failure) {
            if ($failure instanceof MailboxProviderFailure && !$failure->isRetryable()) {
                throw new UnrecoverableMessageHandlingException($failure->getMessage(), 0, $failure);
            }
            if (!$again instanceof Closure) {
                $this->logger?->notice('Mailbox sweep acknowledged after a transient failure; the next scheduled sweep covers the folder.', ['bundle' => 'NowoMailKitBundle', 'mailbox' => $mailbox->value]);

                return;
            }
            $attempt = max(0, $attempt);
            if ($failure->retryAfter === null || $attempt + 1 >= self::MAX_DELAYED_ATTEMPTS) {
                throw $failure;
            }
            $this->bus->dispatch($again($attempt + 1), [new DelayStamp(min(self::MAX_RETRY_AFTER, $failure->retryAfter) * 1000)]);
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
