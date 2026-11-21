<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\MailKitBundle\Sender\SenderIdentityProviderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function array_pop;
use function in_array;
use function is_string;
use function sprintf;
use function str_contains;

/**
 * Refuses, when the container is built, a sender service that does not implement {@see SenderIdentityProviderInterface}
 * (REQ-ARCH-001 rule 3, the only sender port since 2.0).
 *
 * The extension points `SenderIdentityProviderInterface` at the service of `nowo_mail_kit.mailer.sender_provider` (or
 * at the built-in `MailSettings`). This pass then checks, before the decorators are resolved:
 *
 * - the configured service implements the port;
 * - every decorator of the port id, of the configured service and of the aliases on the way to it implements it too;
 * - nothing decorates the id of the Mime-typed `SenderProviderInterface`, removed in 2.0 ({@see self::REMOVED_PORT});
 * - no service definition carries a loading error about that interface. A host class registered by resource
 *   autodiscovery that still implements it cannot be loaded, so Symfony keeps the definition with only an error
 *   ("… not found while loading …") and its `#[AsDecorator]` / `#[AsAlias]` attributes are never read; unused, the
 *   definition would later be removed without a word and the host's sender change would be lost silently.
 *
 * A class that still implements the removed interface cannot even be loaded, so the classes are read through
 * {@see ContainerBuilder::getReflectionClass()} without throwing, and each failure names the service, its class and
 * UPGRADING "To 1.12.0" (the migration table) instead of failing later with a PHP fatal error or a `TypeError`. A service
 * whose class is only known at runtime (a factory or a synthetic service) cannot be checked here, and a service id with
 * no definition at all is left to the container, which reports the unknown service itself.
 *
 * @internal
 */
final class SenderPortPass implements CompilerPassInterface
{
    /** Id (and class name) of the Mime-typed sender port, deprecated since 1.12 and removed in 2.0. */
    public const REMOVED_PORT = 'Nowo\\MailKitBundle\\Sender\\SenderProviderInterface';

    /**
     * Checks the configured sender service and every decorator of the sender.
     *
     * @throws LogicException when the sender service or a decorator does not implement the port, or a service still
     *                        decorates the removed port id
     */
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $id => $definition) {
            foreach ($definition->getErrors() as $error) {
                if (str_contains((string) $error, self::REMOVED_PORT)) {
                    throw new LogicException(sprintf('The service "%s" cannot be loaded: %s %s', $id, (string) $error, $this->removedPortHint()));
                }
            }
            if (($definition->getDecoratedService()[0] ?? null) === self::REMOVED_PORT) {
                throw new LogicException(sprintf('The service "%s" decorates "%s", which was removed in MailKit 2.0. Decorate "%s" instead and implement it (see UPGRADING.md "To 1.12.0" and "To 2.0.0").', $id, self::REMOVED_PORT, SenderIdentityProviderInterface::class));
            }
        }
        if (!$container->hasAlias(SenderIdentityProviderInterface::class)) {
            return;
        }

        $configured = (string) $container->getAlias(SenderIdentityProviderInterface::class);
        $chain      = [SenderIdentityProviderInterface::class, $configured];
        $target     = $configured;
        while ($container->hasAlias($target) && !in_array((string) $container->getAlias($target), $chain, true)) {
            $target  = (string) $container->getAlias($target);
            $chain[] = $target;
        }
        if (!$container->hasDefinition($target)) {
            return; // No definition to look at: the container reports the unknown service itself.
        }
        if (!$this->implementsPort($container, $target)) {
            throw new LogicException(sprintf('nowo_mail_kit.mailer.sender_provider: the service "%s" (%s) must implement "%s". %s', $target, $this->className($container, $target), SenderIdentityProviderInterface::class, $this->removedPortHint()));
        }

        $this->assertDecorators($container, $chain);
    }

    /**
     * Fails on the first decorator of the given ids (and of their decorators) that does not implement the port.
     *
     * @param list<string> $ids
     */
    private function assertDecorators(ContainerBuilder $container, array $ids): void
    {
        $seen = [];
        while ($ids !== []) {
            $decorated = array_pop($ids);
            if (isset($seen[$decorated])) {
                continue;
            }
            $seen[$decorated] = true;
            foreach ($container->getDefinitions() as $id => $definition) {
                if (($definition->getDecoratedService()[0] ?? null) !== $decorated) {
                    continue;
                }
                if (!$this->implementsPort($container, $id)) {
                    throw new LogicException(sprintf('The service "%s" (%s) decorates the MailKit sender but does not implement "%s". %s', $id, $this->className($container, $id), SenderIdentityProviderInterface::class, $this->removedPortHint()));
                }
                $ids[] = $id;
            }
        }
    }

    /**
     * Second sentence of every message of this pass.
     */
    private function removedPortHint(): string
    {
        return sprintf('"%s" was removed in 2.0, so a class that still implements it no longer loads: implement "%s" (it returns "Nowo\MailKitBundle\Sender\MailAddress"; see UPGRADING.md "To 1.12.0" and "To 2.0.0").', self::REMOVED_PORT, SenderIdentityProviderInterface::class);
    }

    /**
     * Whether the service implements the port; true when its class is only known at runtime (factory, synthetic).
     */
    private function implementsPort(ContainerBuilder $container, string $service): bool
    {
        $definition = $container->getDefinition($service);
        if ($definition->getFactory() !== null || $definition->isSynthetic()) {
            return true;
        }
        $class = $this->className($container, $service);

        return $container->getReflectionClass($class, false)?->implementsInterface(SenderIdentityProviderInterface::class) ?? false;
    }

    /**
     * The class of a service definition (its id when no class is set), with parameters resolved.
     */
    private function className(ContainerBuilder $container, string $service): string
    {
        $class = $container->getParameterBag()->resolveValue($container->getDefinition($service)->getClass() ?? $service);

        return is_string($class) ? $class : $service;
    }
}
