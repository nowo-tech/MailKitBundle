<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Template\Infrastructure\Command;

use Nowo\MailKitBundle\Template\Infrastructure\Store\TemplateStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `nowo:mail-kit:templates:schema` (2.7.0): prints, or creates with `--force`, the missing template table in the SQL of
 * the connection's platform (MySQL / MariaDB / PostgreSQL / SQLite), for hosts without the ORM schema listener or
 * migrations, or to copy into a hand-written migration.
 */
#[AsCommand(name: self::NAME, description: 'Print (or create with --force) the missing e-mail template table.')]
final class TemplateSchemaCommand extends Command
{
    /** Command name. */
    public const NAME = 'nowo:mail-kit:templates:schema';

    /**
     * Wires the command.
     */
    public function __construct(
        private readonly TemplateStore $store,
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
            $output->writeln('The template table exists.');

            return Command::SUCCESS;
        }
        if ($input->getOption('force') === true) {
            $this->store->execute($sql);
            $output->writeln('Created the template table.');

            return Command::SUCCESS;
        }
        foreach ($sql as $statement) {
            $output->writeln($statement . ';');
        }

        return Command::SUCCESS;
    }
}
