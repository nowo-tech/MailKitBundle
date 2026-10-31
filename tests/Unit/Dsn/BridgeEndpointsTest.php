<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Dsn;

use Nowo\MailKitBundle\Dsn\DsnHostGuard;
use Nowo\MailKitBundle\Dsn\MailerDsnValidator;
use Nowo\MailKitBundle\Mailer\DeliveryFailedException;
use Nowo\MailKitBundle\Mailer\RuntimeTransport;
use Nowo\MailKitBundle\Sender\SenderPolicy;
use Nowo\MailKitBundle\Settings\MailSettings;
use Nowo\MailKitBundle\Tests\Support\FakeSettings;
use Nowo\MailKitBundle\Tests\Support\MemoryMailStore;
use Nowo\MailKitBundle\Tests\Support\MemoryTransport;
use Nowo\MailKitBundle\Tests\Support\StaticDsnProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Bridge\Amazon\Transport\SesTransportFactory;
use Symfony\Component\Mailer\Bridge\Mailgun\Transport\MailgunTransportFactory;
use Symfony\Component\Mailer\Bridge\Scaleway\Transport\ScalewayTransportFactory;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Component\Mime\Email;

/**
 * CA follow-up N2 / N1 / info: SES and Scaleway DSNs validate and send (their transport strings carry more than the
 * host), unreachable API endpoints give a scrubbed failure, TLS checks cannot be switched off silently.
 */
final class BridgeEndpointsTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string|null, 2?: bool}>
     */
    public static function dsns(): iterable
    {
        yield 'ses api default endpoint' => ['ses+api://REDACTED-TEST:REDACTED-TEST@default?region=eu-west-1', null];
        yield 'ses https default endpoint' => ['ses+https://REDACTED-TEST:REDACTED-TEST@default?region=us-gov-west-1', null];
        yield 'ses region with a trailing newline' => ['ses+api://REDACTED-TEST:REDACTED-TEST@default?region=eu-west-1%0A', MailerDsnValidator::OPTION_NOT_ALLOWED];
        yield 'ses custom private endpoint' => ['ses+api://REDACTED-TEST:REDACTED-TEST@10.0.0.5', DsnHostGuard::HOST_NOT_ALLOWED];
        yield 'scaleway default endpoint' => ['scaleway+api://REDACTED-TEST:REDACTED-TEST@default', null];
        yield 'scaleway region' => ['scaleway+api://REDACTED-TEST:REDACTED-TEST@default?region=nl-ams', null];
        yield 'insecure tls refused' => ['smtp://8.8.8.8:587?verify_peer=0', MailerDsnValidator::INSECURE_TLS];
        yield 'insecure peer name refused' => ['smtp://8.8.8.8:587?verify_peer_name=false', MailerDsnValidator::INSECURE_TLS];
        yield 'tls on accepted' => ['smtp://8.8.8.8:587?verify_peer=1', null];
        yield 'insecure tls allowed in dev' => ['smtp://8.8.8.8:587?verify_peer=0', null, true];
    }

    #[DataProvider('dsns')]
    public function testValidate(string $dsn, ?string $expected, bool $allowInsecureTls = false): void
    {
        self::assertSame($expected, (new MailerDsnValidator(MailerDsnValidator::DEFAULT_SCHEMES, new DsnHostGuard(resolveDns: false), $this->factory(new MockHttpClient()), $allowInsecureTls))->validate($dsn));
    }

    public function testEndpointHostOfTheBridges(): void
    {
        self::assertSame('email.eu-west-1.amazonaws.com', MailerDsnValidator::endpointHost('ses+api://AKIA@eu-west-1'));
        self::assertSame('email.example.com', MailerDsnValidator::endpointHost('ses+https://AKIA@email.example.com:8443'));
        self::assertSame('api.scaleway.com', MailerDsnValidator::endpointHost('scaleway+api://api.scaleway.com@project?region=fr-par'));
        self::assertNull(MailerDsnValidator::endpointHost('ses+api://AKIA@'));
        self::assertNull(MailerDsnValidator::endpointHost('scaleway+api://@project'));
        self::assertNull(MailerDsnValidator::endpointHost('mailgun+https://a@b'));
    }

    public function testSesAndScalewaySendThroughAMockedClient(): void
    {
        $client = new MockHttpClient([new JsonMockResponse(['MessageId' => 'ses-1']), new JsonMockResponse(['emails' => [['message_id' => 'scw-1']]])]);
        foreach (['ses+api://REDACTED-TEST:REDACTED-TEST@default?region=eu-west-1', 'scaleway+api://REDACTED-TEST:REDACTED-TEST@default'] as $dsn) {
            $sent = $this->transport($dsn, $client)->send((new Email())->to('a@example.com')->subject('S')->text('t'));
            self::assertNotNull($sent);
        }
        self::assertSame(2, $client->getRequestsCount());
    }

    public function testAnUnreachableApiGivesAScrubbedFailure(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['error' => 'Could not resolve host: api.mailgun.net (https://api.mailgun.net/v3/x)']));

        $this->expectException(DeliveryFailedException::class);
        $this->expectExceptionMessageMatches('/^The mail provider could not be reached \(reference [0-9a-f]{8}\)\.$/');
        $this->transport('mailgun+api://REDACTED-TEST:example.com@default', $client)->send((new Email())->to('a@example.com')->subject('S')->text('t'));
    }

    private function factory(MockHttpClient $client): Transport
    {
        return new Transport([new EsmtpTransportFactory(), new SesTransportFactory(null, $client), new ScalewayTransportFactory(null, $client), new MailgunTransportFactory(null, $client)]);
    }

    private function transport(string $dsn, MockHttpClient $client): RuntimeTransport
    {
        $factory = $this->factory($client);

        return new RuntimeTransport(
            new MemoryTransport('fallback', new MemoryMailStore()),
            new StaticDsnProvider($dsn),
            new MailerDsnValidator(MailerDsnValidator::DEFAULT_SCHEMES, new DsnHostGuard(resolveDns: false), $factory),
            $factory,
            new SenderPolicy(new MailSettings(new FakeSettings(['mailer.from' => 'noreply@example.com']))),
        );
    }
}
