<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Infrastructure\Command;

use Nowo\MailKitBundle\Mailbox\Engine\MailboxSender;
use Nowo\MailKitBundle\Mailbox\Engine\MailboxSyncOptions;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Store\MailboxStore;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function sprintf;

/**
 * `nowo:mail-kit:mailbox:purge` (2.5.0 part 2): deletes the settled send rows (`sent`, `failed`, `unknown`, `released`)
 * older than `mailboxes.send.retention` (default `P7D`). Rows of sends in flight are kept. Schedule it daily. A key
 * reused after its row was purged is sent again: keep the retention longer than your own retries. With `--drafts` it
 * first deletes the drafts those `released` / `failed` rows still name, each only after Graph reads it as a draft
 * (audit ZAV-I4; one Graph call or two per draft); a row whose draft could not be dealt with is kept, so the next run
 * retries it.
 */
#[AsCommand(name: self::NAME, description: 'Delete settled delegated-mailbox send rows older than the retention.')]
final class MailboxPurgeCommand extends Command
{
    /** Command name. */
    public const NAME = 'nowo:mail-kit:mailbox:purge';

    /**
     * @param string $retention `mailboxes.send.retention` (ISO 8601 days / hours)
     */
    public function __construct(
        private readonly MailboxStore $store,
        private readonly string $retention = 'P7D',
        private readonly ?ClockInterface $clock = null,
        private readonly ?MailboxSender $sender = null,
    ) {
        parent::__construct(self::NAME);
    }

    protected function configure(): void
    {
        $this->addOption('drafts', null, InputOption::VALUE_NONE, 'First delete the leftover drafts of released / failed sends (read as drafts first)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $before = ($this->clock ?? new NativeClock())->now()->getTimestamp() - MailboxSyncOptions::seconds($this->retention);
        if ($input->getOption('drafts') === true && $this->sender instanceof MailboxSender) {
            // Rows whose draft could not be dealt with are kept for the next run.
            $output->writeln(sprintf('%d leftover draft(s) deleted.', $this->sender->purgeOrphanDrafts($before)));
        }
        $drafts  = $input->getOption('drafts') === true && $this->sender instanceof MailboxSender;
        $deleted = $this->store->purgeSends($before, 500, $drafts);
        $output->writeln(sprintf('%d send row(s) deleted.', $deleted));

        return Command::SUCCESS;
    }
}
