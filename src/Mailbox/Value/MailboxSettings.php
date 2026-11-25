<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Value;

use InvalidArgumentException;

use function count;
use function filter_var;
use function preg_match;
use function strlen;
use function strtolower;
use function trim;

use const FILTER_FLAG_EMAIL_UNICODE;
use const FILTER_VALIDATE_EMAIL;

/**
 * Settings of one delegated mailbox as the host's directory reports them (2.5.0): provider, address (lower-cased; the
 * account behind the token must have it), followed folders, host pause and the window of the first sweep.
 *
 * The VO enforces the hard bounds (1 to {@see MAX_FOLDERS} distinct folders, a window of days / hours); MailKit also
 * checks the configured `mailboxes.max_folders` and `mailboxes.sync.max_initial_window`. A send-only provider
 * ({@see MailboxProvider::isSendOnly()}, Gmail) follows no folder: its list must be empty.
 */
final readonly class MailboxSettings
{
    /** Hard cap of followed folders (the configured `max_folders` is at most this). */
    public const MAX_FOLDERS = 100;

    /** Accepted windows: ISO 8601 days and / or hours (`P7D`, `PT12H`, `P1DT6H`). */
    public const WINDOW_PATTERN = '/^P(?:[1-9]\d{0,3}D|T[1-9]\d{0,4}H|[1-9]\d{0,3}DT[1-9]\d{0,4}H)\z/';

    /** The address, trimmed and lower-cased. */
    public string $address;

    /** @var list<FolderRef> followed folders, distinct, in the host's order */
    public array $folders;

    /**
     * @param list<FolderRef> $folders followed folders (1 to {@see MAX_FOLDERS}, distinct keys; none for a send-only provider)
     * @param string|null $initialWindow window of the first sweep (`P30D`), null = `mailboxes.sync.initial_window`
     *
     * @throws InvalidArgumentException on an invalid address, folder list or window
     */
    public function __construct(
        public MailboxId $id,
        public MailboxProvider $provider,
        string $address,
        array $folders,
        public bool $paused = false,
        public ?string $initialWindow = null,
    ) {
        $address = strtolower(trim($address));
        if ($address === '' || strlen($address) > 254 || preg_match('/[\x00-\x20<>"]/', $address) === 1 || filter_var($address, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) === false) {
            throw new InvalidArgumentException('The mailbox address is not a valid e-mail address.');
        }
        if ($provider->isSendOnly() && $folders !== []) {
            throw new InvalidArgumentException('A send-only mailbox (' . $provider->value . ') follows no folders: it has no inbox.');
        }
        if (!$provider->isSendOnly() && ($folders === [] || count($folders) > self::MAX_FOLDERS)) {
            throw new InvalidArgumentException('A mailbox follows 1 to 100 folders.');
        }
        $keys = [];
        foreach ($folders as $folder) {
            if (isset($keys[$folder->key])) {
                throw new InvalidArgumentException('A mailbox follows each folder once.');
            }
            $keys[$folder->key] = true;
        }
        if ($initialWindow !== null && preg_match(self::WINDOW_PATTERN, $initialWindow) !== 1) {
            throw new InvalidArgumentException('The initial window must be an ISO 8601 duration in days and / or hours such as "P30D".');
        }
        $this->address = $address;
        $this->folders = $folders;
    }

    /**
     * The followed folder with this key, or null.
     */
    public function folder(string $key): ?FolderRef
    {
        foreach ($this->folders as $folder) {
            if ($folder->key === $key) {
                return $folder;
            }
        }

        return null;
    }
}
