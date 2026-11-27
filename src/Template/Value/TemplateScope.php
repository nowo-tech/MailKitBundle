<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Template\Value;

/**
 * Who sees a template or snippet (2.7.0): `personal` = its owner only; `shared` = everyone of its tenant whom the host
 * grants {@see TemplatePermission::UseShared}. The scope of a stored row never changes.
 */
enum TemplateScope: string
{
    case Personal = 'personal';
    case Shared   = 'shared';
}
