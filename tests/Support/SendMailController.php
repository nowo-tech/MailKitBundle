<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Host controller of the worker-mode test: sends one e-mail through the framework mailer (MailKit decorates it).
 */
final readonly class SendMailController
{
    public function __construct(
        private MailerInterface $mailer,
    ) {
    }

    public function __invoke(string $tenant): Response
    {
        $this->mailer->send((new Email())->to($tenant . '-user@example.net')->subject('Hello ' . $tenant)->text('Hi'));

        return new Response('sent');
    }
}
