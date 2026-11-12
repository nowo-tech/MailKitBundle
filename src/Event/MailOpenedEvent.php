<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Event;

use DateTimeImmutable;

/**
 * Dispatched (PSR-14, FQCN as event name) after a human open of a tracked e-mail was counted (1.10, open pixel). Ids
 * only: the send id (match it with {@see MailDeliveredEvent::$trackingId}); read the counters through
 * `Tracking\EmailTrackingReaderInterface`. Not dispatched for an unknown, purged or erased send. A listener that throws
 * is logged and never changes the pixel response.
 */
final readonly class MailOpenedEvent
{
    /**
     * @param string $sendId opaque send id (32 hex characters)
     * @param DateTimeImmutable $occurredAt when the pixel was fetched
     */
    public function __construct(
        public string $sendId,
        public DateTimeImmutable $occurredAt,
    ) {
    }
}
