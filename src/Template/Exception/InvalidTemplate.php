<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Template\Exception;

use InvalidArgumentException;

/**
 * A draft breaks the grammar or a configured limit (2.7.0): key, locale, name, subject, body, size, placeholder count
 * or the per-scope cap. `field` names the offending input for a form error.
 */
final class InvalidTemplate extends InvalidArgumentException implements TemplateException
{
    /**
     * @param string $field `key`, `locale`, `name`, `subject`, `body`, `html`, `placeholders`, `scope` or `output`
     * @param string $message fixed text, no template content
     */
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
