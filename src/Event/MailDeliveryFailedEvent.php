<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Event;

use DateTimeImmutable;
use Nowo\MailKitBundle\Mailer\DeliveryFailedException;

/**
 * Dispatched (PSR-14, FQCN as event name) when the runtime transport failed to send a message (1.5, DDD P2), before
 * the {@see DeliveryFailedException} is thrown.
 *
 * Ids and labels only: never addresses, subject, body, DSN or the provider's error text. `reference` is the one of the
 * exception (also in the application log and the sent-mail log row `logId`). A listener that throws is logged and
 * never replaces the delivery failure.
 */
final readonly class MailDeliveryFailedEvent
{
    /**
     * @param string|null $logId sent-mail log row id
     * @param string|null $tag message category
     * @param string $transport transport scheme label (`smtp`, `resend+api`, `default`)
     * @param string $reference support reference of the failure
     * @param DateTimeImmutable $occurredAt when the send failed
     */
    public function __construct(
        public ?string $logId,
        public ?string $tag,
        public string $transport,
        public string $reference,
        public DateTimeImmutable $occurredAt,
    ) {
    }
}
