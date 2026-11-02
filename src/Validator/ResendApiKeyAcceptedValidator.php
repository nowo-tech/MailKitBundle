<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Validator;

use Nowo\DoctrineEncryptBundle\Form\SecretFieldApplier;
use Nowo\MailKitBundle\NowoMailKitBundle;
use Nowo\MailKitBundle\Resend\ResendApiChecker;
use Nowo\MailKitBundle\Settings\MailerSettingsSection;
use Nowo\MailKitBundle\Settings\MailSettings;
use Nowo\MailKitBundle\TestMail\TestMailGuard;
use SensitiveParameter;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

use function trim;

/**
 * Validator of {@see ResendApiKeyAccepted}. Reads the submitted `provider` from the root form and the submitted
 * secret (`['value' => ?string, 'clear' => bool]`); checks the key that would go live with {@see ResendApiChecker}
 * (one bounded HTTPS call to the fixed Resend host, per-user rate limit shared with the test actions). The key is
 * never part of a message, a log line or the violation's invalid value.
 */
final class ResendApiKeyAcceptedValidator extends ConstraintValidator
{
    /**
     * @param ResendApiChecker $checker Resend key check (`GET /domains`)
     * @param MailSettings $settings stored provider and key
     * @param TestMailGuard|null $guard per-user rate limit (null = unlimited)
     * @param TokenStorageInterface|null $tokenStorage current user (rate-limit key)
     * @param RequestStack|null $requestStack client address of an anonymous request (rate-limit key)
     */
    public function __construct(
        private readonly ResendApiChecker $checker,
        private readonly MailSettings $settings,
        private readonly ?TestMailGuard $guard = null,
        private readonly ?TokenStorageInterface $tokenStorage = null,
        private readonly ?RequestStack $requestStack = null,
    ) {
    }

    /**
     * Adds one violation when the key that would go live is missing or rejected (or the check is rate limited).
     */
    public function validate(#[SensitiveParameter] mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ResendApiKeyAccepted) {
            throw new UnexpectedTypeException($constraint, ResendApiKeyAccepted::class);
        }
        $root = $this->context->getRoot();
        if (!$root instanceof FormInterface || !$root->has('provider') || $root->get('provider')->getData() !== MailerSettingsSection::PROVIDER_RESEND) {
            return;
        }

        $typed = null;
        $clear = false;
        SecretFieldApplier::applyData($value, static function (?string $secret) use (&$typed, &$clear): void {
            if ($secret === null) {
                $clear = true;
            } elseif (trim($secret) !== '') {
                $typed = trim($secret);
            }
        });

        if ($typed === null && !$clear && $this->settings->provider() === MailerSettingsSection::PROVIDER_RESEND) {
            // Resend already live with the stored key: nothing new goes live.
            return;
        }
        $key = $clear ? null : ($typed ?? $this->settings->resendApiKey());
        if ($key === null) {
            $this->violation('resend.save.key_required');

            return;
        }
        if ($this->guard instanceof TestMailGuard && !$this->guard->allows($this->limiterKey())) {
            $this->violation('resend.save.rate_limited');

            return;
        }

        $result = $this->checker->check($key);
        if (!$result->ok) {
            $this->violation('resend.save.rejected', ['%error%' => (string) $result->error]);
        }
    }

    /**
     * @param array<string, string> $parameters
     */
    private function violation(string $message, array $parameters = []): void
    {
        $this->context->buildViolation($message, $parameters)
            ->setTranslationDomain(NowoMailKitBundle::TRANSLATION_DOMAIN)
            ->setInvalidValue('[redacted]')
            ->addViolation();
    }

    /**
     * Same key as the MailKit actions: the user identifier, else the client address.
     */
    private function limiterKey(): string
    {
        $user = $this->tokenStorage?->getToken()?->getUser();

        return $user instanceof UserInterface ? 'user:' . $user->getUserIdentifier() : 'ip:' . $this->requestStack?->getMainRequest()?->getClientIp();
    }
}
