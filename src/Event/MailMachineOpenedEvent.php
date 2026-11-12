<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Event;

use DateTimeImmutable;

/**
 * Dispatched (PSR-14, FQCN as event name) after a machine open of a tracked e-mail was counted (1.10): the pixel was
 * fetched by Apple Mail Privacy Protection, an image proxy or a scanner (`tracking.machine_opens`), which says nothing
 * about the recipient reading it. Ids only, like {@see MailOpenedEvent}.
 */
final readonly class MailMachineOpenedEvent
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
