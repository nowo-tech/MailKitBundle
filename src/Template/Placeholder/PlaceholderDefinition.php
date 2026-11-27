<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Template\Placeholder;

use InvalidArgumentException;

use function preg_match;

/**
 * One placeholder an editor may insert (2.7.0): its name inside the namespace and a label (plain text, already in the
 * host's language, or a translation key of the host's domain).
 */
final readonly class PlaceholderDefinition
{
    /**
     * @throws InvalidArgumentException for a name outside {@see PlaceholderProviderInterface::NAME_PATTERN}
     */
    public function __construct(
        public string $name,
        public string $label,
    ) {
        if (preg_match(PlaceholderProviderInterface::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException('A placeholder name must be 1 to 40 characters among a-z, 0-9 and "_".');
        }
    }
}
