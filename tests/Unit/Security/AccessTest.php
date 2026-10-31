<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Security;

use Nowo\InstanceSettingsBundle\Security\AccessGuard as InstanceSettingsAccessGuard;
use Nowo\InstanceSettingsBundle\Security\InstanceSettingsAccessCheckerInterface;
use Nowo\MailKitBundle\EventListener\MailKitAccessListener;
use Nowo\MailKitBundle\Security\AccessGuard;
use Nowo\MailKitBundle\Security\AllowAllMailKitAccessChecker;
use Nowo\MailKitBundle\Security\ConfigurableMailKitAccessChecker;
use Nowo\MailKitBundle\Security\MailKitAccessCheckerInterface;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class AccessTest extends TestCase
{
    public function testCheckers(): void
    {
        $authorization = $this->createMock(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturnCallback(static fn (mixed $role): bool => $role === 'ROLE_MAIL');
        $checker = new ConfigurableMailKitAccessChecker($authorization, ['ROLE_ADMIN', 'ROLE_MAIL']);

        self::assertFalse($checker->canAccess(null));
        self::assertTrue($checker->canAccess(new stdClass()));
        self::assertFalse((new ConfigurableMailKitAccessChecker($authorization, ['ROLE_ADMIN']))->canAccess(new stdClass()));
        self::assertTrue((new AllowAllMailKitAccessChecker())->canAccess(null));
    }

    public function testGuardUsesTheFirewallTokenAndTheSectionAccess(): void
    {
        $user   = new InMemoryUser('admin', null, ['ROLE_ADMIN']);
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_ADMIN']));
        $stack   = new RequestStack();
        $request = new Request();
        $request->attributes->set('_firewall_context', 'security.firewall.map.context.main');
        $stack->push($request);
        $settings = $this->createMock(InstanceSettingsAccessCheckerInterface::class);
        $settings->method('canAccess')->willReturnCallback(static fn (?object $u, ?string $area): bool => $u === $user && $area === 'mailer');

        $guard = new AccessGuard(new AllowAllMailKitAccessChecker(), new InstanceSettingsAccessGuard($settings, false, $tokens, $stack), $tokens, $stack);
        self::assertSame($user, $guard->user());
        self::assertTrue($guard->isGranted());
        self::assertTrue($guard->isSectionGranted('mailer'));
        self::assertFalse($guard->isSectionGranted('resend'));
        self::assertFalse((new AccessGuard(new AllowAllMailKitAccessChecker(), null, $tokens, $stack))->isSectionGranted('mailer'));

        try {
            $guard->deny('no');
        } catch (AccessDeniedException $e) {
            self::assertSame('no', $e->getMessage());
        }

        $noFirewall = new AccessGuard(new AllowAllMailKitAccessChecker(), null, $tokens, new RequestStack());
        self::assertNull($noFirewall->user());
        $this->expectException(AccessDeniedHttpException::class);
        $noFirewall->deny('no');
    }

    public function testListener(): void
    {
        $deny = new AccessGuard(new class implements MailKitAccessCheckerInterface {
            public function canAccess(?object $user): bool
            {
                return false;
            }
        });
        $listener = new MailKitAccessListener($deny, '/admin/mail/');
        $kernel   = $this->createMock(HttpKernelInterface::class);

        $listener->onKernelRequest(new RequestEvent($kernel, Request::create('/elsewhere'), HttpKernelInterface::MAIN_REQUEST));
        $listener->onKernelRequest(new RequestEvent($kernel, Request::create('/admin/mailbox'), HttpKernelInterface::MAIN_REQUEST));
        $sub = Request::create('/_fragment');
        $sub->attributes->set('_controller', 'App\\Controller\\X::y');
        $listener->onKernelRequest(new RequestEvent($kernel, $sub, HttpKernelInterface::SUB_REQUEST));
        $this->addToAssertionCount(1);

        foreach ([
            [Request::create('/admin/mail'), HttpKernelInterface::MAIN_REQUEST],
            [Request::create('/admin/mail/log'), HttpKernelInterface::MAIN_REQUEST],
            [$this->bundleRequest(['Nowo\\MailKitBundle\\Controller\\MailKitController', 'index']), HttpKernelInterface::SUB_REQUEST],
            [$this->bundleRequest('\\Nowo\\MailKitBundle\\Controller\\MailKitController::index'), HttpKernelInterface::MAIN_REQUEST],
        ] as [$request, $type]) {
            try {
                $listener->onKernelRequest(new RequestEvent($kernel, $request, $type));
                self::fail('Access must be denied for ' . $request->getPathInfo());
            } catch (AccessDeniedHttpException) {
            }
        }
    }

    private function bundleRequest(mixed $controller): Request
    {
        $request = Request::create('/somewhere');
        $request->attributes->set('_controller', $controller);

        return $request;
    }
}
