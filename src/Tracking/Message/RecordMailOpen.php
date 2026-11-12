<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tracking\Message;

/**
 * Messenger message of one open (1.10, with `tracking.bus`): the pixel endpoint only dispatches it; the handler does
 * the database write. Carries no full address and no user-agent string: the address is already truncated and the
 * user agent reduced to its family.
 */
final readonly class RecordMailOpen
{
    /**
     * @param string $sendId verified send id
     * @param bool $machine machine open
     * @param string|null $ip truncated address (IPv4 /24, IPv6 /48)
     * @param string|null $family user-agent family
     * @param int $at UNIX seconds of the fetch
     */
    public function __construct(
        public string $sendId,
        public bool $machine,
        public ?string $ip,
        public ?string $family,
        public int $at,
    ) {
    }
}
