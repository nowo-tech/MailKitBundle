<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Resend;

use Nowo\MailKitBundle\Resend\ResendApiChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ResendApiCheckerTest extends TestCase
{
    public function testDomainsAndOptions(): void
    {
        $seen   = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): JsonMockResponse {
            $seen = ['method' => $method, 'url' => $url, 'auth' => $options['normalized_headers']['authorization'][0] ?? null, 'timeout' => $options['timeout'], 'max_redirects' => $options['max_redirects']];

            return new JsonMockResponse(['data' => [['name' => 'example.com', 'status' => 'verified'], ['name' => 'other.com'], 'junk']]);
        });
        $result = (new ResendApiChecker($client, 3.0))->check('re_REDACTED-TEST');

        self::assertTrue($result->ok);
        self::assertSame(['example.com' => 'verified', 'other.com' => 'unknown'], $result->domains);
        self::assertNull($result->error);
        self::assertSame('GET', $seen['method']);
        self::assertSame(ResendApiChecker::DOMAINS_URL, $seen['url']);
        self::assertSame('Authorization: Bearer re_REDACTED-TEST', $seen['auth']);
        self::assertSame(3.0, $seen['timeout']);
        self::assertSame(0, $seen['max_redirects']);
    }

    public function testRestrictedRejectedAndTimeout(): void
    {
        self::assertNull((new ResendApiChecker(new MockHttpClient(new JsonMockResponse(['name' => 'restricted_api_key'], ['http_code' => 401]))))->check('k')->domains);

        $rejected = (new ResendApiChecker(new MockHttpClient(new JsonMockResponse(['message' => 'Invalid key re_REDACTEDTEST'], ['http_code' => 403]))))->check('k');
        self::assertFalse($rejected->ok);
        self::assertSame('Invalid key re_***', $rejected->error);

        self::assertSame('HTTP 500', (new ResendApiChecker(new MockHttpClient(new MockResponse('{}', ['http_code' => 500]))))->check('k')->error);

        // REQ-RUNTIME-001: a deadline hit (idle timeout) is a controlled failure, not a hang.
        $timeout = (new ResendApiChecker(new MockHttpClient(new MockResponse([''], ['http_code' => 200])), 1.0))->check('k');
        self::assertFalse($timeout->ok);
        self::assertNotEmpty($timeout->error);
    }
}
