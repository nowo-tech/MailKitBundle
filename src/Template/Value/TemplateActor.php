<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Template\Value;

use InvalidArgumentException;

use function preg_match;

/**
 * Who acts on the template store (2.7.0): the host's tenant reference and the host's person reference (owner of the
 * personal rows, author of the shared ones). Built by the host from its own security context or, in a worker (a
 * sequence step), from the job it runs; MailKit never derives it from user input. Both refs are compared exactly.
 */
final readonly class TemplateActor
{
    /** Accepted form of a tenant or owner reference. */
    public const REF_PATTERN = '/^[A-Za-z0-9_.:@-]{1,128}\z/';

    /**
     * @param string $tenant the host's tenant reference (single-tenant hosts pass a constant such as "default")
     * @param string $owner the host's person reference
     *
     * @throws InvalidArgumentException when a reference breaks {@see REF_PATTERN}
     */
    public function __construct(
        public string $tenant,
        public string $owner,
    ) {
        self::assertRef($tenant, 'tenant');
        self::assertRef($owner, 'owner');
    }

    /**
     * Checks a tenant or owner reference.
     *
     * @throws InvalidArgumentException when the reference breaks {@see REF_PATTERN}
     */
    public static function assertRef(string $ref, string $what = 'owner'): void
    {
        if (preg_match(self::REF_PATTERN, $ref) !== 1) {
            throw new InvalidArgumentException('A template ' . $what . ' reference must be 1 to 128 characters among letters, digits, "_", ".", ":", "@" and "-".');
        }
    }
}
