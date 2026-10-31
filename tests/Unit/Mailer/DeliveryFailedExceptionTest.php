<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Mailer;

use LogicException;
use Nowo\MailKitBundle\Log\ErrorScrubber;
use Nowo\MailKitBundle\Mailer\DeliveryFailedException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportException;

use function str_repeat;

/**
 * CA M1 / L3 / I3: no provider body, no credential, a reference instead.
 */
final class DeliveryFailedExceptionTest extends TestCase
{
    public function testProviderBodiesNeverReachTheMessage(): void
    {
        $response = (new MockHttpClient(new MockResponse('<html>INTERNAL-ADMIN-PAGE secret_token=abc123</html>', ['http_code' => 403])))->request('POST', 'https://api.mailgun.net/v3/x');
        $failure  = DeliveryFailedException::from(new HttpTransportException('Unable to send an email: <html>INTERNAL-ADMIN-PAGE secret_token=abc123</html> (code 403).', $response));

        self::assertMatchesRegularExpression('/^The mail provider answered HTTP 403 \(reference [0-9a-f]{8}\)\.$/', $failure->getMessage());
        self::assertStringContainsString('INTERNAL-ADMIN-PAGE', $failure->detail);
        self::assertNull($failure->getPrevious());
        self::assertSame($failure, DeliveryFailedException::from($failure));
    }

    public function testSmtpAnswersAreKeptScrubbedAndCapped(): void
    {
        $failure = DeliveryFailedException::from(new TransportException("Expected response code \"250\" but got code \"535\", with message \"535 bad credentials for username \"mk-user\"\".\nsecond line"), 'mk-user');
        self::assertStringContainsString('535', $failure->getMessage());
        self::assertStringNotContainsString('mk-user', $failure->getMessage());
        self::assertStringNotContainsString('second line', $failure->getMessage());
        self::assertStringContainsString('(reference ' . $failure->reference . ')', $failure->getMessage());

        $long = DeliveryFailedException::from(new TransportException(str_repeat('x', 1000)));
        self::assertLessThan(400, mb_strlen($long->getMessage()));
    }

    public function testOtherFailuresGetAGenericMessage(): void
    {
        foreach ([new TransportException('Unable to send an email: plain text body'), new TransportException('<b>html</b>'), new TransportException(''), new LogicException('boom')] as $exception) {
            self::assertStringStartsWith('Mail delivery failed: ', DeliveryFailedException::from($exception)->getMessage());
        }
    }

    public function testScrubberPatterns(): void
    {
        // Provider-shaped keys are built at runtime from obvious placeholders (no realistic secret in the source).
        $keys     = 'SG.' . str_repeat('R', 16) . '.' . str_repeat('T', 16) . ' xkeysib-' . str_repeat('0', 20) . ' key-' . str_repeat('0', 32);
        $scrubbed = ErrorScrubber::scrub('user mk-user / pass p%40ss%3Aword / ' . base64_encode('p@ss:word') . ' username "mk-user" ' . $keys . ' token ' . str_repeat('0', 8) . '-0000-0000-0000-' . str_repeat('0', 12), 'mk-user', 'p@ss:word', 'x');

        self::assertSame('user *** / pass *** / *** username "***" SG.*** xkeysib-*** key-*** token ***', $scrubbed);
    }
}
