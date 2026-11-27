<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Infrastructure\Command;

use DateTimeInterface;
use InvalidArgumentException;
use Nowo\MailKitBundle\Mailbox\Service\MailboxSyncInterface;
use Nowo\MailKitBundle\Mailbox\Value\GrantFailureReason;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\MailboxProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function is_string;
use function sprintf;

/**
 * `nowo:mail-kit:mailbox:status <id>` (2.5.0): status, grant-failure reason, provider (a send-only Gmail mailbox says
 * so) and per folder the last complete sweep and the subscription expiry. No address or other personal data.
 */
#[AsCommand(name: self::NAME, description: 'Show the sync status of one delegated mailbox (no personal data).')]
final class MailboxStatusCommand extends Command
{
    /** Command name. */
    public const NAME = 'nowo:mail-kit:mailbox:status';

    /**
     * Wires the command.
     */
    public function __construct(
        private readonly MailboxSyncInterface $sync,
    ) {
        parent::__construct(self::NAME);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('mailbox', InputArgument::REQUIRED, 'The host\'s mailbox id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getArgument('mailbox');

        try {
            $status = $this->sync->status(new MailboxId(is_string($id) ? $id : ''));
        } catch (InvalidArgumentException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return Command::INVALID;
        }
        $output->writeln(sprintf('Status: %s%s', $status->status->value, $status->reason instanceof GrantFailureReason ? ' (' . $status->reason->value . ')' : ''));
        if ($status->provider instanceof MailboxProvider) {
            $output->writeln(sprintf('Provider: %s%s', $status->provider->value, $status->isSendOnly() ? ' (send only: no inbox, nothing is synced)' : ''));
        }
        foreach ($status->folders as $folder) {
            $output->writeln(sprintf(
                '  %s: last complete sweep %s, subscription %s%s',
                $folder->folder->key,
                $folder->lastCompleteSweep?->format(DateTimeInterface::ATOM) ?? 'never',
                $folder->subscriptionExpiresAt !== null ? 'until ' . $folder->subscriptionExpiresAt->format(DateTimeInterface::ATOM) : 'none',
                $folder->syncError !== null ? ', sync error ' . $folder->syncError . ' since ' . ($folder->syncErrorSince?->format(DateTimeInterface::ATOM) ?? '?') : '',
            ));
        }

        return Command::SUCCESS;
    }
}
