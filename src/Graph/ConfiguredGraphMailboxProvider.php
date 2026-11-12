<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph;

use SensitiveParameter;

use function sprintf;

/**
 * Default {@see GraphMailboxProviderInterface} (1.8): the profiles of `nowo_mail_kit.graph.profiles`. Secrets come
 * from `%env()%` placeholders, resolved by the container at runtime; the mailbox is built per call.
 */
final class ConfiguredGraphMailboxProvider implements GraphMailboxProviderInterface
{
    /**
     * @param array<string, array{tenant_id: string, client_id: string, client_secret: string, mailbox: string}> $profiles
     */
    public function __construct(
        #[SensitiveParameter]
        private readonly array $profiles,
        private readonly string $defaultProfile,
    ) {
    }

    /**
     * The configured profile.
     */
    public function mailbox(string $profile): GraphMailbox
    {
        $config = $this->profiles[$profile] ?? null;
        if ($config === null) {
            throw GraphException::configuration(sprintf('Unknown Microsoft Graph mailbox profile "%s".', GraphException::safeToken($profile) ?? '?'));
        }

        return new GraphMailbox($profile, $config['tenant_id'], $config['client_id'], new GraphSecret($config['client_secret']), $config['mailbox']);
    }

    /**
     * `nowo_mail_kit.graph.default_profile`.
     */
    public function defaultProfile(): string
    {
        return $this->defaultProfile;
    }

    /**
     * Debug output without the secrets.
     *
     * @return array{profiles: list<string>, defaultProfile: string}
     */
    public function __debugInfo(): array
    {
        $names = [];
        foreach ($this->profiles as $name => $unused) {
            $names[] = (string) $name;
        }

        return ['profiles' => $names, 'defaultProfile' => $this->defaultProfile];
    }
}
