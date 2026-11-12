<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tracking\Command;

use Nowo\MailKitBundle\Tracking\Store\TrackingStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `nowo:mail-kit:tracking:schema` (1.10): prints the CREATE statements of the tracking table when it is missing (paste
 * them into a migration), or runs them with `--force`. ORM hosts also get the table from `doctrine:migrations:diff` /
 * `doctrine:schema:update` (postGenerateSchema listener).
 */
#[AsCommand(name: self::NAME, description: 'Print (or create with --force) the missing table of the e-mail open tracking.')]
final class TrackingSchemaCommand extends Command
{
    /** Command name. */
    public const NAME = 'nowo:mail-kit:tracking:schema';

    /**
     * Wires the command.
     */
    public function __construct(
        private readonly TrackingStore $store,
    ) {
        parent::__construct(self::NAME);
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Run the statements');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sql = $this->store->missingTablesSql();
        if ($sql === []) {
            $output->writeln('The tracking table exists.');

            return Command::SUCCESS;
        }
        if ($input->getOption('force') === true) {
            $this->store->execute($sql);
            $output->writeln('Created the tracking table.');

            return Command::SUCCESS;
        }
        foreach ($sql as $statement) {
            $output->writeln($statement . ';');
        }

        return Command::SUCCESS;
    }
}
