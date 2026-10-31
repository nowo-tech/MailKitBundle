<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle;

use Nowo\MailKitBundle\DependencyInjection\Compiler\CsrfProtectionPass;
use Nowo\MailKitBundle\DependencyInjection\Compiler\MailerIntegrationPass;
use Nowo\MailKitBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\MailKitBundle\DependencyInjection\Compiler\UnauthenticatedAccessPass;
use Nowo\MailKitBundle\DependencyInjection\NowoMailKitExtension;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Runtime-configurable mailer for Symfony on top of Instance Settings: DSN and sender validation, SSRF-safe hosts,
 * test e-mail, Resend key check, sent-mail log with retention and an AuthKit mail-ready adapter.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NowoMailKitBundle extends Bundle
{
    /** Translation domain of the bundle UI copy (REQ-I18N-003) and Twig namespace (REQ-TWIG-002). */
    public const TRANSLATION_DOMAIN = 'NowoMailKitBundle';

    /** FormKit profile (`nowo_form_kit.profiles.mail_kit`) of every bundle form type (REQ-TWIG-005 rule 11). */
    public const FORM_KIT_PROFILE = 'mail_kit';

    /**
     * Registers the compiler passes: Twig paths, mailer / Instance Settings integration, CSRF fail-closed guard and
     * the demo-only unauthenticated access guard.
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new TwigPathsPass());
        // Before the decorators are resolved (DecoratorServicePass runs in TYPE_OPTIMIZE).
        $container->addCompilerPass(new MailerIntegrationPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION);
        $container->addCompilerPass(new CsrfProtectionPass());
        $container->addCompilerPass(new UnauthenticatedAccessPass());
    }

    /**
     * The extension alias is `nowo_mail_kit` (not the default derived from the class name).
     */
    public function getContainerExtension(): ?ExtensionInterface
    {
        if ($this->extension === null) {
            // @igor-ignore - Boot-time Symfony Bundle extension cache (not request state).
            $this->extension = new NowoMailKitExtension();
        }

        return $this->extension instanceof ExtensionInterface ? $this->extension : null;
    }
}
