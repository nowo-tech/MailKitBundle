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
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

use function array_pop;
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
 * Host decorators (1.12.1): when the deprecated port id or the configured service is decorated, the new port follows
 * the decorated service — straight when every decorator implements the new port, through a gate otherwise — so it
 * never skips a decorator nor fails with a `TypeError` on a decorator that implements only the deprecated port.
 * MailKit's own path is unchanged: it reads the deprecated id, through the gate where the new port wins.
 *
 * Runs before optimization, so the decorators of the aliased id are resolved afterwards.
 *
 * @internal
 */
final class SenderPortPass implements CompilerPassInterface
{
    /** Service id of the gate this pass registers when one of the two ports has to be adapted. */
    public const GATE_ID = 'nowo_mail_kit.sender.provider_gate';

    /** Service id of the gate that serves the new port from a host-decorated deprecated port id (1.12.1). */
    public const IDENTITY_GATE_ID = 'nowo_mail_kit.sender.identity_gate';

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

        $alias      = $container->getAlias(SenderProviderInterface::class);
        $public     = $alias->isPublic();
        $configured = (string) $alias;
        $target     = $this->resolve($container, SenderProviderInterface::class);
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
            $identity = $target;
        } else {
            // The gate reads the configured id, so a decorator of an alias on the way to the service applies too.
            $this->registerGate($container, self::GATE_ID, $configured);
            if (!$oldPort || $newPort) {
                $container->setAlias(SenderProviderInterface::class, self::GATE_ID)->setPublic($public);
            }
            $identity = $newPort ? $target : self::GATE_ID;
        }

        $container->setAlias(SenderIdentityProviderInterface::class, $this->decoratedIdentity($container, $configured, $target) ?? $identity)->setPublic($public);
    }

    /**
     * Where the new port points when a host decorates the deprecated port id or the configured service (1.12.1,
     * PI-L2), so its consumers get the same decorated sender as MailKit's own path; null when nothing is decorated.
     *
     * - The deprecated port id is decorated: that id when every decorator implements the new port, otherwise a second
     *   gate over that id ({@see self::IDENTITY_GATE_ID}).
     * - The configured service (or an alias on the way to it) is decorated: the configured id when every decorator
     *   implements the new port, otherwise the gate over it ({@see self::GATE_ID}).
     *
     * Decorator ordering (priorities) is left to the container: the outermost decorator is one of the decorators, so
     * the straight alias is used only when all of them implement the new port, and the gate (which adapts whichever
     * port the object implements, per call) otherwise.
     */
    private function decoratedIdentity(ContainerBuilder $container, string $configured, string $target): ?string
    {
        $port = $this->decoratorsImplementNewPort($container, [SenderProviderInterface::class]);
        if ($port !== null) {
            if ($port) {
                return SenderProviderInterface::class;
            }
            $this->registerGate($container, self::IDENTITY_GATE_ID, SenderProviderInterface::class);

            return self::IDENTITY_GATE_ID;
        }

        $chain = [$configured];
        for ($id = $configured; $id !== $target && $container->hasAlias($id); $chain[] = $id) {
            $id = (string) $container->getAlias($id);
        }
        $port = $this->decoratorsImplementNewPort($container, $chain);
        if ($port === null) {
            return null;
        }
        if ($port) {
            return $configured;
        }
        if (!$container->hasDefinition(self::GATE_ID)) {
            $this->registerGate($container, self::GATE_ID, $configured);
        }

        return self::GATE_ID;
    }

    /**
     * Whether every decorator of the given ids (and of their decorators) implements the new port; null for none.
     *
     * @param list<string> $ids
     */
    private function decoratorsImplementNewPort(ContainerBuilder $container, array $ids): ?bool
    {
        $found = false;
        $all   = true;
        $seen  = [];
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
                $found = true;
                $class = $this->classOf($container, $id);
                $all   = $all && $class !== null && is_a($class, SenderIdentityProviderInterface::class, true);
                $ids[] = $id;
            }
        }

        return $found ? $all : null;
    }

    /**
     * Registers a gate over the given service id, with the logger for refused addresses when there is one.
     */
    private function registerGate(ContainerBuilder $container, string $gateId, string $over): void
    {
        $container->register($gateId, SenderProviderGate::class)->setArguments([
            new Reference($over),
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]);
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
