<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Kernel;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Throwable;

/**
 * REQ-SF-006: unsafe or incomplete setups refuse to compile with a message naming the fix.
 */
final class InvalidConfigurationTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: list<string>, 2: bool, 3: class-string<Throwable>, 4: string}>
     */
    public static function invalidSetups(): iterable
    {
        yield 'empty roles' => [['security' => ['access_roles' => []]], [], false, InvalidConfigurationException::class, 'access_roles must contain at least one'];
        yield 'sendmail allowed' => [['dsn' => ['allowed_schemes' => ['smtp', 'sendmail']]], [], false, InvalidConfigurationException::class, 'must not contain "sendmail"'];
        yield 'bad prefix' => [['doctrine' => ['table_prefix' => 'app-']], [], false, InvalidConfigurationException::class, 'table_prefix must contain only'];
        yield 'bad path prefix' => [['web_ui' => ['path_prefix' => 'admin']], [], false, InvalidConfigurationException::class, 'must be an absolute path'];
        yield 'bad template page' => [['web_ui' => ['templates' => ['nope' => 'x.html.twig']]], [], false, InvalidConfigurationException::class, 'Unknown nowo_mail_kit.web_ui.templates page(s) "nope"'];
        yield 'bad default from' => [['sender' => ['default_from' => 'not-an-address']], [], false, InvalidConfigurationException::class, 'default_from must be an e-mail address'];
        yield 'bad interval' => [['test_email' => ['rate_interval' => 'whenever']], [], false, InvalidConfigurationException::class, 'rate_interval must be a relative time'];
        yield 'export bodies without stored bodies' => [['web_ui' => ['export' => ['include_bodies' => true]]], [], false, InvalidConfigurationException::class, 'include_bodies needs nowo_mail_kit.log.store_bodies'];
        yield 'export row cap' => [['web_ui' => ['export' => ['max_rows' => 0]]], [], false, InvalidConfigurationException::class, 'max_rows'];
        yield 'env placeholder export switch' => [['web_ui' => ['export' => ['enabled' => '%env(bool:MAIL_KIT_EXPORT)%']]], [], false, InvalidConfigurationException::class, 'web_ui.export.enabled must be a literal boolean'];
        yield 'env placeholder switch' => [['log' => ['store_bodies' => '%env(bool:MAIL_KIT_BODIES)%']], [], false, InvalidConfigurationException::class, 'must be a literal boolean'];
        yield 'unauthenticated outside debug' => [['security' => ['allow_unauthenticated' => true]], [], false, InvalidConfigurationException::class, 'only allowed when kernel.debug is true'];
        yield 'no instance settings' => [[], ['instance_settings'], false, LogicException::class, 'requires nowo-tech/instance-settings-bundle'];
        yield 'no mailer' => [[], ['mailer'], false, LogicException::class, 'requires the framework mailer'];
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $without
     * @param class-string<Throwable> $exception
     */
    #[DataProvider('invalidSetups')]
    public function testTheContainerRefusesToCompile(array $config, array $without, bool $debug, string $exception, string $message): void
    {
        $this->expectException($exception);
        $this->expectExceptionMessage($message);

        $this->bootKernel($config, without: $without, debug: $debug);
    }

    public function testTheAdminUiNeedsTheSecurityBundle(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('NowoMailKitBundle: the admin UI requires symfony/security-bundle');

        $this->bootKernel(settingsConfig: ['web_ui' => ['enabled' => false]], without: ['security']);
    }

    public function testFormCsrfIsRequiredForTheAdminUi(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires CSRF protection');

        $this->bootKernel(extra: static function (ContainerConfigurator $container): void {
            $container->extension('framework', ['form' => ['csrf_protection' => false]]);
        });
    }

    public function testUnauthenticatedAccessIsAcceptedInDebug(): void
    {
        $kernel = $this->bootKernel(['security' => ['allow_unauthenticated' => true]], ['security' => ['allow_unauthenticated' => true]], debug: true);
        $client = $this->client($kernel, null);
        $client->request('GET', '/admin/mail/log');

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }
}
