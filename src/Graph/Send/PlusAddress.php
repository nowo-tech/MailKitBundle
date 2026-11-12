<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Send;

use Nowo\MailKitBundle\Graph\GraphException;

use function explode;
use function preg_match;
use function str_contains;
use function strrpos;
use function strtolower;
use function substr;
use function substr_count;
use function trim;

/**
 * Exchange Online plus addressing (1.8): `projects+t-42@example.com` is delivered to `projects@example.com`. Use it as
 * the Reply-To of a message so a reply names its thread (spec 229: one tag per task).
 */
final class PlusAddress
{
    /** Tags: letters, digits, `.`, `_`, `=`, `-`; at most 64 characters. */
    public const TAG_PATTERN = '/^[A-Za-z0-9._=-]{1,64}\z/';

    /**
     * `local+tag@domain` of a mailbox address (an existing tag of the mailbox is replaced).
     */
    public static function compose(string $mailbox, string $tag): string
    {
        if (preg_match(self::TAG_PATTERN, $tag) !== 1) {
            throw GraphException::invalidMessage('Invalid plus-address tag.');
        }
        [$local, $domain] = self::split($mailbox);

        return $local . '+' . $tag . '@' . $domain;
    }

    /**
     * The tag of an address when it is a plus address of the mailbox (case-insensitive local part and domain), else null.
     */
    public static function tag(string $address, string $mailbox): ?string
    {
        try {
            [$local, $domain]       = self::split($address, false);
            [$boxLocal, $boxDomain] = self::split($mailbox);
        } catch (GraphException) {
            return null;
        }
        $plus = strrpos($local, '+');
        if ($plus === false || strtolower($domain) !== strtolower($boxDomain) || strtolower(substr($local, 0, $plus)) !== strtolower($boxLocal)) {
            return null;
        }
        $tag = substr($local, $plus + 1);

        return preg_match(self::TAG_PATTERN, $tag) === 1 ? $tag : null;
    }

    /**
     * @return array{0: string, 1: string} local part (without its own tag when `$stripTag`) and domain
     */
    private static function split(string $address, bool $stripTag = true): array
    {
        $address = trim($address);
        if (substr_count($address, '@') !== 1) {
            throw GraphException::invalidMessage('Invalid mailbox address.');
        }
        [$local, $domain] = explode('@', $address);
        if ($local === '' || $domain === '') {
            throw GraphException::invalidMessage('Invalid mailbox address.');
        }
        if ($stripTag && str_contains($local, '+')) {
            $local = substr($local, 0, (int) strrpos($local, '+'));
            if ($local === '') {
                throw GraphException::invalidMessage('Invalid mailbox address.');
            }
        }

        return [$local, $domain];
    }
}
