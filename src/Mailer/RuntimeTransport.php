<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailer;

use Nowo\MailKitBundle\Dsn\DsnSource;
use Nowo\MailKitBundle\Dsn\MailerDsnAudit;
use Nowo\MailKitBundle\Dsn\MailerDsnProviderInterface;
use Nowo\MailKitBundle\Dsn\MailerDsnValidator;
use Nowo\MailKitBundle\Dsn\RuntimeDsn;
use Nowo\MailKitBundle\Log\SentMailRecorder;
use Nowo\MailKitBundle\Sender\SenderPolicy;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport;
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
 * The internal {@see MailHeaders} are removed before delivery; the outcome is written to the sent-mail log when it
 * is enabled.
 */
final class RuntimeTransport implements TransportInterface, ResetInterface
{
    /** Label (log transport column) of the framework transport. */
    public const FALLBACK_LABEL = 'default';

    private ?TransportInterface $resolved = null;

    private ?string $resolvedDsn = null;

    private ?string $checkedDsn = null;

    private ?string $checkedError = null;

    /**
     * @param TransportInterface $fallback the decorated `mailer.transports`
     * @param Transport $factory `mailer.transport_factory` (every installed bridge)
     */
    public function __construct(
        private readonly TransportInterface $fallback,
        private readonly MailerDsnProviderInterface $provider,
        private readonly MailerDsnValidator $validator,
        private readonly Transport $factory,
        private readonly SenderPolicy $sender,
        private readonly ?SentMailRecorder $recorder = null,
        private readonly ?LoggerInterface $logger = null,
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
     * the Resend section is switched off.
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
        if ($message instanceof Message) {
            MailHeaders::strip($message);
        }
        $label = $dsn instanceof RuntimeDsn ? MailerDsnAudit::scheme($dsn->dsn) : self::FALLBACK_LABEL;

        try {
            $sent = ($dsn instanceof RuntimeDsn ? $this->transportFor($dsn) : $this->fallback)->send($message, $envelope);
        } catch (Throwable $exception) {
            $this->recorder?->failed($id, $snapshot, $label, $exception);
            $this->logger?->warning('Mail delivery failed.', ['bundle' => 'NowoMailKitBundle', 'transport' => $dsn instanceof RuntimeDsn ? $dsn->label() : self::FALLBACK_LABEL, 'error' => $exception::class]);

            throw $exception;
        }

        $this->recorder?->sent($id, $snapshot, $label, $sent?->getMessageId(), $sent instanceof SentMessage ? strlen($sent->toString()) : null);

        return $sent;
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
