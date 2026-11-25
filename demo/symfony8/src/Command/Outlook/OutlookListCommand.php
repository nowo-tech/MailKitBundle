<?php

declare(strict_types=1);

namespace App\Command\Outlook;

use App\Outlook\HarnessMessageSink;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function count;
use function is_scalar;
use function mb_strimwidth;
use function strlen;
use function substr;

/**
 * DEMO ONLY (Outlook manual test harness): what the sink received (provider id, folder, origin, duplicates, removal).
 */
#[AsCommand(name: 'app:outlook:list', description: 'DEMO ONLY: list the messages the demo sink received')]
final class OutlookListCommand extends Command
{
    public function __construct(
        private readonly HarnessMessageSink $sink,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Mailbox id', OutlookCommandSupport::DEFAULT_ID)
            ->addOption('full-ids', null, InputOption::VALUE_NONE, 'Print the whole provider ids (needed for --reply-to)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $full  = $input->getOption('full-ids') === true;
        $table = new Table($output);
        $table->setHeaders(['provider id', 'folder', 'origin', 'received', 'from', 'subject', 'att', 'dups', 'removed']);
        $rows = $this->sink->all(OutlookCommandSupport::id($input));
        foreach ($rows as $row) {
            $id = self::text($row['provider_message_id']);
            $table->addRow([
                $full || strlen($id) <= 32 ? $id : substr($id, 0, 16) . '…' . substr($id, -12),
                self::text($row['folder']),
                self::text($row['origin']),
                self::text($row['received_at']),
                self::text($row['from_address']),
                mb_strimwidth(self::text($row['subject']), 0, 40, '…'),
                self::text($row['attachment_count']) . ($row['attachment_count'] > 0 ? ' (' . self::text($row['attachment_bytes']) . ' B)' : ''),
                self::text($row['duplicate_count']),
                $row['removed_reason'] !== null ? self::text($row['removed_reason']) . ' from ' . self::text($row['removed_folder']) : '',
            ]);
        }
        $table->render();
        $output->writeln(count($rows) . ' message(s). "dups" counts later store() calls that answered Duplicate (a sweep, a move, a sent copy).');

        return Command::SUCCESS;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
