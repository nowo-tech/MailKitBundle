<?php

declare(strict_types=1);

namespace App\Command\Outlook;

use App\Outlook\HarnessMessageSink;
use InvalidArgumentException;
use Nowo\MailKitBundle\Mailbox\Service\MailboxSyncInterface;
use Nowo\MailKitBundle\Mailbox\Value\FolderRef;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function count;
use function is_string;

/**
 * DEMO ONLY (Outlook manual test harness): one sync pass. MailboxSyncInterface::sweep() enqueues SyncMailboxFolder;
 * the demo bus has no transport, so the sweep, its fetches and the sink calls all run inside this command (no
 * Messenger worker, no webhook).
 */
#[AsCommand(name: 'app:outlook:sync', description: 'DEMO ONLY: one sweep pass of the followed folders (inline, no worker)')]
final class OutlookSyncCommand extends Command
{
    public function __construct(
        private readonly MailboxSyncInterface $sync,
        private readonly HarnessMessageSink $sink,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Mailbox id', OutlookCommandSupport::DEFAULT_ID)
            ->addOption('folder', null, InputOption::VALUE_REQUIRED, 'Only this followed folder');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $folder = $input->getOption('folder');

        try {
            $id = new MailboxId(OutlookCommandSupport::id($input));
            $this->sync->sweep($id, is_string($folder) && $folder !== '' ? new FolderRef($folder) : null);
        } catch (InvalidArgumentException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return Command::INVALID;
        }
        $status = $this->sync->status($id);
        OutlookCommandSupport::printStatus($output, $status);
        $output->writeln('Messages known to the demo sink: ' . count($this->sink->all($id->value)) . ' (app:outlook:list shows them).');

        return $status->isActive() ? Command::SUCCESS : Command::FAILURE;
    }
}
