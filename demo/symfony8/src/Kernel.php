<?php

declare(strict_types=1);

namespace App;

use App\Http\ResendDemoApi;
use Nowo\MailKitBundle\Resend\ResendApiChecker;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    /**
     * Demo only: the Resend key check talks to {@see ResendDemoApi} instead of api.resend.com, so the provider switch
     * and the check-before-save path work offline (a key starting with `re_demo_` is accepted, any other is refused).
     */
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(ResendApiChecker::class)) {
            $container->getDefinition(ResendApiChecker::class)->replaceArgument(0, new Definition(MockHttpClient::class, [new Definition(ResendDemoApi::class)]));
        }
    }
}
