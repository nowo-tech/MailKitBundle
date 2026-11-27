<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Template;

use Nowo\MailKitBundle\Template\Exception\TemplateNotFound;
use Nowo\MailKitBundle\Template\Infrastructure\Store\TemplateStore;
use Nowo\MailKitBundle\Template\Service\TemplateLibraryInterface;
use Nowo\MailKitBundle\Template\Service\TemplatePersonalDataInterface;
use Nowo\MailKitBundle\Template\Service\TemplateRendererInterface;
use Nowo\MailKitBundle\Template\Value\TemplateActor;
use Nowo\MailKitBundle\Template\Value\TemplateDraft;
use Nowo\MailKitBundle\Template\Value\TemplateKind;
use Nowo\MailKitBundle\Template\Value\TemplatePermission;
use Nowo\MailKitBundle\Template\Value\TemplateScope;
use Nowo\MailKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\MailKitBundle\Tests\Integration\Kernel\MailKitTestKernel;
use Nowo\MailKitBundle\Tests\Support\ContactPlaceholderProvider;
use Nowo\MailKitBundle\Tests\Support\MemoryTemplatePermission;
use Nowo\MailKitBundle\Tests\Support\MePlaceholderProvider;
use Nowo\MailKitBundle\Tests\Support\RequestTenantSenderProvider;
use Nowo\MailKitBundle\Tests\Support\StaticDsnProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

use function array_keys;
use function gc_collect_cycles;
use function memory_get_usage;
use function preg_replace;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * 2.7.0 stored templates in a booted kernel: off by default; with the ORM the schema listener creates the table,
 * lint:container passes and providers are autoconfigured; a strict DDD host (no Instance Settings, no ORM) renders
 * for two tenants and two people through 400 calls in one kept-alive kernel without the services resetter (FrankenPHP
 * worker mode, kernel reset false) with no state bleed, then sends a rendered template through MailKit.
 */
final class TemplateKernelTest extends KernelTestCase
{
    public function testOffByDefault(): void
    {
        $container = $this->container($this->bootKernel());
        foreach ([TemplateStore::class, TemplateLibraryInterface::class, TemplateRendererInterface::class, TemplatePersonalDataInterface::class] as $id) {
            self::assertFalse($container->has($id), $id);
        }
    }

    public function testLintContainerSchemaAndCommandsWithTheOrm(): void
    {
        $kernel = $this->bootTemplates([], true);
        // The ORM schema listener created the table with the host's schema.
        self::assertSame([], $this->service($kernel, TemplateStore::class)->missingTablesSql());
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $tester->run(['command' => 'lint:container']);
        self::assertSame(0, $tester->getStatusCode(), (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
        $tester->run(['command' => 'nowo:mail-kit:templates:schema']);
        self::assertSame("The template table exists.\n", $tester->getDisplay());
        self::assertSame(['contact', 'me'], array_keys($this->service($kernel, TemplateRendererInterface::class)->placeholders()));
    }

    public function testDddHostRendersForTwoTenantsInOneWorkerWithoutStateBleed(): void
    {
        $kernel = $this->bootTemplates(['orm', 'instance_settings']);
        $store  = $this->service($kernel, TemplateStore::class);
        $store->execute($store->missingTablesSql());
        $this->connection($kernel)->executeStatement('DELETE FROM mail_kit_template');
        $this->service($kernel, MemoryTemplatePermission::class)->grant('*', TemplatePermission::Personal, TemplatePermission::UseShared, TemplatePermission::ManageShared);
        $library  = $this->service($kernel, TemplateLibraryInterface::class);
        $renderer = $this->service($kernel, TemplateRendererInterface::class);

        $actors = [new TemplateActor('t1', 'ana'), new TemplateActor('t2', 'bob')];
        $ids    = [];
        foreach ($actors as $actor) {
            $ids[$actor->tenant] = $library->create($actor, new TemplateDraft(TemplateKind::Template, TemplateScope::Shared, 'welcome', 'Welcome', 'en', 'Hi {{ contact.first_name }}', '<p>{{ me.first_name }} of ' . $actor->tenant . '</p>', ''))->id;
        }
        $before = 0;
        for ($i = 0; $i < 400; ++$i) {
            $actor = $actors[$i % 2];
            $out   = $renderer->render($actor, $ids[$actor->tenant], ['contact' => '42']);
            self::assertSame('<p>' . ($actor->owner === 'ana' ? 'Ana' : 'Bob') . ' of ' . $actor->tenant . '</p>', $out->html);
            self::assertSame($actor->tenant === 't1' ? 'Hi <script>alert(1)</script>Pedro' : 'Hi {{ contact.first_name }}', $out->subject);
            // The other tenant's template is never reachable.
            try {
                $renderer->render($actor, $ids[$actor->tenant === 't1' ? 't2' : 't1']);
                self::fail('tenant isolation');
            } catch (TemplateNotFound) {
            }
            if ($i === 99) {
                gc_collect_cycles();
                $before = memory_get_usage();
            }
        }
        gc_collect_cycles();
        self::assertLessThan(2 * 1024 * 1024, memory_get_usage() - $before, 'Memory must stay flat over 300 renders.');

        $mailer = $this->mailer($kernel);
        $email  = $renderer->render($actors[0], $ids['t1'], ['contact' => '42'])->applyTo((new Email())->from('crm@example.com')->to(new Address('pedro@example.org')));
        $mailer->send($email);
        self::assertCount(1, $this->store($kernel)->sent);
    }

    /**
     * @param list<string> $without
     */
    private function bootTemplates(array $without, bool $debug = false): MailKitTestKernel
    {
        return $this->bootKernel(
            [
                'mailer'    => ['dsn_provider' => StaticDsnProvider::class, 'sender_provider' => RequestTenantSenderProvider::class],
                'log'       => ['enabled' => false],
                'templates' => ['enabled' => true, 'permission' => MemoryTemplatePermission::class],
            ],
            without: $without,
            debug: $debug,
            extra: static function (ContainerConfigurator $container): void {
                $services = $container->services();
                $services->set(MemoryTemplatePermission::class)->public();
                $services->set(MePlaceholderProvider::class)->autoconfigure();
                $services->set(ContactPlaceholderProvider::class)->autoconfigure();
                $services->set(StaticDsnProvider::class)->args(['memory://default']);
                $services->set(RequestTenantSenderProvider::class)->args([service('request_stack')]);
            },
        );
    }
}
