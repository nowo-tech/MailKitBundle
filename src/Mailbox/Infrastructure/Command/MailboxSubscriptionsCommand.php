<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Infrastructure\Command;

use InvalidArgumentException;
use Nowo\MailKitBundle\Mailbox\Engine\MailboxSyncEngine;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxException;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function is_string;
use function sprintf;

/**
 * `nowo:mail-kit:mailbox:subscriptions` (2.5.0): re-checks the account and creates / renews the change-notification
 * subscriptions of every active delegated mailbox (or of one with `--mailbox`). Schedule it hourly; a grant failure
 * pauses that mailbox, a transient failure is retried at the next run.
 */
#[AsCommand(name: self::NAME, description: 'Create or renew the change-notification subscriptions of the active delegated mailboxes.')]
final class MailboxSubscriptionsCommand extends Command
{
    /** Command name. */
    public const NAME = 'nowo:mail-kit:mailbox:subscriptions';

    /**
     * Wires the command.
     */
    public function __construct(
        private readonly MailboxSyncEngine $engine,
    ) {
        parent::__construct(self::NAME);
    }

    protected function configure(): void
    {
        $this
            ->addOption('mailbox', null, InputOption::VALUE_REQUIRED, 'Only this mailbox id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getOption('mailbox');
        if (is_string($id) && $id !== '') {
            try {
                $this->engine->renew(new MailboxId($id));
            } catch (InvalidArgumentException $exception) {
                $output->writeln('<error>' . $exception->getMessage() . '</error>');

                return Command::INVALID;
            } catch (MailboxException $exception) {
                $output->writeln('<error>' . $exception->getMessage() . '</error>');

                return Command::FAILURE;
            }
            $output->writeln('Subscriptions checked.');

            return Command::SUCCESS;
        }
        $failures = $this->engine->renewAll();
        $output->writeln($failures === 0 ? 'Subscriptions checked.' : sprintf('<error>%d mailbox(es) failed; see the log.</error>', $failures));

        return $failures === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
