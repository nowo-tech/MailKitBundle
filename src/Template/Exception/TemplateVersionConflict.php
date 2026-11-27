<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Template\Exception;

use RuntimeException;

/**
 * The row changed since the actor read it (2.7.0, optimistic version): nothing was written. Reload and retry, or show
 * the newer version (map it to 409).
 */
final class TemplateVersionConflict extends RuntimeException implements TemplateException
{
    /**
     * @param int $expected the version the caller passed
     */
    public function __construct(public readonly int $expected)
    {
        parent::__construct('The template was changed by someone else.');
    }
}
