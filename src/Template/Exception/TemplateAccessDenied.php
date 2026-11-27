<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Template\Exception;

use Nowo\MailKitBundle\Template\Value\TemplatePermission;
use RuntimeException;

/**
 * The host's permission port refused the action (2.7.0; map it to 403).
 */
final class TemplateAccessDenied extends RuntimeException implements TemplateException
{
    /**
     * @param TemplatePermission $permission the permission that was refused
     */
    public function __construct(public readonly TemplatePermission $permission)
    {
        parent::__construct('Template access denied (' . $permission->value . ').');
    }
}
