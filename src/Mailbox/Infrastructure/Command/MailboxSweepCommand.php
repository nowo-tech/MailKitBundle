<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Infrastructure\Command;

use InvalidArgumentException;
use Nowo\MailKitBundle\Mailbox\Engine\MailboxSyncEngine;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function is_string;
use function sprintf;

/**
 * `nowo:mail-kit:mailbox:sweep` (2.5.0): enqueues a sweep of every followed folder of every active delegated mailbox
 * (or of one with `--mailbox`). Schedule it every 5 minutes: a lost notification then costs at most one interval.
 */
#[AsCommand(name: self::NAME, description: 'Enqueue a sweep of the followed folders of the active delegated mailboxes.')]
final class MailboxSweepCommand extends Command
{
    /** Command name. */
    public const NAME = 'nowo:mail-kit:mailbox:sweep';

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
                $this->engine->sweep(new MailboxId($id));
            } catch (InvalidArgumentException $exception) {
                $output->writeln('<error>' . $exception->getMessage() . '</error>');

                return Command::INVALID;
            }
            $output->writeln('Sweep enqueued.');

            return Command::SUCCESS;
        }
        $output->writeln(sprintf('%d folder sweep(s) enqueued.', $this->engine->sweepAll()));

        return Command::SUCCESS;
    }
}
