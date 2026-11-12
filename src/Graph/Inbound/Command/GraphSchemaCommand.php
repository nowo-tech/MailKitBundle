<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Inbound\Command;

use Nowo\MailKitBundle\Graph\Inbound\Store\GraphInboundStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `nowo:mail-kit:graph:schema` (1.9): prints the CREATE statements of the missing inbound tables (paste them into a
 * migration), or runs them with `--force`. ORM hosts also get the tables from `doctrine:migrations:diff` /
 * `doctrine:schema:update` (postGenerateSchema listener).
 */
#[AsCommand(name: self::NAME, description: 'Print (or create with --force) the missing tables of the Microsoft Graph inbound pipeline.')]
final class GraphSchemaCommand extends Command
{
    /** Command name. */
    public const NAME = 'nowo:mail-kit:graph:schema';

    /**
     * Wires the command.
     */
    public function __construct(
        private readonly GraphInboundStore $store,
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
            $output->writeln('The inbound tables exist.');

            return Command::SUCCESS;
        }
        if ($input->getOption('force') === true) {
            $this->store->execute($sql);
            $output->writeln('Created the inbound tables.');

            return Command::SUCCESS;
        }
        foreach ($sql as $statement) {
            $output->writeln($statement . ';');
        }

        return Command::SUCCESS;
    }
}
