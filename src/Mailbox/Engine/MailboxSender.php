<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Engine;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxGrantFailure;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxNotActive;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxProviderFailure;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxSendFailed;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxSendInProgress;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxSendKeyConflict;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxSendOutcomeUnknown;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxTokenUnavailable;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger\DeliverSentCopy;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger\ReconcileMailboxSend;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Store\MailboxStore;
use Nowo\MailKitBundle\Mailbox\Service\MailboxSenderInterface;
use Nowo\MailKitBundle\Mailbox\Value\GrantFailureReason;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\MailboxProvider;
use Nowo\MailKitBundle\Mailbox\Value\MailboxSettings;
use Nowo\MailKitBundle\Mailbox\Value\OutgoingMailboxMail;
use Nowo\MailKitBundle\Mailbox\Value\ProviderMessageId;
use Nowo\MailKitBundle\Mailbox\Value\SentMessage;
use Nowo\MailKitBundle\Mailbox\Value\ThreadKey;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Throwable;

use function bin2hex;
use function count;
use function hash;
use function hash_equals;
use function intdiv;
use function random_bytes;
use function strlen;
use function strrchr;
use function substr;

/**
 * Default {@see MailboxSenderInterface} (2.5.0 part 2, design sections 6.5 and 9.4): draft + send from a delegated
 * mailbox, at most once per idempotency key. Stateless (FrankenPHP worker mode): every call reads the directory and
 * the key's row again and builds its own session.
 *
 * The key's row moves by conditional statements only ({@see SendState}); every statement changes the claim token, the
 * state or `row_version`, so MySQL / MariaDB count the row they match (audit ZAT-R4):
 *
 * 1. **Claim**: insert `drafting` with a random claim token (a caught duplicate key reads the row instead).
 * 2. **Account check** (every send): a token of another account pauses the mailbox `AccountMismatch`; nothing is sent.
 * 3. **Draft**: create it, store its id on the row (held by the token), put the recipients / body on it, upload the
 *    attachments (each chunk extends the claim; a lost claim ends the upload). Any failure here deletes the draft (only
 *    after reading it as a draft) and the row becomes `released` (transient, grant, token: the next call takes it over
 *    at once) or `failed` (a definite refusal).
 * 4. **`sending`** is written before the submit. From then on only that draft is ever submitted again for the key.
 * 5. **Submit**, one request at a time: before each (the retry after a 401 too) the token is fetched, the submit
 *    window checked (at most `send.stale_claim_seconds / 2` after `sending` was written) and the claim renewed by a
 *    conditional heartbeat (audit ZAV-L1). A failure of any kind is followed by a read of the draft state: sent → `sent`; still a draft after a
 *    definite 4xx → `failed`; gone → `unknown`; still a draft after an ambiguous answer, or unreadable → stays
 *    `sending`, {@see MailboxSendInProgress} with `reconciling`, and a delayed `ReconcileMailboxSend`.
 * 6. **Reconcile** (a later send() with the key, or the delayed message) of a `sending` row with no progress for
 *    `send.stale_claim_seconds`: take it over (one winner), read the draft state; sent → `sent`, still a draft → submit
 *    that same draft again, gone → `unknown`; after `send.max_attempts` takeovers → `unknown`. One draft item is
 *    delivered at most once, so no path sends twice.
 * 7. **Sent**: `DeliverSentCopy` is enqueued (Q-MB-6). Delegated sends are not written to the sent-mail log (Q-MB-14).
 *
 * A second call with the same key: `sent` → the stored ids with `replayed: true`; `failed` / `unknown` → the same
 * exception; in flight → {@see MailboxSendInProgress}; another message → {@see MailboxSendKeyConflict}.
 */
