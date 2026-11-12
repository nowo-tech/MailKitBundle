<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Transport;

use Nowo\MailKitBundle\Graph\GraphMailbox;
use Nowo\MailKitBundle\Graph\GraphMailboxProviderInterface;
use Nowo\MailKitBundle\Graph\Send\GraphMailSender;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;

use function preg_match;

/**
 * Builds `microsoft+graph://<profile>` transports (1.8; registered as `mailer.transport_factory` when
 * `nowo_mail_kit.graph.enabled` is true). The DSN names a profile only — `default` is the provider's default profile —
 * and carries no credentials: they come from the {@see GraphMailboxProviderInterface}.
 */
final class GraphTransportFactory implements TransportFactoryInterface
{
    /** DSN scheme. */
    public const SCHEME = 'microsoft+graph';

    /**
     * @param GraphMailSender $sender sender shared by every transport (stateless)
     */
    public function __construct(
        private readonly GraphMailSender $sender,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Transport of the DSN's profile.
     */
    public function create(Dsn $dsn): TransportInterface
    {
        if (!$this->supports($dsn)) {
            throw new UnsupportedSchemeException($dsn, 'microsoft-graph', [self::SCHEME]);
        }
        if ($dsn->getUser() !== null || $dsn->getPassword() !== null) {
            throw new InvalidArgumentException('The microsoft+graph DSN must not carry credentials: configure them in nowo_mail_kit.graph (or your mailbox provider).');
        }
        $host = $dsn->getHost();
        if (preg_match(GraphMailbox::PROFILE_PATTERN, $host) !== 1) {
            throw new InvalidArgumentException('The host of a microsoft+graph DSN must be a profile name (letters, digits, "_", ".", "-").');
        }

        return new GraphTransport($this->sender, $host === 'default' ? null : $host, $this->dispatcher, $this->logger);
    }

    /**
     * True for `microsoft+graph://`.
     */
    public function supports(Dsn $dsn): bool
    {
        return $dsn->getScheme() === self::SCHEME;
    }
}
