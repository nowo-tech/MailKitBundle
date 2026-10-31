<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Security;

/**
 * DEV / DEMO only (`security.allow_unauthenticated: true`, refused without kernel.debug): everybody may open the UI.
 */
final class AllowAllMailKitAccessChecker implements MailKitAccessCheckerInterface
{
    /**
     * Always true.
     */
    public function canAccess(?object $user): bool
    {
        return true;
    }
}
