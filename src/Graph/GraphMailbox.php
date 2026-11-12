<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph;

use SensitiveParameter;

use function filter_var;
use function preg_match;
use function sprintf;
use function strlen;
use function strtolower;
use function trim;

use const FILTER_VALIDATE_EMAIL;

/**
 * A shared mailbox reachable through Microsoft Graph with app-only credentials (1.8): the profile name, the Entra tenant
 * (GUID or verified domain), the app (client) id, the client secret and the mailbox address. Returned by a
 * {@see GraphMailboxProviderInterface}; validated here so a host value can never inject a path into the token or Graph
 * URLs. `var_dump()` shows no secret.
 */
final class GraphMailbox
{
    /** Profile names: letters, digits, `_`, `.`, `-`, at most 64 characters. */
    public const PROFILE_PATTERN = '/^[A-Za-z0-9_.-]{1,64}\z/';

    /** Entra tenant: a GUID or a domain name. */
    private const TENANT_PATTERN = '/^(?:[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}|(?=.{1,253}\z)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63})\z/';

    /** Application (client) id: a GUID. */
    private const CLIENT_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/';

    /** Mailbox address, lower-cased. */
    public readonly string $address;

    /**
     * @param string $profile profile name (see PROFILE_PATTERN)
     * @param string $tenantId Entra tenant id (GUID) or verified domain
     * @param string $clientId application (client) id (GUID)
     * @param GraphSecret $secret client secret
     * @param string $address shared mailbox address (the From of every message)
     */
    public function __construct(
        public readonly string $profile,
        public readonly string $tenantId,
        public readonly string $clientId,
        #[SensitiveParameter]
        public readonly GraphSecret $secret,
        string $address,
    ) {
        if (preg_match(self::PROFILE_PATTERN, $profile) !== 1) {
            throw GraphException::configuration('Invalid Microsoft Graph mailbox profile name.');
        }
        if (preg_match(self::TENANT_PATTERN, $tenantId) !== 1) {
            throw GraphException::configuration(sprintf('Invalid Microsoft Graph tenant id for profile "%s" (a GUID or a domain name).', $profile));
        }
        if (preg_match(self::CLIENT_PATTERN, $clientId) !== 1) {
            throw GraphException::configuration(sprintf('Invalid Microsoft Graph client id for profile "%s" (a GUID).', $profile));
        }
        $address = strtolower(trim($address));
        if (strlen($address) > 254 || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw GraphException::configuration(sprintf('Invalid Microsoft Graph mailbox address for profile "%s".', $profile));
        }
        $this->address = $address;
    }

    /**
     * Debug output without the secret.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'profile'  => $this->profile,
            'tenantId' => $this->tenantId,
            'clientId' => $this->clientId,
            'secret'   => '[redacted]',
            'address'  => $this->address,
        ];
    }
}