final readonly class MailboxSender implements MailboxSenderInterface
{
    /**
     * @param MessageBusInterface $bus bus of `DeliverSentCopy` and `ReconcileMailboxSend` (`mailboxes.bus`)
     */
    public function __construct(
        private MailboxSyncEngine $engine,
        private MailboxStore $store,
        private MessageBusInterface $bus,
        private MailboxSendOptions $options = new MailboxSendOptions(),
        private ?ClockInterface $clock = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function send(MailboxId $mailbox, OutgoingMailboxMail $mail): SentMessage
    {
        $this->checkLimits($mail);
        $this->assertOutsideTransaction();
        $context = $this->engine->active($mailbox);
        if ($context === null) {
            throw new MailboxNotActive($this->engine->status($mailbox)->status);
        }
        [$settings, $gateway, $session] = $context;
        $key                            = $mail->idempotencyKey;
        $hash                           = $mail->fingerprint();
        $token                          = $this->token();
        if ($this->store->claimSend($mailbox, $key, $hash, $token)) {
            return $this->compose($settings, $gateway, $session, $mail, $token, null);
        }
        $row = $this->store->sendRow($mailbox, $key);
        if ($row === null) {
            // Purged or disconnected between the two statements: a retry claims it again.
            throw new MailboxSendInProgress();
        }
        if (!hash_equals($row['payload_hash'], $hash)) {
            throw new MailboxSendKeyConflict();
        }

        return match ($row['status']) {
            SendState::Sent    => $this->sentMessage($row, true),
            SendState::Failed  => throw new MailboxSendFailed($row['reason'] ?? MailboxProviderFailure::REFUSED),
            SendState::Unknown => throw new MailboxSendOutcomeUnknown(),
            SendState::Sending => $this->reconcileRow($settings, $gateway, $session, $key, $row),
            SendState::Drafting,
            SendState::Released => $this->takeOverDraft($settings, $gateway, $session, $mail, $row),
        };
    }

    /**
     * Reconciles a `sending` key in the background (Messenger `ReconcileMailboxSend`): true when nothing more is to be
     * done (settled, not `sending`, or the mailbox is not active: then the next send() with the key reconciles it), false
     * to try again later.
     *
     * @throws MailboxGrantFailure the mailbox was paused
     * @throws LogicException called inside a transaction on the mailbox connection
     */
    public function reconcile(MailboxId $mailbox, string $key): bool
    {
        $this->assertOutsideTransaction();
        $row = $this->store->sendRow($mailbox, $key);
        if ($row === null || $row['status'] !== SendState::Sending) {
            return true;
        }
        if ($row['updated_at'] >= $this->staleBefore()) {
            return false;
        }
        $context = $this->engine->active($mailbox);
        if ($context === null) {
            return true;
        }
        [$settings, $gateway, $session] = $context;

        try {
            $this->reconcileRow($settings, $gateway, $session, $key, $row);
        } catch (MailboxSendInProgress) {
            return false;
        } catch (MailboxSendFailed|MailboxSendOutcomeUnknown) {
            return true;
        }

        return true;
    }

    /**
     * Deletes the drafts that settled rows (`released`, `failed`) not updated since `$before` still name, each only
     * after Graph reads it as a draft (audit ZAV-I4; `nowo:mail-kit:mailbox:purge --drafts`). A draft that reads as sent
     * is left alone; a paused mailbox, an unreadable state or a failure skips that row. Returns the drafts deleted.
     */
    public function purgeOrphanDrafts(int $before): int
    {
        $deleted = 0;
        foreach ($this->store->orphanDrafts($before) as $row) {
            $mailbox = new MailboxId($row['mailbox_id']);
            $context = $this->engine->active($mailbox);
            if ($context === null) {
                continue;
            }
            [, $gateway, $session] = $context;
            $draft                 = new ProviderMessageId($row['draft']);

            try {
                if ($gateway->draftState($session, $draft) === DraftState::Draft) {
                    $gateway->deleteDraft($session, $draft);
                    ++$deleted;
                }
            } catch (Throwable $failure) {
                $this->logger?->notice('Mailbox orphan draft not deleted; retried by the next purge until its row is purged.', ['bundle' => 'NowoMailKitBundle', 'mailbox' => $mailbox->value, 'error' => $failure::class]);
            }
        }

        return $deleted;
    }

    /**
     * A `drafting` row without progress for `send.stale_claim_seconds`, or a `released` one: taken over by one caller.
     *
     * @param array{status: SendState, token: string, message_id: string|null} $row
     */
    private function takeOverDraft(MailboxSettings $settings, MailboxGatewayInterface $gateway, MailboxSession $session, OutgoingMailboxMail $mail, array $row): SentMessage
    {
        $stale = $row['status'] === SendState::Drafting ? $this->staleBefore() : null;
        $token = $this->token();
        if (!$this->store->takeOverSend($settings->id, $mail->idempotencyKey, $row['token'], $row['status'], $token, SendState::Drafting, $stale)) {
            throw new MailboxSendInProgress();
        }

        return $this->compose($settings, $gateway, $session, $mail, $token, $row['message_id'] !== null ? new ProviderMessageId($row['message_id']) : null);
    }

    /**
     * Steps 2 – 5 for the holder of `$token` (row `drafting`).
     *
     * @param ProviderMessageId|null $previous the draft of an abandoned attempt (never submitted): deleted first
     */
    private function compose(MailboxSettings $settings, MailboxGatewayInterface $gateway, MailboxSession $session, OutgoingMailboxMail $mail, string $token, ?ProviderMessageId $previous): SentMessage
    {
        $mailbox = $settings->id;
        $key     = $mail->idempotencyKey;
        $draft   = null;
        $since   = 0;

        try {
            if (!$this->engine->checkAccount($settings, $gateway, $session)) {
                throw new MailboxGrantFailure(GrantFailureReason::AccountMismatch, 'The token belongs to another account than the mailbox; nothing was sent.');
            }
            if ($previous instanceof ProviderMessageId) {
                $this->dropPrevious($settings, $gateway, $session, $key, $token, $previous);
            }
            $draft = $gateway->createDraft($session, $mail, $this->messageId($settings, $key));
            if (!$this->store->updateSend($mailbox, $key, $token, SendState::Drafting, SendState::Drafting, ['provider_message_id' => $draft->id->value, 'internet_message_id' => $draft->internetMessageId !== '' ? $draft->internetMessageId : null, 'thread_id' => $draft->threadId])) {
                throw new MailboxSendInProgress();
            }
            $gateway->updateDraft($session, $draft, $mail);
            foreach ($mail->attachments as $attachment) {
                // @igor-ignore - The closure runs synchronously inside addAttachment(); it is not stored on the service.
                $gateway->addAttachment($session, $draft, $attachment, function () use ($mailbox, $key, $token): void {
                    if (!$this->store->updateSend($mailbox, $key, $token, SendState::Drafting, SendState::Drafting)) {
                        throw new MailboxSendInProgress();
                    }
                });
            }
            $since = $this->now();
            if (!$this->store->updateSend($mailbox, $key, $token, SendState::Drafting, SendState::Sending)) {
                throw new MailboxSendInProgress();
            }
        } catch (Throwable $failure) {
            $this->abandon($settings, $gateway, $session, $key, $token, $draft?->id, $failure);
        }

        return $this->submit($settings, $gateway, $session, $key, $token, $draft->id, $since, false);
    }

    /**
     * Deletes the draft an abandoned attempt left (it never reached `sending`, so MailKit never submitted it). A draft
     * that reads as sent was sent from the mailbox by hand: the key is `unknown` and nothing is sent again.
     *
     * @throws MailboxSendOutcomeUnknown
     * @throws MailboxProviderFailure transient: its state cannot be read now
     */
    private function dropPrevious(MailboxSettings $settings, MailboxGatewayInterface $gateway, MailboxSession $session, string $key, string $token, ProviderMessageId $previous): void
    {
        $state = $gateway->draftState($session, $previous);
        if ($state === DraftState::Sent) {
            $this->store->updateSend($settings->id, $key, $token, SendState::Drafting, SendState::Unknown, ['reason' => 'draft_sent_elsewhere']);
            $this->logger?->warning('Mailbox send: the draft of an abandoned attempt was sent outside MailKit; the key is unknown and nothing is sent again.', ['bundle' => 'NowoMailKitBundle', 'mailbox' => $settings->id->value]);

            throw new MailboxSendOutcomeUnknown();
        }
        if ($state === DraftState::Unreadable) {
            throw new MailboxProviderFailure('The state of the previous draft cannot be read now.', MailboxProviderFailure::TRANSIENT);
        }
        if ($state === DraftState::Draft) {
            $gateway->deleteDraft($session, $previous);
        }
    }

    /**
     * Ends a failed attempt before the submit: deletes its draft (read as a draft first), then `failed` for a definite
     * refusal, `released` otherwise (a lost claim leaves the row to its new holder). Always throws.
     *
     * @throws Throwable
     */
    private function abandon(MailboxSettings $settings, MailboxGatewayInterface $gateway, MailboxSession $session, string $key, string $token, ?ProviderMessageId $draft, Throwable $failure): never
    {
        $mailbox   = $settings->id;
        $discarded = !$draft instanceof ProviderMessageId || $this->discard($gateway, $session, $draft);
        if ($failure instanceof MailboxSendInProgress || $failure instanceof MailboxSendOutcomeUnknown) {
            throw $failure;
        }
        if ($failure instanceof MailboxGrantFailure) {
            $this->engine->grantFailed($mailbox, $settings->provider, $failure->reason);
        }
        $definite = $failure instanceof MailboxProviderFailure && !$failure->isRetryable();
        $reason   = $failure instanceof MailboxProviderFailure ? $this->reason($failure) : null;
        // The row keeps a draft id only while that draft may still exist (the next holder deletes it first).
        $set = ($draft instanceof ProviderMessageId ? ['provider_message_id' => $discarded ? null : $draft->value] : []) + ($definite ? ['reason' => $reason] : []);

        try {
            $this->store->updateSend($mailbox, $key, $token, SendState::Drafting, $definite ? SendState::Failed : SendState::Released, $set);
        } catch (Throwable $storage) {
            // The claim then goes stale and is taken over: the original failure is what the caller needs.
            $this->logger?->warning('Mailbox send: the abandoned claim could not be recorded; it is taken over once stale.', ['bundle' => 'NowoMailKitBundle', 'mailbox' => $mailbox->value, 'error' => $storage::class]);
        }
        $this->logger?->notice('Mailbox send stopped before the submit; nothing was sent.', ['bundle' => 'NowoMailKitBundle', 'mailbox' => $mailbox->value, 'definite' => $definite, 'error' => $failure::class]);
        if ($definite) {
            throw new MailboxSendFailed((string) $reason);
        }

        throw $failure;
    }

    /**
     * Step 5 for the holder of a `sending` row (audit ZAV-L1). Every submit request, the retry after a 401 included, is
     * preceded by: the token (a host refresh may take long, so it comes first), the submit window (no submit later than
     * `send.stale_claim_seconds / 2` after `sending` was written or taken over, so a request is over long before another
     * worker may read the draft state) and a conditional heartbeat on the claim token (a lost claim submits nothing).
     *
     * @param int $since UNIX seconds when this holder wrote or took over `sending`
     * @param bool $replayed this call takes over an earlier submit (the result is reported as a replay when sent)
     */
    private function submit(MailboxSettings $settings, MailboxGatewayInterface $gateway, MailboxSession $session, string $key, string $token, ProviderMessageId $draft, int $since, bool $replayed): SentMessage
    {
        $mailbox = $settings->id;
        for ($attempt = 1;; ++$attempt) {
            try {
                $session->token();
                if ($this->now() - $since > intdiv($this->options->staleClaimSeconds, 2)) {
                    $this->logger?->notice('Mailbox send: the submit window passed (slow token refresh); reconciled by the draft state later.', ['bundle' => 'NowoMailKitBundle', 'mailbox' => $mailbox->value]);
                    $this->pending($mailbox, $key);
                }
                if (!$this->store->updateSend($mailbox, $key, $token, SendState::Sending, SendState::Sending)) {
                    // Another worker took the key over: it decides by the draft state; nothing is submitted here.
                    throw new MailboxSendInProgress(true);
                }
                $gateway->sendDraft($session, $draft);
            } catch (MailboxSendInProgress $stop) {
                throw $stop;
            } catch (SubmitTokenRejected) {
                if ($attempt === 1) {
                    // Graph took nothing; the token was dropped. Refresh, then window and heartbeat again.
                    continue;
                }

                return $this->afterFailedSubmit($settings, $gateway, $session, $key, $token, $draft, new MailboxGrantFailure(GrantFailureReason::Revoked, 'Microsoft Graph refused a fresh token of the submit (HTTP 401).'), $replayed);
            } catch (Throwable $failure) {
                return $this->afterFailedSubmit($settings, $gateway, $session, $key, $token, $draft, $failure, $replayed);
            }

            return $this->markSent($mailbox, $key, $token, $draft, $replayed);
        }
    }

    /**
     * Decides by the draft state after any failure of the submit (lesson of 1.8 audit NK-1 / 2.2.x: a failed submit
     * proves nothing by itself; never resubmit blindly).
     *
     * @throws Throwable
     */
    private function afterFailedSubmit(MailboxSettings $settings, MailboxGatewayInterface $gateway, MailboxSession $session, string $key, string $token, ProviderMessageId $draft, Throwable $failure, bool $replayed): SentMessage
    {
        $mailbox = $settings->id;
        if ($failure instanceof MailboxGrantFailure) {
            // The provider refused the token: it took nothing. The row stays `sending` and is reconciled after resume().
            $this->engine->grantFailed($mailbox, $settings->provider, $failure->reason);

            throw $failure;
        }

        try {
            $state = $gateway->draftState($session, $draft);
        } catch (MailboxGrantFailure $grant) {
            $this->engine->grantFailed($mailbox, $settings->provider, $grant->reason);

            throw $grant;
        } catch (Throwable) {
            $state = DraftState::Unreadable;
        }
        if ($state === DraftState::Sent) {
            $this->logger?->notice('Mailbox send: the submit failed but the message was sent.', ['bundle' => 'NowoMailKitBundle', 'mailbox' => $mailbox->value, 'error' => $failure::class]);

            return $this->markSent($mailbox, $key, $token, $draft, $replayed);
        }
        if ($state === DraftState::Gone) {
            $this->store->updateSend($mailbox, $key, $token, SendState::Sending, SendState::Unknown, ['reason' => 'draft_gone']);
            $this->logger?->warning('Mailbox send outcome unknown: the submitted draft is gone; it is never sent again.', ['bundle' => 'NowoMailKitBundle', 'mailbox' => $mailbox->value]);

            throw new MailboxSendOutcomeUnknown();
        }
        if ($state === DraftState::Draft && $failure instanceof MailboxProviderFailure && !$failure->isRetryable()) {
            // A definite refusal of a draft that is still a draft: nothing was sent.
            $reason  = $this->reason($failure);
            $deleted = $this->delete($gateway, $session, $draft);
            $this->store->updateSend($mailbox, $key, $token, SendState::Sending, SendState::Failed, ['reason' => $reason] + ($deleted ? ['provider_message_id' => null] : []));
            $this->logger?->notice('Mailbox send refused by the provider; nothing was sent.', ['bundle' => 'NowoMailKitBundle', 'mailbox' => $mailbox->value, 'status' => $failure->status]);

            throw new MailboxSendFailed($reason);
        }
        // Ambiguous (timeout, 5xx, 429) or unreadable: the provider may still deliver it. Reconcile later by the state.
        $this->logger?->notice('Mailbox send outcome pending; reconciled by the draft state later, never resent blindly.', ['bundle' => 'NowoMailKitBundle', 'mailbox' => $mailbox->value, 'error' => $failure::class]);
        $this->scheduleReconcile($mailbox, $key);

        throw new MailboxSendInProgress(true);
    }

    /**
     * Step 6: takes a stale `sending` row over and decides by the draft state.
     *
     * @param array{token: string, message_id: string|null, attempts: int, updated_at: int} $row
     */
    private function reconcileRow(MailboxSettings $settings, MailboxGatewayInterface $gateway, MailboxSession $session, string $key, array $row): SentMessage
    {
        $mailbox = $settings->id;
        $stale   = $this->staleBefore();
        if ($row['updated_at'] >= $stale) {
            throw new MailboxSendInProgress(true);
        }
        $token = $this->token();
        $since = $this->now();
        if (!$this->store->takeOverSend($mailbox, $key, $row['token'], SendState::Sending, $token, SendState::Sending, $stale)) {
            throw new MailboxSendInProgress(true);
        }
        if ($row['message_id'] === null || $this->options->maxAttempts < $row['attempts'] + 1) {
            $this->store->updateSend($mailbox, $key, $token, SendState::Sending, SendState::Unknown, ['reason' => 'attempts']);
            $this->logger?->warning('Mailbox send outcome unknown after the last reconcile attempt; it is never sent again.', ['bundle' => 'NowoMailKitBundle', 'mailbox' => $mailbox->value]);

            throw new MailboxSendOutcomeUnknown();
        }
        $draft = new ProviderMessageId($row['message_id']);

        try {
            if (!$this->engine->checkAccount($settings, $gateway, $session)) {
                throw new MailboxGrantFailure(GrantFailureReason::AccountMismatch, 'The token belongs to another account than the mailbox; nothing was sent.');
            }
            $state = $gateway->draftState($session, $draft);
        } catch (MailboxGrantFailure $failure) {
            $this->engine->grantFailed($mailbox, $settings->provider, $failure->reason);

            throw $failure;
        } catch (MailboxProviderFailure|MailboxTokenUnavailable) {
            $state = DraftState::Unreadable;
        }

        return match ($state) {
            DraftState::Sent       => $this->markSent($mailbox, $key, $token, $draft, true),
            DraftState::Draft      => $this->submit($settings, $gateway, $session, $key, $token, $draft, $since, true),
            DraftState::Gone       => $this->unknown($mailbox, $key, $token),
            DraftState::Unreadable => $this->pending($mailbox, $key),
        };
    }

    /**
     * `sending` → `sent`, then the sent copy is enqueued.
     */
    private function markSent(MailboxId $mailbox, string $key, string $token, ProviderMessageId $draft, bool $replayed): SentMessage
    {
        if (!$this->store->updateSend($mailbox, $key, $token, SendState::Sending, SendState::Sent, ['provider_message_id' => $draft->value, 'sent_at' => $this->now()])) {
            // Taken over meanwhile (this call was slower than send.stale_claim_seconds): the holder reaches the same outcome.
            $row = $this->store->sendRow($mailbox, $key);
            if ($row !== null && $row['status'] === SendState::Sent) {
                return $this->sentMessage($row, true);
            }

            throw new MailboxSendInProgress(true);
        }

        try {
            $this->bus->dispatch(new DeliverSentCopy($mailbox->value, $draft->value), [new DelayStamp($this->options->sentCopyDelayMs)]);
        } catch (Throwable $failure) {
            // The message is sent; only the sink's copy waits for the sweep of Sent Items (when followed).
            $this->logger?->warning('Mailbox sent copy could not be enqueued.', ['bundle' => 'NowoMailKitBundle', 'mailbox' => $mailbox->value, 'error' => $failure::class]);
        }
        $this->logger?->info('Mailbox message sent.', ['bundle' => 'NowoMailKitBundle', 'mailbox' => $mailbox->value]);
        $row = $this->store->sendRow($mailbox, $key);

        return $this->sentMessage($row ?? ['message_id' => $draft->value, 'internet_message_id' => '', 'thread_id' => null, 'sent_at' => $this->now()], $replayed);
    }

    /**
     * @throws MailboxSendOutcomeUnknown
     */
    private function unknown(MailboxId $mailbox, string $key, string $token): never
    {
        $this->store->updateSend($mailbox, $key, $token, SendState::Sending, SendState::Unknown, ['reason' => 'draft_gone']);
        $this->logger?->warning('Mailbox send outcome unknown: the submitted draft is gone; it is never sent again.', ['bundle' => 'NowoMailKitBundle', 'mailbox' => $mailbox->value]);

        throw new MailboxSendOutcomeUnknown();
    }

    /**
     * @throws MailboxSendInProgress
     */
    private function pending(MailboxId $mailbox, string $key): never
    {
        $this->scheduleReconcile($mailbox, $key);

        throw new MailboxSendInProgress(true);
    }

    /**
     * The stored result of a sent key.
     *
     * @param array{message_id: string|null, internet_message_id: string, thread_id: string|null, sent_at: int|null} $row
     */
    private function sentMessage(array $row, bool $replayed): SentMessage
    {
        if ($row['message_id'] === null) {
            throw new MailboxSendOutcomeUnknown();
        }
        $id = new ProviderMessageId($row['message_id']);

        return new SentMessage(
            $id,
            $row['internet_message_id'],
            $row['thread_id'],
            ThreadKey::for(MailboxProvider::Graph, $row['thread_id'], [], null, $row['internet_message_id'], $id),
            new DateTimeImmutable('@' . ($row['sent_at'] ?? $this->now())),
            $replayed,
        );
    }

    /**
     * Deletes a draft MailKit created and never submitted, after reading it as a draft; true when it is gone.
     */
    private function discard(MailboxGatewayInterface $gateway, MailboxSession $session, ProviderMessageId $draft): bool
    {
        try {
            $state = $gateway->draftState($session, $draft);
        } catch (Throwable) {
            return false;
        }

        return $state === DraftState::Gone || ($state === DraftState::Draft && $this->delete($gateway, $session, $draft));
    }

    /**
     * Deletes a draft read as a draft; false when the delete failed (the id stays on the row).
     */
    private function delete(MailboxGatewayInterface $gateway, MailboxSession $session, ProviderMessageId $draft): bool
    {
        try {
            $gateway->deleteDraft($session, $draft);
        } catch (Throwable $failure) {
            $this->logger?->notice('Mailbox draft of an unsent message could not be deleted.', ['bundle' => 'NowoMailKitBundle', 'mailbox' => $session->mailbox->value, 'error' => $failure::class]);

            return false;
        }

        return true;
    }

    /**
     * Enqueues the background reconcile of a `sending` key once it is stale (best effort: a later send() with the key
     * reconciles too).
     */
    private function scheduleReconcile(MailboxId $mailbox, string $key): void
    {
        try {
            $this->bus->dispatch(new ReconcileMailboxSend($mailbox->value, $key), [new DelayStamp(($this->options->staleClaimSeconds + 1) * 1000)]);
        } catch (Throwable $failure) {
            $this->logger?->warning('Mailbox send reconcile could not be enqueued; the next send() with the key reconciles it.', ['bundle' => 'NowoMailKitBundle', 'mailbox' => $mailbox->value, 'error' => $failure::class]);
        }
    }

    /**
     * The configured `send.*` bounds.
     *
     * @throws InvalidArgumentException
     */
    private function checkLimits(OutgoingMailboxMail $mail): void
    {
        $options = $this->options;
        if ($mail->recipientCount() > $options->maxRecipients) {
            throw new InvalidArgumentException('The message has more recipients than nowo_mail_kit.mailboxes.send.max_recipients allows.');
        }
        if (strlen($mail->text) > $options->maxTextBytes || strlen((string) $mail->html) > $options->maxHtmlBytes) {
            throw new InvalidArgumentException('The message body exceeds nowo_mail_kit.mailboxes.send.max_text_bytes or max_html_bytes.');
        }
        if (count($mail->attachments) > $options->maxAttachments) {
            throw new InvalidArgumentException('The message has more attachments than nowo_mail_kit.mailboxes.send.max_attachments allows.');
        }
        foreach ($mail->attachments as $attachment) {
            if ($attachment->size > $options->maxAttachmentBytes) {
                throw new InvalidArgumentException('An attachment exceeds nowo_mail_kit.mailboxes.send.max_attachment_bytes.');
            }
        }
        if ($mail->size() > $options->maxMessageBytes) {
            throw new InvalidArgumentException('The message exceeds nowo_mail_kit.mailboxes.send.max_message_bytes.');
        }
    }

    /**
     * A rollback after the provider sent would forget the send and let a retry send again.
     *
     * @throws LogicException
     */
    private function assertOutsideTransaction(): void
    {
        if ($this->store->connection()->isTransactionActive()) {
            throw new LogicException('MailboxSenderInterface::send() must run outside a database transaction on the mailbox connection: a rollback after the provider sent would forget the send. Use a dedicated nowo_mail_kit.mailboxes.connection or call it outside the transaction.');
        }
    }

    /**
     * Message-ID of a new message: derived from the mailbox and the key (the same for every attempt of the key), on the
     * mailbox's domain.
     */
    private function messageId(MailboxSettings $settings, string $key): string
    {
        return substr(hash('sha256', $settings->id->value . "\n" . $key), 0, 32) . '.mailkit' . strrchr($settings->address, '@');
    }

    /**
     * Short reason of a refusal: the kind and the provider error code (a token of letters, digits, `.`, `_`, `-`).
     */
    private function reason(MailboxProviderFailure $failure): string
    {
        return substr($failure->reason . ($failure->providerCode !== null ? ':' . $failure->providerCode : ''), 0, 128);
    }

    private function token(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function staleBefore(): int
    {
        return $this->now() - $this->options->staleClaimSeconds;
    }

    private function now(): int
    {
        return ($this->clock ?? new NativeClock())->now()->getTimestamp();
    }
}
