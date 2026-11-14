<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Sender;

use Symfony\Component\Mime\Address;
use Throwable;

/**
 * Translates between the bundle value object {@see MailAddress} of the sender ports and the Symfony Mime `Address`
 * the message headers need (1.12). Infrastructure, not part of the ports: the framework type stops here.
 *
 * Stateless (static methods, no memoization): REQ-RUNTIME-002.
 *
 * @internal
 */
final class MimeAddressMapper
{
    /**
     * The value object of a Mime address, or null for null. A blank display name becomes `null`.
     */
    public static function toValueObject(?Address $address): ?MailAddress
    {
        if (!$address instanceof Address) {
            return null;
        }

        return new MailAddress($address->getAddress(), $address->getName());
    }

    /**
     * The Mime address of a value object, or null for null — and null when the mailer refuses the address, the same
     * leniency as the settings sender: a message then goes out without that header instead of failing the send.
     */
    public static function toMimeAddress(?MailAddress $address): ?Address
    {
        if (!$address instanceof MailAddress) {
            return null;
        }

        try {
            return new Address($address->email, (string) $address->name);
        } catch (Throwable) {
            return null;
        }
    }
}
