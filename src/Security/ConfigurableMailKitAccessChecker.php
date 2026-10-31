<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Security;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Default checker: the user holds any of `security.access_roles` (never empty: the configuration refuses an empty
 * list, so this checker cannot fail open). Anonymous users are denied.
 */
final readonly class ConfigurableMailKitAccessChecker implements MailKitAccessCheckerInterface
{
    /**
     * @param list<string> $accessRoles non-empty role list
     */
    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
        private array $accessRoles,
    ) {
    }

    /**
     * Any role granted.
     */
    public function canAccess(?object $user): bool
    {
        if ($user === null) {
            return false;
        }
        foreach ($this->accessRoles as $role) {
            if ($this->authorizationChecker->isGranted($role)) {
                return true;
            }
        }

        return false;
    }
}
