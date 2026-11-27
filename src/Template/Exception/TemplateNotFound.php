<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Template\Exception;

use Nowo\MailKitBundle\Template\Value\TemplatePermission;
use RuntimeException;

/**
 * No template or snippet with that id (or key) that the actor may see (2.7.0): another tenant's row, another
 * person's personal row, a shared row without {@see TemplatePermission::UseShared}
 * and a missing row all read the same (map it to 404).
 */
final class TemplateNotFound extends RuntimeException implements TemplateException
{
    /**
     * Fixed message.
     */
    public function __construct()
    {
        parent::__construct('Template not found.');
    }
}
