<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\EventListener;

use Nowo\MailKitBundle\Security\AccessGuard;
use Symfony\Component\HttpKernel\Event\RequestEvent;

use function is_array;
use function is_string;
use function ltrim;
use function rtrim;
use function str_starts_with;

/**
 * REQ-UI-002 layer 2: denies every request under `web_ui.path_prefix` (and every sub-request to a bundle
 * controller) unless the access checker grants the current user. Runs after the firewall (priority 8) and before
 * the controller.
 */
final readonly class MailKitAccessListener
{
    /** Listener priority (after the firewall, 8). */
    public const PRIORITY = 7;

    /** Controller namespace of the bundle. */
    public const CONTROLLER_NAMESPACE = 'Nowo\\MailKitBundle\\Controller\\';

    /**
     * @param string $pathPrefix `web_ui.path_prefix`
     */
    public function __construct(
        private AccessGuard $guard,
        private string $pathPrefix,
    ) {
    }

    /**
     * Denies the request when the user may not open the UI.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        $request            = $event->getRequest();
        $isBundleController = $this->isBundleController($request->attributes->get('_controller'));
        if (!$event->isMainRequest()) {
            if (!$isBundleController) {
                return;
            }
        } else {
            $prefix = rtrim($this->pathPrefix, '/');
            $path   = $request->getPathInfo();
            if (!$isBundleController && $path !== $prefix && !str_starts_with($path, $prefix . '/')) {
                return;
            }
        }

        if (!$this->guard->isGranted()) {
            $this->guard->deny('Access denied to the mail kit UI.');
        }
    }

    private function isBundleController(mixed $controller): bool
    {
        if (is_array($controller)) {
            $controller = $controller[0] ?? null;
        }

        return is_string($controller) && str_starts_with(ltrim($controller, '\\'), self::CONTROLLER_NAMESPACE);
    }
}
