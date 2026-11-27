<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Template\Port;

use Nowo\MailKitBundle\Template\Value\TemplateActor;
use Nowo\MailKitBundle\Template\Value\TemplatePermission;

/**
 * Default permission port (2.7.0): refuses everything until the host configures `templates.permission`.
 */
final readonly class DenyAllTemplatePermission implements TemplatePermissionInterface
{
    /**
     * Always false.
     */
    public function isGranted(TemplatePermission $permission, TemplateActor $actor): bool
    {
        return false;
    }
}
