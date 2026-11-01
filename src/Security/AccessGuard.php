<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Security;

use Nowo\InstanceSettingsBundle\Security\AccessGuard as InstanceSettingsAccessGuard;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function is_object;

/**
 * Applies the MailKit access checker to the current user and, for the settings actions, the Instance Settings
 * access of the section as well (the user must be able to open that settings page), and for the CSV export the
 * optional `web_ui.export.access_roles` on top of the UI access. The token is trusted only when a firewall matched
 * the main request.
 */
final readonly class AccessGuard
{
    /**
     * @param InstanceSettingsAccessGuard|null $settingsGuard Instance Settings access guard (its checker, `allow_unauthenticated` and firewall rules); null denies the actions
     * @param list<string> $exportRoles `web_ui.export.access_roles`: the export also needs any of them (empty = the UI access is enough)
     */
    public function __construct(
        private MailKitAccessCheckerInterface $accessChecker,
        private ?InstanceSettingsAccessGuard $settingsGuard = null,
        private ?TokenStorageInterface $tokenStorage = null,
        private ?RequestStack $requestStack = null,
        private array $exportRoles = [],
        private ?AuthorizationCheckerInterface $authorizationChecker = null,
    ) {
    }

    /**
     * The MailKit UI is open for the current user.
     */
    public function isGranted(): bool
    {
        return $this->accessChecker->canAccess($this->user());
    }

    /**
     * The MailKit UI and the Instance Settings section are open for the current user.
     */
    public function isSectionGranted(string $section): bool
    {
        return $this->isGranted()
            && $this->settingsGuard instanceof InstanceSettingsAccessGuard
            && $this->settingsGuard->isGranted($section);
    }

    /**
     * The MailKit UI is open and, when `web_ui.export.access_roles` is set, the user (behind a firewall) holds any of
     * those roles too. Fails closed without an authorization checker.
     */
    public function isExportGranted(): bool
    {
        if (!$this->isGranted()) {
            return false;
        }
        if ($this->exportRoles === []) {
            return true;
        }
        if ($this->user() === null || !$this->authorizationChecker instanceof AuthorizationCheckerInterface) {
            return false;
        }
        foreach ($this->exportRoles as $role) {
            if ($this->authorizationChecker->isGranted($role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Current security user behind a firewall, or null.
     */
    public function user(): ?object
    {
        $mainRequest = $this->requestStack?->getMainRequest();
        if (!$mainRequest instanceof Request || !$mainRequest->attributes->has('_firewall_context')) {
            return null;
        }
        $user = $this->tokenStorage?->getToken()?->getUser();

        return is_object($user) ? $user : null;
    }

    /**
     * 403 (or the firewall entry point for anonymous users behind a firewall).
     */
    public function deny(string $message): never
    {
        $mainRequest = $this->requestStack?->getMainRequest();
        if ($mainRequest instanceof Request && $mainRequest->attributes->has('_firewall_context')) {
            throw new AccessDeniedException($message);
        }

        throw new AccessDeniedHttpException($message);
    }
}
