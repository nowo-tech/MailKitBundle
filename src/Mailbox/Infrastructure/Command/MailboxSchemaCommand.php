<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Infrastructure\Command;

use Nowo\MailKitBundle\Mailbox\Infrastructure\Store\MailboxStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `nowo:mail-kit:mailbox:schema` (2.5.0): prints, or creates with `--force`, the missing delegated-mailbox tables (for
 * hosts without the ORM schema listener or migrations).
 */
#[AsCommand(name: self::NAME, description: 'Print (or create with --force) the missing tables of the delegated mailboxes.')]
final class MailboxSchemaCommand extends Command
{
    /** Command name. */
    public const NAME = 'nowo:mail-kit:mailbox:schema';

    /**
     * Wires the command.
     */
    public function __construct(
        private readonly MailboxStore $store,
    ) {
        parent::__construct(self::NAME);
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Run the statements');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sql = $this->store->missingTablesSql();
        if ($sql === []) {
            $output->writeln('The mailbox tables exist.');

            return Command::SUCCESS;
        }
        if ($input->getOption('force') === true) {
            $this->store->execute($sql);
            $output->writeln('Created the mailbox tables.');

            return Command::SUCCESS;
        }
        foreach ($sql as $statement) {
            $output->writeln($statement . ';');
        }

        return Command::SUCCESS;
    }
}
