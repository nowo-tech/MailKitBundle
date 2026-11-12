<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tracking;

use DateTimeImmutable;

/**
 * Open statistics of one tracked send (1.10, {@see EmailTrackingReaderInterface}): human opens and machine opens
 * (Apple Mail Privacy Protection, image proxies, scanners) counted apart, the truncated address and user-agent family
 * of the last open.
 *
 * Plain PHP value object (no Doctrine, Symfony or HTTP types).
 */
final readonly class OpenStats
{
    /**
     * @param string $sendId opaque send id
     * @param string|null $logId sent-mail log row id (when the log is on)
     * @param string|null $templateId template id of the e-mail
     * @param string|null $recipientId host recipient id
     * @param string|null $tenant tenant key
     * @param DateTimeImmutable $sentAt when the send was prepared
     * @param int $opens human opens
     * @param DateTimeImmutable|null $firstOpenAt first human open
     * @param DateTimeImmutable|null $lastOpenAt last human open
     * @param int $machineOpens machine opens
     * @param DateTimeImmutable|null $machineFirstOpenAt first machine open
     * @param DateTimeImmutable|null $machineLastOpenAt last machine open
     * @param string|null $lastOpenIp truncated address of the last open (IPv4 /24, IPv6 /48)
     * @param string|null $lastOpenFamily user-agent family of the last open ({@see OpenClassifier::FAMILIES})
     */
    public function __construct(
        public string $sendId,
        public ?string $logId,
        public ?string $templateId,
        public ?string $recipientId,
        public ?string $tenant,
        public DateTimeImmutable $sentAt,
        public int $opens,
        public ?DateTimeImmutable $firstOpenAt,
        public ?DateTimeImmutable $lastOpenAt,
        public int $machineOpens,
        public ?DateTimeImmutable $machineFirstOpenAt,
        public ?DateTimeImmutable $machineLastOpenAt,
        public ?string $lastOpenIp,
        public ?string $lastOpenFamily,
    ) {
    }

    /**
     * True when a human opened the e-mail at least once.
     */
    public function opened(): bool
    {
        return $this->opens > 0;
    }
}
