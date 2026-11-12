<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Nowo\MailKitBundle\Graph\Send\GraphMailSender;
use Nowo\MailKitBundle\Graph\Send\GraphOutgoingMail;
use Nowo\MailKitBundle\Graph\Send\GraphRecipient;
use Nowo\MailKitBundle\Graph\Send\PlusAddress;
use Symfony\Component\HttpFoundation\Response;

/**
 * Host controller of the Graph worker-mode test: sends one e-mail from the organization's mailbox (route `tenant` =
 * mailbox profile) with a task plus address as Reply-To, and prints the Graph result.
 */
final readonly class GraphSendController
{
    public function __construct(
        private GraphMailSender $sender,
    ) {
    }

    public function __invoke(string $tenant, string $task): Response
    {
        $mailbox = $tenant === 'acme' ? 'projects@example.com' : 'support@example.org';
        $result  = $this->sender->send(new GraphOutgoingMail(
            to: [new GraphRecipient('client-' . $tenant . '@example.net')],
            subject: 'Task ' . $task,
            text: 'Update on task ' . $task,
            replyTo: [new GraphRecipient(PlusAddress::compose($mailbox, 't-' . $task))],
        ), $tenant);

        return new Response($result->profile . '|' . $result->graphId . '|' . $result->internetMessageId);
    }
}
