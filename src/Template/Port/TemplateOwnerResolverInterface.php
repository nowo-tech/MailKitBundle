<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Template\Port;

use Nowo\MailKitBundle\Template\Value\TemplateActor;

/**
 * Host port (2.7.0, `templates.owner_resolver`): the owner references of a user, for the UserKit GDPR bridge
 * (`email_templates` export section and eraser). Required when `templates.enabled`, `personal_data.enabled` and UserKit
 * are all on. Return the same references the host puts in {@see TemplateActor::$owner}.
 */
interface TemplateOwnerResolverInterface
{
    /**
     * The user's owner references (possibly none).
     *
     * @return list<string>
     */
    public function ownerIdsFor(object $user): array;
}
