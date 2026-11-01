<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Routing;

use Nowo\MailKitBundle\Controller\MailKitController;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Attribute\Route as RouteAttribute;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

use function is_string;
use function rtrim;
use function str_starts_with;

/**
 * Route loader `nowo_mail_kit` (REQ-SF-004 rule 7: the only way to expose the bundle routes). Reads the `#[Route]`
 * attributes of {@see MailKitController} and mounts them under `web_ui.path_prefix`, leaving out the groups that are
 * switched off: the log pages without `log.enabled`, the body page without `log.store_bodies`, the CSV export without
 * `web_ui.export.enabled`, the Resend actions without `resend.enabled`. Nothing is loaded when `web_ui.enabled` is
 * false.
 *
 * ```yaml
 * # config/routes/nowo_mail_kit.yaml
 * nowo_mail_kit:
 *     resource: .
 *     type: nowo_mail_kit
 * ```
 */
final class MailKitRouteLoader extends Loader
{
    /** Loader type. */
    public const TYPE = 'nowo_mail_kit';

    private bool $loaded = false;

    /**
     * @param string $pathPrefix `web_ui.path_prefix`
     * @param bool $exportEnabled `web_ui.export.enabled`
     */
    public function __construct(
        private readonly bool $webUiEnabled,
        private readonly string $pathPrefix,
        private readonly bool $logEnabled = true,
        private readonly bool $storeBodies = false,
        private readonly bool $resendEnabled = false,
        ?string $env = null,
        private readonly bool $exportEnabled = true,
    ) {
        parent::__construct($env);
    }

    /**
     * Builds the collection (once).
     */
    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        if ($this->loaded) {
            throw new RuntimeException('Do not add the "nowo_mail_kit" route loader twice.');
        }
        // @igor-ignore - Route loader builds routes at cache warm-up; not per-request state.
        $this->loaded = true;

        $collection = new RouteCollection();
        if (!$this->webUiEnabled) {
            return $collection;
        }

        $prefix = rtrim($this->pathPrefix, '/');
        foreach ((new ReflectionClass(MailKitController::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(RouteAttribute::class) as $attribute) {
                $declared = $attribute->newInstance();
                $path     = is_string($declared->path) ? $declared->path : '';
                if (!$this->enabled($path)) {
                    continue;
                }
                $collection->add((string) $declared->name, new Route(
                    $prefix . $path,
                    ['_controller' => MailKitController::class . '::' . $method->getName()],
                    $declared->requirements,
                    methods: $declared->methods,
                ));
            }
        }

        return $collection;
    }

    /**
     * Type `nowo_mail_kit`.
     */
    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === self::TYPE;
    }

    private function enabled(string $path): bool
    {
        return match (true) {
            str_starts_with($path, '/actions/resend') => $this->resendEnabled,
            str_starts_with($path, '/log/{id}/body')  => $this->logEnabled && $this->storeBodies,
            str_starts_with($path, '/log/export')     => $this->logEnabled && $this->exportEnabled,
            str_starts_with($path, '/log')            => $this->logEnabled,
            default                                   => true,
        };
    }
}
