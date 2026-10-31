<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Security;

use Nowo\InstanceSettingsBundle\Security\InstanceSettingsAccessCheckerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function is_object;

/**
 * Applies the MailKit access checker to the current user and, for the settings actions, the Instance Settings
 * access of the section as well (the user must be able to open that settings page). The token is trusted only when
 * a firewall matched the main request.
 */
final readonly class AccessGuard
{
    /**
     * @param InstanceSettingsAccessCheckerInterface|null $settingsChecker Instance Settings checker (null when its admin UI is off: the actions are then denied)
     */
    public function __construct(
        private MailKitAccessCheckerInterface $accessChecker,
        private ?InstanceSettingsAccessCheckerInterface $settingsChecker = null,
        private ?TokenStorageInterface $tokenStorage = null,
        private ?RequestStack $requestStack = null,
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
            && $this->settingsChecker instanceof InstanceSettingsAccessCheckerInterface
            && $this->settingsChecker->canAccess($this->user(), $section);
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
