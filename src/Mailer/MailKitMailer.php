<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailer;

use Nowo\MailKitBundle\Log\SentMailRecorder;
use Nowo\MailKitBundle\Sender\SenderPolicy;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

/**
 * Decorates the framework `MailerInterface` (`mailer.mailer`): fills the runtime sender (From / Reply-To from the
 * settings) **before** the framework mailer builds the envelope or queues the message, and — when the mailer
 * dispatches to Messenger — records a "queued" row whose id travels in {@see MailHeaders::LOG_ID} so the worker's
 * {@see RuntimeTransport} resolves it. The DSN itself is resolved per send by {@see RuntimeTransport}.
 */
final readonly class MailKitMailer implements MailerInterface
{
    /**
     * @param MailerInterface $inner the decorated framework mailer
     * @param bool $queues the framework mailer has a message bus (async delivery possible)
     */
    public function __construct(
        private MailerInterface $inner,
        private SenderPolicy $sender,
        private ?SentMailRecorder $recorder = null,
        private bool $queues = false,
    ) {
    }

    /**
     * Applies the sender, records "queued" (bus only), then delegates.
     */
    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        $this->sender->apply($message);
        if ($this->queues && $this->recorder instanceof SentMailRecorder && $message instanceof Message && !$message->getHeaders()->has(MailHeaders::LOG_ID)) {
            $id = $this->recorder->queued($message);
            if ($id !== null) {
                $message->getHeaders()->addTextHeader(MailHeaders::LOG_ID, $id);
            }
        }

        $this->inner->send($message, $envelope);
    }
}
