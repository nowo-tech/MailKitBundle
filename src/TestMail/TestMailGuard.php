<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\TestMail;

use Symfony\Component\RateLimiter\RateLimiterFactory;

use function hash;
use function strtolower;

/**
 * Per-user rate limit of the test e-mail actions (`test_email.rate_limit` per `test_email.rate_interval`, sliding
 * window over `cache.app`), so the action cannot be used to flood an inbox or burn the provider quota.
 */
final readonly class TestMailGuard
{
    /**
     * @param RateLimiterFactory $limiter limiter `nowo_mail_kit.test_email`
     */
    public function __construct(
        private RateLimiterFactory $limiter,
    ) {
    }

    /**
     * Consumes one token for the key (user identifier, or client address for anonymous demo access); false when the
     * limit is reached.
     */
    public function allows(string $key): bool
    {
        return $this->limiter->create(hash('sha256', strtolower($key)))->consume()->isAccepted();
    }
}
