<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\DependencyInjection\Compiler;

use Nowo\MailKitBundle\DependencyInjection\NowoMailKitExtension;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function is_array;
use function str_ends_with;
use function substr;

/**
 * Build-time warning (2.5.0, audit ZAV-I3): delegated mailboxes on the default DBAL connection while a Messenger bus
 * runs the `doctrine_transaction` middleware. `MailboxSenderInterface::send()` and the `ReconcileMailboxSend` handler
 * refuse to run inside a transaction on the mailbox connection (a rollback after Graph sent would forget the send),
 * so such a host should set a dedicated `mailboxes.connection`. Runs before the Messenger pass, which consumes the
 * `<bus>.middleware` parameters.
 *
 * @internal
 */
final class MailboxSendTransactionPass implements CompilerPassInterface
{
    /**
     * Logs the warning in the container compiler log.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(NowoMailKitExtension::MAILBOX_CONNECTION_PARAMETER) || $container->getParameter(NowoMailKitExtension::MAILBOX_CONNECTION_PARAMETER) !== null) {
            return;
        }
        foreach ($container->getParameterBag()->all() as $name => $middleware) {
            if (!str_ends_with($name, '.middleware') || !is_array($middleware)) {
                continue;
            }
            foreach ($middleware as $item) {
                if (is_array($item) && ($item['id'] ?? null) === 'doctrine_transaction') {
                    $container->log($this, 'nowo_mail_kit.mailboxes.connection is not set while the Messenger bus "' . substr($name, 0, -11) . '" runs the doctrine_transaction middleware: MailboxSenderInterface::send() and ReconcileMailboxSend refuse to run inside a transaction on the mailbox connection. Configure a dedicated DBAL connection for mailboxes.connection.');

                    return;
                }
            }
        }
    }
}
