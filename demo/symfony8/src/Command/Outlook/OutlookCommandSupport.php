<?php

declare(strict_types=1);

namespace App\Command\Outlook;

use DateTimeInterface;
use Nowo\MailKitBundle\Mailbox\Value\MailboxSyncStatus;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function is_string;
use function sprintf;

/**
 * DEMO ONLY (Outlook manual test harness): shared bits of the app:outlook:* commands.
 */
final class OutlookCommandSupport
{
    public const DEFAULT_ID = 'outlook-test';

    public static function id(InputInterface $input): string
    {
        $id = $input->getOption('id');

        return is_string($id) && $id !== '' ? $id : self::DEFAULT_ID;
    }

    public static function printStatus(OutputInterface $output, MailboxSyncStatus $status): void
    {
        $output->writeln(sprintf('Status: <info>%s</info>%s', $status->status->value, $status->reason !== null ? ' (reason: ' . $status->reason->value . ')' : ''));
        foreach ($status->folders as $folder) {
            $output->writeln(sprintf(
                '  folder %s: last complete sweep %s, subscription %s%s',
                $folder->folder->key,
                $folder->lastCompleteSweep?->format(DateTimeInterface::ATOM) ?? 'never',
                $folder->subscriptionExpiresAt instanceof DateTimeInterface ? 'until ' . $folder->subscriptionExpiresAt->format(DateTimeInterface::ATOM) : 'none (sweep only)',
                $folder->syncError !== null ? ', SYNC ERROR ' . $folder->syncError : '',
            ));
        }
    }
}
