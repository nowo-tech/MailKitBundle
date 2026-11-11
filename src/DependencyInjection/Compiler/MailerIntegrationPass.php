<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\MailKitBundle\Log\SentMailRecorder;
use Nowo\MailKitBundle\Mailer\MailKitMailer;
use Nowo\MailKitBundle\Settings\MailSettings;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Checks the dependencies before the decorators are resolved — the framework mailer (`mailer.transports`,
 * `mailer.transport_factory`) and, when the configuration is read from settings, the Instance Settings store (1.6: not
 * needed when the host provides the DSN and sender ports), Doctrine when the sent-mail log is on — and tells {@see MailKitMailer} whether the framework mailer
 * has a message bus (then it records "queued" rows).
 */
final class MailerIntegrationPass implements CompilerPassInterface
{
    /** Service id of the MailerInterface decorator. */
    public const MAILER_ID = 'nowo_mail_kit.mailer';

    /**
     * Fails fast on a missing dependency; sets the `$queues` argument of the decorator.
     */
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(MailSettings::class) && !$container->has(InstanceSettingsInterface::class)) {
            throw new LogicException('NowoMailKitBundle requires nowo-tech/instance-settings-bundle: register Nowo\\InstanceSettingsBundle\\NowoInstanceSettingsBundle in config/bundles.php (the mailer settings are an Instance Settings section).');
        }
        if ($container->hasDefinition(SentMailRecorder::class) && !$container->has('doctrine')) {
            throw new LogicException('NowoMailKitBundle: the sent-mail log (nowo_mail_kit.log.enabled, default true) requires doctrine/doctrine-bundle with the ORM. Register Doctrine\\Bundle\\DoctrineBundle\\DoctrineBundle, or set nowo_mail_kit.log.enabled: false.');
        }
        if (!$container->hasDefinition('mailer.transports') || !$container->has('mailer.transport_factory')) {
            throw new LogicException('NowoMailKitBundle requires the framework mailer: install symfony/mailer and configure framework.mailer.dsn (MAILER_DSN, for example null://null until a runtime DSN is stored).');
        }

        if (!$container->hasDefinition(self::MAILER_ID) || !$container->hasDefinition('mailer.mailer')) {
            return;
        }
        $arguments = $container->getDefinition('mailer.mailer')->getArguments();
        $bus       = $arguments[1] ?? null;
        $queues    = $bus instanceof Reference && $container->has((string) $bus);
        $container->getDefinition(self::MAILER_ID)->replaceArgument('$queues', $queues);
    }
}
