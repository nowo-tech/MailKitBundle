<?php

declare(strict_types=1);

namespace App\Outlook;

use Doctrine\DBAL\Connection;
use Nowo\MailKitBundle\Mailbox\Port\MailboxDirectoryInterface;
use Nowo\MailKitBundle\Mailbox\Value\FolderRef;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\MailboxProvider;
use Nowo\MailKitBundle\Mailbox\Value\MailboxSettings;

use function array_map;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function strtolower;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * DEMO ONLY (Outlook manual test harness): MailKit's directory port over the `demo_outlook_mailbox` table (Graph
 * mailboxes only).
 */
final class HarnessMailboxDirectory implements MailboxDirectoryInterface
{
    public const TABLE = 'demo_outlook_mailbox';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Registers (or replaces) a mailbox; validated through MailboxSettings first.
     *
     * @param list<string> $folders
     */
    public function register(MailboxId $id, string $address, array $folders, ?string $initialWindow): MailboxSettings
    {
        $settings = new MailboxSettings($id, MailboxProvider::Graph, $address, array_map(static fn (string $key): FolderRef => new FolderRef($key), $folders), false, $initialWindow);
        $row      = [
            'address'        => $settings->address,
            'folders'        => json_encode($folders, JSON_THROW_ON_ERROR),
            'paused'         => 0,
            'initial_window' => $initialWindow,
        ];
        $this->connection->transactional(static function (Connection $connection) use ($id, $row): void {
            if ($connection->fetchOne('SELECT 1 FROM ' . self::TABLE . ' WHERE id = ?', [$id->value]) !== false) {
                $connection->update(self::TABLE, $row, ['id' => $id->value]);

                return;
            }
            $connection->insert(self::TABLE, ['id' => $id->value] + $row);
        });

        return $settings;
    }

    public function mailbox(MailboxId $mailbox): ?MailboxSettings
    {
        $row = $this->connection->fetchAssociative('SELECT address, folders, paused, initial_window FROM ' . self::TABLE . ' WHERE id = ?', [$mailbox->value]);
        if ($row === false) {
            return null;
        }
        $folders = json_decode((string) $row['folders'], true, 4, JSON_THROW_ON_ERROR);
        $refs    = [];
        foreach (is_array($folders) ? $folders : [] as $key) {
            if (is_string($key)) {
                $refs[] = new FolderRef($key);
            }
        }
        $window = $row['initial_window'];

        return new MailboxSettings($mailbox, MailboxProvider::Graph, (string) $row['address'], $refs, (bool) $row['paused'], is_string($window) && $window !== '' ? $window : null);
    }

    public function activeMailboxes(MailboxProvider $provider): iterable
    {
        if ($provider !== MailboxProvider::Graph) {
            return [];
        }
        $ids = [];
        foreach ($this->connection->fetchFirstColumn('SELECT id FROM ' . self::TABLE . ' WHERE paused = 0 ORDER BY id') as $id) {
            $ids[] = new MailboxId((string) $id);
        }

        return $ids;
    }

    public function findByAddress(MailboxProvider $provider, string $address): ?MailboxId
    {
        if ($provider !== MailboxProvider::Graph) {
            return null;
        }
        $id = $this->connection->fetchOne('SELECT id FROM ' . self::TABLE . ' WHERE address = ?', [strtolower(trim($address))]);

        return is_string($id) ? new MailboxId($id) : null;
    }
}
