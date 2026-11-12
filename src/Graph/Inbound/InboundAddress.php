<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Inbound;

use Nowo\MailKitBundle\Graph\Send\PlusAddress;

use function mb_substr;
use function preg_replace;
use function strtolower;
use function trim;

/**
 * An address of an inbound message (1.9): lower-cased address, display name without control characters (at most 200
 * characters, or null) and the plus-address tag when the address is a plus address of the receiving mailbox
 * (`projects+t-42@…` → `t-42`). Values come from the sender: treat them as untrusted (escape on output).
 */
final class InboundAddress
{
    /** Lower-cased address (at most 254 characters). */
    public readonly string $address;

    /** Display name, or null. */
    public readonly ?string $name;

    /** Plus-address tag of the mailbox, or null. */
    public readonly ?string $tag;

    /**
     * @param string $mailbox the receiving mailbox (for the tag)
     */
    public function __construct(string $address, ?string $name, string $mailbox)
    {
        $this->address = mb_substr(strtolower(trim((string) preg_replace('/[\x00-\x20<>"]+/u', '', $address))), 0, 254);
        $name          = trim((string) preg_replace('/[\x00-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', ' ', (string) $name));
        $this->name    = $name === '' ? null : mb_substr($name, 0, 200);
        $this->tag     = PlusAddress::tag($this->address, $mailbox);
    }
}
