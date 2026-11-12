<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailer;

use DateTimeImmutable;
use Nowo\MailKitBundle\Dsn\DsnSource;
use Nowo\MailKitBundle\Dsn\MailerDsnAudit;
use Nowo\MailKitBundle\Dsn\MailerDsnProviderInterface;
use Nowo\MailKitBundle\Dsn\MailerDsnValidator;
use Nowo\MailKitBundle\Dsn\RuntimeDsn;
use Nowo\MailKitBundle\Event\MailDeliveredEvent;
use Nowo\MailKitBundle\Event\MailDeliveryFailedEvent;
use Nowo\MailKitBundle\Log\SentMailRecorder;
use Nowo\MailKitBundle\Sender\SenderPolicy;
use Nowo\MailKitBundle\Tracking\EmailTracker;
use Nowo\MailKitBundle\Tracking\TrackedEmail;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function sprintf;
use function strlen;

/**
 * Decorates `mailer.transports` (the transport behind the framework mailer **and** the Messenger
 * `SendEmailMessage` handler): every send resolves the DSN at runtime through the {@see MailerDsnProviderInterface}
 * (Instance Settings by default) and falls back to the framework transport (MAILER_DSN) when nothing is stored or
 * when the message picks a named transport (`X-Transport` header).
 *
 * A stored DSN is validated ({@see MailerDsnValidator}: scheme allow-list, SSRF-safe host) before its transport is
 * built; a refused DSN fails the send (fail closed: no silent fallback). The built transport is cached per DSN and
 * dropped by {@see reset()} (`kernel.reset` between requests / Messenger messages in worker mode, and on
 * `SettingsChangedEvent` for the mail sections).
 *
 * The internal {@see MailHeaders} are removed from the copy that is delivered (never from the caller's message, which a
 * Messenger retry sends again, 1.10 / audit NU-H1); the outcome is written to the sent-mail log when it
 * is enabled. A failure is rethrown as {@see DeliveryFailedException}: scrubbed message and reference, never the
 * provider response body (so it is safe in flashes, the log and the Messenger failure transport).
 *
 * Since 1.10, with `tracking.enabled`, the {@see EmailTracker} prepares a tracked copy (open pixel, rewritten links)
 * right before delivery; the original message is never changed, a failed delivery forgets the send, and
 * {@see MailDeliveredEvent::$trackingId} carries its id.
 *
 * Since 1.5 every outcome is also dispatched as an id-only PSR-14 event ({@see MailDeliveredEvent},
 * {@see MailDeliveryFailedEvent}) after the log write; a failing listener is logged and never changes the outcome.
 */
final class RuntimeTransport implements TransportInterface, ResetInterface
{
    /** Label (log transport column) of the framework transport. */
    public const FALLBACK_LABEL = 'default';

    private ?TransportInterface $resolved = null;

    private ?string $resolvedDsn = null;

    private ?string $checkedDsn = null;

    private ?string $checkedError = null;

    /** A delivery event is being dispatched (re-entrancy guard, audit KI-M-L1). */
    private bool $notifying = false;

    /**
     * @param TransportInterface $fallback the decorated `mailer.transports`
     * @param Transport $factory `mailer.transport_factory` (every installed bridge)
     * @param EventDispatcherInterface|null $dispatcher receives the delivery events (1.5)
     * @param ClockInterface|null $clock time of the delivery events (1.5)
     * @param EmailTracker|null $tracker open / click tracking (1.10, `tracking.enabled`)
     */
    public function __construct(
        private readonly TransportInterface $fallback,
        private readonly MailerDsnProviderInterface $provider,
        private readonly MailerDsnValidator $validator,
        private readonly Transport $factory,
        private readonly SenderPolicy $sender,
        private readonly ?SentMailRecorder $recorder = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly ?ClockInterface $clock = null,
        private readonly ?EmailTracker $tracker = null,
    ) {
    }

    /**
     * Sends through the runtime DSN (or the framework transport) and records the outcome.
     */
    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $this->sender->apply($message);
        $named = $message instanceof Message && $message->getHeaders()->has('X-Transport');
        $dsn   = $named ? null : $this->provider->getDsn();

