<?php

declare(strict_types=1);

namespace App\Command\Outlook;

use App\Outlook\HarnessMailboxDirectory;
use App\Outlook\ScratchFileTokenProvider;
use InvalidArgumentException;
use Nowo\MailKitBundle\Mailbox\Service\MailboxSyncInterface;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function array_values;
use function is_array;
use function is_string;

/**
 * DEMO ONLY (Outlook manual test harness): registers the delegated mailbox in the demo directory and calls
 * MailboxSyncInterface::start() (account check, no subscription without a notification URL = sweep only, first sweep;
 * the demo bus is synchronous, so the first sweep runs inside this command).
 */
#[AsCommand(name: 'app:outlook:register', description: 'DEMO ONLY: register a delegated Outlook mailbox and start it (first sweep inline)')]
final class OutlookRegisterCommand extends Command
{
    public function __construct(
        private readonly HarnessMailboxDirectory $directory,
        private readonly MailboxSyncInterface $sync,
        private readonly ScratchFileTokenProvider $tokens,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('address', null, InputOption::VALUE_REQUIRED, 'Address of the test Outlook.com account (must be the token\'s account)')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Mailbox id', OutlookCommandSupport::DEFAULT_ID)
            ->addOption('folder', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Followed folder (well-known name or folder id); repeat it', ['inbox'])
            ->addOption('window', null, InputOption::VALUE_REQUIRED, 'Initial window of the first sweep (ISO 8601, e.g. P7D)')
            ->addOption('no-start', null, InputOption::VALUE_NONE, 'Only register; do not call start()');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $address = $input->getOption('address');
        $folders = $input->getOption('folder');
        $window  = $input->getOption('window');
        if (!is_string($address) || $address === '') {
            $output->writeln('<error>--address is required.</error>');

            return Command::INVALID;
        }

        try {
            $file = $this->tokens->file();
            if (!$file->exists()) {
                throw new RuntimeException('The token file does not exist yet: run make outlook-login (or put the device-code token JSON there).');
            }
            $id       = new MailboxId(OutlookCommandSupport::id($input));
            $settings = $this->directory->register($id, $address, is_array($folders) ? array_values($folders) : ['inbox'], is_string($window) && $window !== '' ? $window : null);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return Command::FAILURE;
        }
        $output->writeln('Registered mailbox <info>' . $id->value . '</info> (' . $settings->address . ').');
        if ($input->getOption('no-start') === true) {
            return Command::SUCCESS;
        }
        $status = $this->sync->start($id);
        OutlookCommandSupport::printStatus($output, $status);

        return $status->isActive() ? Command::SUCCESS : Command::FAILURE;
    }
}
