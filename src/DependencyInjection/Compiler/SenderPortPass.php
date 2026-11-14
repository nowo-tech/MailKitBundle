<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\MailKitBundle\Sender\SenderIdentityProviderInterface;
use Nowo\MailKitBundle\Sender\SenderProviderGate;
use Nowo\MailKitBundle\Sender\SenderProviderInterface;
use Nowo\MailKitBundle\Settings\MailSettings;
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
 * `nowo_mail_kit.mailer.sender_provider` takes a service implementing {@see SenderIdentityProviderInterface} (the twin,
 * `MailAddress`), the deprecated {@see SenderProviderInterface} (Symfony Mime `Address`), or both; it stays the single
 * source of which provider is used. The extension points `SenderProviderInterface` at that service (or at
 * `MailSettings`); this pass then:
 *
 * - leaves that alias untouched when the service implements only the deprecated port (today's wiring), and points
 *   `SenderIdentityProviderInterface` at an internal {@see SenderProviderGate} over it, so the new port can always be
 *   autowired;
 * - points both aliases straight at `MailSettings`, the built-in default, which implements both ports with the same
 *   values (no gate, no change for a standard host);
 * - otherwise — the service implements the new port, alone or next to the deprecated one — points
 *   `SenderProviderInterface` at the gate (the new port wins: the gate reads `defaultFrom()` / `defaultReplyTo()`) and
 *   `SenderIdentityProviderInterface` straight at the service.
 *
 * When the class cannot be known before the container is compiled (a factory or a synthetic service), both aliases
 * point at the gate, which adapts whichever port the object implements, per call. A service id with no definition at
 * all is left alone, so the container keeps reporting the unknown service itself. No autoconfiguration: implementing
 * a port does not select a provider, the configuration does.
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
    /** Service id of the gate this pass registers when one of the two ports has to be adapted. */
    public const GATE_ID = 'nowo_mail_kit.sender.provider_gate';

    /**
     * Points both sender port aliases at the configured service, through the gate where it is needed.
     *
     * @throws LogicException when the configured sender service implements neither port
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasAlias(SenderProviderInterface::class)) {
            return;
        }

        $public = $container->getAlias(SenderProviderInterface::class)->isPublic();
        $target = $this->resolve($container, SenderProviderInterface::class);
        if (!$container->hasDefinition($target)) {
            return; // No definition to look at: the container reports the unknown service itself, as before.
        }

        $class   = $this->classOf($container, $target);
        $newPort = $class !== null && is_a($class, SenderIdentityProviderInterface::class, true);
        $oldPort = $class !== null && is_a($class, SenderProviderInterface::class, true);
        if ($class !== null && !$newPort && !$oldPort) {
            throw new LogicException(sprintf('nowo_mail_kit.mailer.sender_provider: the service "%s" (%s) must implement "%s" (or the deprecated "%s").', $target, $class, SenderIdentityProviderInterface::class, SenderProviderInterface::class));
        }

        if ($class === MailSettings::class) {
            // Built-in default: both ports return the same values, so the deprecated alias keeps today's wiring.
            $container->setAlias(SenderIdentityProviderInterface::class, $target)->setPublic($public);

            return;
        }

        $container->register(self::GATE_ID, SenderProviderGate::class)->setArguments([new Reference($target)]);
        if ($oldPort && !$newPort) {
            $container->setAlias(SenderIdentityProviderInterface::class, self::GATE_ID)->setPublic($public);

            return;
        }

        $container->setAlias(SenderProviderInterface::class, self::GATE_ID)->setPublic($public);
        $container->setAlias(SenderIdentityProviderInterface::class, $newPort ? $target : self::GATE_ID)->setPublic($public);
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
