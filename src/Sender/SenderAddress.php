<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Sender;

use Symfony\Component\Mime\Address;
use Throwable;

use function filter_var;
use function str_contains;
use function str_ends_with;
use function strrpos;
use function strtolower;
use function substr;
use function trim;

use const FILTER_VALIDATE_EMAIL;

/**
 * Sender address rules: a valid address (optionally `Name <address>`) on a public domain — at least one dot, not
 * `.local` / `.localhost` / `.internal`, no trailing dot.
 */
final class SenderAddress
{
    /**
     * True when the address can be used as a sender with a real provider.
     */
    public static function isDeliverable(?string $from): bool
    {
        $from = trim((string) $from);
        if ($from === '') {
            return false;
        }

        try {
            $address = Address::create($from)->getAddress();
        } catch (Throwable) {
            return false;
        }

        $at     = strrpos($address, '@');
        $domain = $at === false ? '' : strtolower(substr($address, $at + 1));

        return str_contains($domain, '.')
            && !str_ends_with($domain, '.')
            && !str_ends_with($domain, '.local')
            && !str_ends_with($domain, '.localhost')
            && !str_ends_with($domain, '.internal')
            && filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
    }
}
