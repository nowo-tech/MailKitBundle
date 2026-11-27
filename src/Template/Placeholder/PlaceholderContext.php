<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Template\Placeholder;

use InvalidArgumentException;
use Nowo\MailKitBundle\Template\Value\TemplateActor;

use function count;
use function is_string;
use function preg_match;

/**
 * What a render is about (2.7.0): the actor, the template locale and the host's subject references, such as
 * `['contact' => '42', 'deal' => '7']`. MailKit passes the references through untouched; each provider decides what
 * they mean and whether the actor may see them.
 */
final readonly class PlaceholderContext
{
    /** Most subject references. */
    public const MAX_SUBJECTS = 16;

    /**
     * @param array<string, string> $subjects name (`[a-z][a-z0-9_]{0,31}`) => reference (1 to 128 characters of
     *                                        {@see TemplateActor::REF_PATTERN})
     *
     * @throws InvalidArgumentException for a malformed name or reference, or more than {@see MAX_SUBJECTS}
     */
    public function __construct(
        public TemplateActor $actor,
        public string $locale,
        public array $subjects = [],
    ) {
        if (count($subjects) > self::MAX_SUBJECTS) {
            throw new InvalidArgumentException('Too many subject references.');
        }
        foreach ($subjects as $name => $ref) {
            if (!is_string($name) || preg_match(PlaceholderProviderInterface::NAMESPACE_PATTERN, $name) !== 1 || !is_string($ref) || preg_match(TemplateActor::REF_PATTERN, $ref) !== 1) {
                throw new InvalidArgumentException('A subject reference must be named like a placeholder namespace and look like an id.');
            }
        }
    }

    /**
     * The reference of a subject, or null.
     */
    public function subject(string $name): ?string
    {
        return $this->subjects[$name] ?? null;
    }
}
