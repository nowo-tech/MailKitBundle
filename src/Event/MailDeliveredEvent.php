<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Event;

use DateTimeImmutable;
use Nowo\MailKitBundle\Mailer\MailHeaders;

/**
 * Dispatched (PSR-14, FQCN as event name) after the runtime transport handed a message to the provider (1.5, DDD P2).
 *
 * Ids and labels only: never addresses, subject, body or DSN. `logId` points to the sent-mail log row: null with
 * `log.enabled: false`; when the log write failed, the id of the "queued" row of a queued message (if any), else
 * null. `tag` is the {@see MailHeaders::tag()} category (host free text: keep personal data out of it).
 *
 * The delivery is an external fact: the event follows the send and the log write, it is not held back by a database
 * transaction the host may have open. A listener that throws is logged and never fails the send. A message sent
 * synchronously from a delivery listener dispatches no event of its own (re-entrancy guard).
 */
final readonly class MailDeliveredEvent
{
    /**
     * @param string|null $logId sent-mail log row id
     * @param string|null $tag message category (`password_reset`, `invoice`…)
     * @param string $transport transport scheme label (`smtp`, `resend+api`, `default` for the framework transport)
     * @param string|null $providerMessageId message id returned by the transport
     * @param int|null $size size of the sent message in bytes
     * @param DateTimeImmutable $occurredAt when the send completed
     * @param string|null $trackingId opaque send id of the open / click tracking (1.10), null when the e-mail was not
     *                                tracked
     */
    public function __construct(
        public ?string $logId,
        public ?string $tag,
        public string $transport,
        public ?string $providerMessageId,
        public ?int $size,
        public DateTimeImmutable $occurredAt,
        public ?string $trackingId = null,
    ) {
    }
}
