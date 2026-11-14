<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Sender;

use InvalidArgumentException;

use function preg_match;
use function sprintf;
use function str_replace;
use function trim;

/**
 * Framework-free e-mail address of the sender ports (1.12, REQ-ARCH-001 rule 3): the value object
 * {@see SenderIdentityProviderInterface} takes and returns instead of the Symfony Mime `Address` of the deprecated
 * {@see SenderProviderInterface}.
 *
 * Holds the address and an optional display name, nothing else: no validation beyond "there is an address", so a host
 * domain can build one without a mailer installed. MailKit validates deliverability where it matters
 * ({@see SenderAddress::isDeliverable()}) and drops an address the mailer refuses.
 */
final readonly class MailAddress
{
    /** The e-mail address, trimmed (for example `no-reply@example.com`). */
    public string $email;

    /** Display name, trimmed, or null when the address has none. */
    public ?string $name;

    /**
     * @param string $email the e-mail address; blank is refused
     * @param string|null $name display name; null or blank means "no name"
     *
     * @throws InvalidArgumentException when `$email` is blank
     */
    public function __construct(string $email, ?string $name = null)
    {
        $email = trim($email);
        if ($email === '') {
            throw new InvalidArgumentException('Nowo\\MailKitBundle\\Sender\\MailAddress needs an e-mail address (an empty string was given).');
        }
        $name = trim((string) $name);

        $this->email = $email;
        $this->name  = $name === '' ? null : $name;
    }

    /**
     * The address of `no-reply@example.com` or `Acme <no-reply@example.com>` (the display name may be quoted).
     *
     * @throws InvalidArgumentException when no address is left after the display name
     */
    public static function fromString(string $address): self
    {
        $address = trim($address);
        if (preg_match('/^(?<name>.*)<(?<email>[^<>]*)>$/', $address, $matches) === 1) {
            return new self($matches['email'], trim(trim($matches['name']), '"\''));
        }

        return new self($address);
    }

    /**
     * `no-reply@example.com`, or `"Acme" <no-reply@example.com>` with a display name (quotes and backslashes in the
     * name are escaped).
     */
    public function toString(): string
    {
        if ($this->name === null) {
            return $this->email;
        }

        return sprintf('"%s" <%s>', str_replace(['\\', '"'], ['\\\\', '\\"'], $this->name), $this->email);
    }

    /**
     * Same as {@see self::toString()}.
     */
    public function __toString(): string
    {
        return $this->toString();
    }
}