        return $this->deliver($message, $envelope, $dsn);
    }

    /**
     * Sends through an explicit DSN (validated like a stored one) — the Resend test uses the stored key even while
     * another provider is selected.
     */
    public function sendVia(RuntimeDsn $dsn, RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $this->sender->apply($message);

        return $this->deliver($message, $envelope, $dsn);
    }

    /**
     * Validation result of a DSN, memoized for the last DSN checked (readiness checks run on every page that asks).
     *
     * @return string|null null when valid, else the error translation key
     */
    public function check(RuntimeDsn $dsn): ?string
    {
        if ($this->checkedDsn !== $dsn->dsn) {
            $this->checkedError = $this->validator->validate($dsn->dsn);
            $this->checkedDsn   = $dsn->dsn;
        }

        return $this->checkedError;
    }

    /**
     * Redacted label of the transport the next send would use (`smtp://mail.example.com:587`, `default`).
     */
    public function currentLabel(): string
    {
        $dsn = $this->provider->getDsn();

        return $dsn instanceof RuntimeDsn ? $dsn->label() : self::FALLBACK_LABEL;
    }

    /**
     * Drops the cached transport and validation (next send rebuilds from the current DSN).
     */
    public function reset(): void
    {
        // Reset every memoized property here so the worker-state audit (Igor) sees it.
        $this->resolved     = null;
        $this->resolvedDsn  = null;
        $this->checkedDsn   = null;
        $this->checkedError = null;
        $this->notifying    = false;
    }

    /**
     * `mail-kit://runtime`.
     */
    public function __toString(): string
    {
        return 'mail-kit://runtime';
    }

    private function deliver(RawMessage $message, ?Envelope $envelope, ?RuntimeDsn $dsn): ?SentMessage
    {
        $snapshot = $message instanceof Message ? clone $message : $message;
        $id       = $message instanceof Message ? MailHeaders::value($message, MailHeaders::LOG_ID) : null;
        $tag      = $message instanceof Message ? MailHeaders::value($message, MailHeaders::TAG) : null;
        $tracked  = $message instanceof Message ? $this->tracker?->track($message, $envelope, $id) : null;
        // The caller's message is never changed (audit NU-H1): a Messenger retry re-sends that very object, which must
        // still carry the log row id, the tag, the redaction values and the tracking opt-outs. Only the copy that
        // leaves the application loses the internal headers.
        $outgoing = $tracked instanceof TrackedEmail ? $tracked->email : ($message instanceof Message ? clone $message : $message);
        if ($outgoing instanceof Message) {
            MailHeaders::strip($outgoing);
        }
        $label = $dsn instanceof RuntimeDsn ? MailerDsnAudit::scheme($dsn->dsn) : self::FALLBACK_LABEL;

        try {
            $sent = ($dsn instanceof RuntimeDsn ? $this->transportFor($dsn) : $this->fallback)->send($outgoing, $envelope);
        } catch (Throwable $exception) {
            if ($tracked instanceof TrackedEmail) {
                $this->tracker?->discard($tracked);
            }
            // Scrubbed message + reference only: no provider body, no credential, no `previous` (CA M1 / L3 / I3).
            $failure = DeliveryFailedException::from($exception, ...$this->credentials($dsn));
            $logId   = $this->recorder?->failed($id, $snapshot, $label, $failure->getMessage()) ?? $id;
            $this->logger?->warning('Mail delivery failed.', [
                'bundle'    => 'NowoMailKitBundle',
                'transport' => $dsn instanceof RuntimeDsn ? $dsn->label() : self::FALLBACK_LABEL,
                'error'     => $exception::class,
                'reference' => $failure->reference,
                'detail'    => $failure->detail,
            ]);
            $this->notify(new MailDeliveryFailedEvent($logId, $tag, $label, $failure->reference, $this->now()));

            throw $failure;
        }

        $size = $sent instanceof SentMessage ? strlen($sent->toString()) : null;
        // A raw (non-MIME) message has no Message-ID: SentMessage leaves it unset.
        $providerId = $sent instanceof SentMessage && $sent->getOriginalMessage() instanceof Message ? $sent->getMessageId() : null;
        $logId      = $this->recorder?->sent($id, $snapshot, $label, $providerId, $size) ?? $id;
        if ($tracked instanceof TrackedEmail && $logId !== null && $logId !== $id) {
            $this->tracker?->attachLog($tracked, $logId);
        }
        $this->notify(new MailDeliveredEvent($logId, $tag, $label, $providerId, $size, $this->now(), $tracked?->sendId));

        return $sent;
    }

    /**
     * Dispatches a delivery event; a listener failure is logged (the message was already sent or already failed).
     *
     * A message sent synchronously from a delivery listener (an alert about a failure, through the same transport)
     * is delivered and logged, but dispatches no event of its own: one level only, so a failing transport cannot
     * recurse (audit KI-M-L1). Send such alerts asynchronously (Messenger) to get their events.
     */
    private function notify(MailDeliveredEvent|MailDeliveryFailedEvent $event): void
    {
        if (!$this->dispatcher instanceof EventDispatcherInterface) {
            return;
        }
        if ($this->notifying) {
            $this->logger?->notice('Mail delivery event not dispatched: the message was sent from a delivery listener.', [
                'bundle' => 'NowoMailKitBundle',
                'event'  => $event::class,
            ]);

            return;
        }
        $this->notifying = true;
        try {
            $this->dispatcher->dispatch($event);
        } catch (Throwable $exception) {
            $this->logger?->error('Mail delivery listener failed.', [
                'bundle' => 'NowoMailKitBundle',
                'event'  => $event::class,
                'error'  => $exception::class,
            ]);
        } finally {
            $this->notifying = false;
        }
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock ?? new NativeClock())->now();
    }

    /**
     * Credentials of a DSN (user, password, decoded and raw) for the error scrubber.
     *
     * @return list<string>
     */
    private function credentials(?RuntimeDsn $dsn): array
    {
        if (!$dsn instanceof RuntimeDsn) {
            return [];
        }
        try {
            $parsed = Dsn::fromString($dsn->dsn);
        } catch (Throwable) {
            return [];
        }
        $list = [];
        foreach ([$parsed->getUser(), $parsed->getPassword()] as $value) {
            if ($value !== null && $value !== '') {
                $list[] = $value;
            }
        }

        return $list;
    }

    private function transportFor(RuntimeDsn $dsn): TransportInterface
    {
        if ($this->resolved instanceof TransportInterface && $this->resolvedDsn === $dsn->dsn) {
            return $this->resolved;
        }

        $error = $this->check($dsn);
        if ($error !== null) {
            throw new TransportException(sprintf('The mailer DSN from the %s settings was refused (%s); fix it in the mail settings.', $dsn->source === DsnSource::Resend ? 'Resend' : 'mailer', $error));
        }

        $this->resolved    = $this->factory->fromString($dsn->dsn);
        $this->resolvedDsn = $dsn->dsn;

        return $this->resolved;
    }
}
