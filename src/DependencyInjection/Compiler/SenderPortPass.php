<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\MailKitBundle\Sender\SenderIdentityProviderInterface;
use Nowo\MailKitBundle\Sender\SenderProviderGate;
use Nowo\MailKitBundle\Sender\SenderProviderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function class_exists;
use function is_a;
use function is_string;
use function sprintf;

/**
 * Wires the framework-free 1.12 twin of the sender port (REQ-ARCH-001 rule 3) and refuses a
 * `nowo_mail_kit.mailer.sender_provider` service that implements neither interface.
 *
 * `nowo_mail_kit.mailer.sender_provider` takes a service implementing either {@see SenderIdentityProviderInterface}
 * (the twin, `MailAddress`) or the deprecated {@see SenderProviderInterface} (Symfony Mime `Address`). The extension
 * points `SenderProviderInterface` at that service; this pass leaves that alias untouched when the service implements
 * the deprecated interface (today's wiring, and what a class implementing both is asked through until 2.0), and
 * otherwise points it at an internal {@see SenderProviderGate} over the service — so the sender policy, the test mail
 * and every decorator of the deprecated id keep working. The gate is used as well when the class cannot be known
 * before the container is compiled (a factory or a synthetic service): it adapts whichever port the object
 * implements, per call. A service id with no definition at all is left alone, so the container keeps reporting the
 * unknown service itself.
 *
 * The deprecation of the old interface reaches the application through its `@deprecated` tag (Symfony's
 * DebugClassLoader, in debug mode), not from this pass.
 *
 * Runs before optimization, so the decorators of the aliased id are resolved afterwards.
 *
 * @internal
 */
final class SenderPortPass implements CompilerPassInterface
{
    /** Service id of the gate this pass registers when the host sender service is not a deprecated-port one. */
    public const GATE_ID = 'nowo_mail_kit.sender.provider_gate';

    /**
     * Interposes the gate where it is needed.
     *
     * @throws LogicException when the configured sender service implements neither port
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasAlias(SenderProviderInterface::class)) {
            return;
        }

        $alias  = $container->getAlias(SenderProviderInterface::class);
        $target = $this->resolve($container, SenderProviderInterface::class);
        if (!$container->hasDefinition($target)) {
            return; // No definition to look at: the container reports the unknown service itself, as before.
        }

        $class = $this->classOf($container, $target);
        if ($class !== null && is_a($class, SenderProviderInterface::class, true)) {
            return;
        }
        if ($class !== null && !is_a($class, SenderIdentityProviderInterface::class, true)) {
            throw new LogicException(sprintf('nowo_mail_kit.mailer.sender_provider: the service "%s" (%s) must implement "%s" (or the deprecated "%s").', $target, $class, SenderIdentityProviderInterface::class, SenderProviderInterface::class));
        }

        $container->register(self::GATE_ID, SenderProviderGate::class)->setArguments([new Reference($target)]);
        $container->setAlias(SenderProviderInterface::class, self::GATE_ID)->setPublic($alias->isPublic());
    }

    /**
     * The service id an alias chain ends on (the id itself when it is no alias).
     */
    private function resolve(ContainerBuilder $container, string $id): string
    {
        $seen = [];
        while ($container->hasAlias($id) && !isset($seen[$id])) {
            $seen[$id] = true;
            $id        = (string) $container->getAlias($id);
        }

        return $id;
    }

    /**
     * @return class-string|null null when the class cannot be known before the container is compiled
     */
    private function classOf(ContainerBuilder $container, string $service): ?string
    {
        $definition = $container->getDefinition($service);
        if ($definition->getFactory() !== null || $definition->isSynthetic()) {
            return null;
        }

        $class = $container->getParameterBag()->resolveValue($definition->getClass() ?? $service);

        return is_string($class) && class_exists($class) ? $class : null;
    }
}
