<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Resend;

use Nowo\MailKitBundle\Log\ErrorScrubber;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function count;
use function is_array;
use function is_string;
use function mb_substr;

/**
 * Checks a Resend API key with `GET https://api.resend.com/domains` (fixed host: no SSRF surface), bounded by
 * `resend.api_timeout` (connect + total, REQ-RUNTIME-001) and without redirects. A sending-only key cannot list
 * domains (`restricted_api_key`) but is valid for delivery.
 */
final readonly class ResendApiChecker
{
    /** Endpoint of the check. */
    public const DOMAINS_URL = 'https://api.resend.com/domains';

    /** Longest error message kept from the API answer. */
    public const MESSAGE_MAX = 200;

    /**
     * @param float $timeout seconds (connect and total)
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        private float $timeout = 10.0,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Result of the check; the key is never logged.
     */
    public function check(#[SensitiveParameter] string $apiKey): ResendCheckResult
    {
        try {
            $response = $this->httpClient->request('GET', self::DOMAINS_URL, [
                'auth_bearer'   => $apiKey,
                'timeout'       => $this->timeout,
                'max_duration'  => $this->timeout,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            $body   = $response->toArray(false);
        } catch (ExceptionInterface $exception) {
            $this->logger?->warning('Resend API key check failed.', ['bundle' => 'NowoMailKitBundle', 'error' => $exception::class]);

            return ResendCheckResult::failed(mb_substr(ErrorScrubber::scrub($exception->getMessage(), $apiKey), 0, self::MESSAGE_MAX));
        }

        if ($status === 200) {
            $domains = [];
            foreach (is_array($body['data'] ?? null) ? $body['data'] : [] as $domain) {
                if (is_array($domain) && is_string($domain['name'] ?? null)) {
                    $domains[$domain['name']] = is_string($domain['status'] ?? null) ? $domain['status'] : 'unknown';
                }
            }
            $this->logger?->info('Resend API key check passed.', ['bundle' => 'NowoMailKitBundle', 'domains' => count($domains)]);

            return ResendCheckResult::ok($domains);
        }

        if (($body['name'] ?? null) === 'restricted_api_key') {
            return ResendCheckResult::ok(null);
        }

        $message = is_string($body['message'] ?? null) && $body['message'] !== '' ? $body['message'] : 'HTTP ' . $status;
        $this->logger?->warning('Resend API key check rejected.', ['bundle' => 'NowoMailKitBundle', 'status' => $status]);

        return ResendCheckResult::failed(mb_substr(ErrorScrubber::scrub($message, $apiKey), 0, self::MESSAGE_MAX));
    }
}
