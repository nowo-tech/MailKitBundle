<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Template\Value;

use Nowo\MailKitBundle\Template\Port\TemplatePermissionInterface;

/**
 * What the host's {@see TemplatePermissionInterface} is asked (2.7.0, deny by
 * default). The owner-only rule of personal rows and the tenant scope are fixed by MailKit; the host cannot widen them.
 */
enum TemplatePermission: string
{
    /** Create, edit, delete, list and render one's own personal templates and snippets. */
    case Personal = 'personal';

    /** List, read and render the shared templates and snippets of the actor's tenant. */
    case UseShared = 'use_shared';

    /** Create, edit and delete the shared templates and snippets of the actor's tenant (Beacon: `crm.manage`). */
    case ManageShared = 'manage_shared';
}
