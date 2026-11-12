<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Send;

use Nowo\MailKitBundle\Graph\GraphException;

use function filter_var;
use function mb_substr;
use function preg_match;
use function preg_replace;
use function strlen;
use function trim;

use const FILTER_FLAG_EMAIL_UNICODE;
use const FILTER_VALIDATE_EMAIL;

/**
 * An e-mail address with an optional display name (1.8): To, Cc, Bcc and Reply-To of a {@see GraphOutgoingMail}.
 * Plus addresses (`projects+t-42@example.com`) are ordinary addresses; build them with {@see PlusAddress}.
 */
final class GraphRecipient
{
    /** Address, trimmed. */
    public readonly string $address;

    /** Display name without control characters (at most 200 characters), or null. */
    public readonly ?string $name;

    /**
     * @param string $address e-mail address
     * @param string|null $name display name
     */
    public function __construct(string $address, ?string $name = null)
    {
        $address = trim($address);
        if ($address === '' || strlen($address) > 254 || preg_match('/[\x00-\x20<>"]/', $address) === 1 || filter_var($address, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) === false) {
            throw GraphException::invalidMessage('Invalid recipient address.');
        }
        $this->address = $address;
        $name          = $name === null ? null : trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name));
        $this->name    = $name === null || $name === '' ? null : mb_substr($name, 0, 200);
    }
}
