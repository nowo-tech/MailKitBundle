<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Template\Port;

use Nowo\MailKitBundle\Template\Value\TemplateActor;
use Nowo\MailKitBundle\Template\Value\TemplatePermission;

/**
 * Host port (2.7.0, `templates.permission`): may this actor do that? Asked on every call, never cached by MailKit.
 * Without a host service MailKit uses {@see DenyAllTemplatePermission} (deny by default). The host should also check
 * that the actor really belongs to its tenant. MailKit adds the fixed rules: tenant isolation on every query and
 * personal rows visible to their owner only.
 */
interface TemplatePermissionInterface
{
    /**
     * True to allow.
     */
    public function isGranted(TemplatePermission $permission, TemplateActor $actor): bool;
}
