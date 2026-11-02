<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Validator;

use Nowo\MailKitBundle\Resend\ResendApiChecker;
use Nowo\MailKitBundle\Settings\MailSettings;
use Nowo\MailKitBundle\TestMail\TestMailGuard;
use Nowo\MailKitBundle\Tests\Support\FakeSettings;
use Nowo\MailKitBundle\Validator\MailerDsn;
use Nowo\MailKitBundle\Validator\ResendApiKeyAccepted;
use Nowo\MailKitBundle\Validator\ResendApiKeyAcceptedValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Validation;

use function count;
use function strlen;
use function substr;

/**
 * MK-L1 (1.3): a Resend key is checked against the Resend API before a save can put it live.
 */
final class ResendApiKeyAcceptedValidatorTest extends TestCase
{
    /** @var list<string> bearer keys sent to the mock API */
    private array $checked = [];

    public function testOnlyResendSavesThatPutAKeyLiveAreChecked(): void
    {
        $settings = new FakeSettings(['mailer.provider' => 'dsn'], ['mailer.resend_api_key' => 're_REDACTED-STORED']);

        // DSN submitted: nothing is checked, even with a typed key.
        self::assertSame([], $this->errors($settings, 'dsn', 're_REDACTED-TYPED'));
        // Switching to Resend with the stored key: the stored key is checked.
        self::assertSame([], $this->errors($settings, 'resend', ''));
        // A typed key is checked (and refused here).
        self::assertSame(['resend.save.rejected'], $this->errors($settings, 'resend', ' re_REDACTED-WRONG ', valid: false));
        self::assertSame(['re_REDACTED-STORED', 're_REDACTED-WRONG'], $this->checked);

        // Resend already live, key kept: no call.
        $live          = new FakeSettings(['mailer.provider' => 'resend'], ['mailer.resend_api_key' => 're_REDACTED-STORED']);
        $this->checked = [];
        self::assertSame([], $this->errors($live, 'resend', ''));
        self::assertSame([], $this->checked);
        // Resend live, key rotated: the new key is checked.
        self::assertSame([], $this->errors($live, 'resend', 're_REDACTED-ROTATED'));
        self::assertSame(['re_REDACTED-ROTATED'], $this->checked);
    }

    public function testResendNeedsAKey(): void
    {
        self::assertSame(['resend.save.key_required'], $this->errors(new FakeSettings(['mailer.provider' => 'dsn']), 'resend', ''));
        $live = new FakeSettings(['mailer.provider' => 'resend'], ['mailer.resend_api_key' => 're_REDACTED-STORED']);
        self::assertSame(['resend.save.key_required'], $this->errors($live, 'resend', '', clear: true));
        self::assertSame([], $this->checked);
    }

    public function testTheChecksAreRateLimited(): void
    {
        $guard = new TestMailGuard(new RateLimiterFactory(['id' => 'test', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour'], new InMemoryStorage()));
        $user  = new TokenStorage();
        $user->setToken(new UsernamePasswordToken(new InMemoryUser('ops', null), 'main'));
        $settings = new FakeSettings(['mailer.provider' => 'dsn'], ['mailer.resend_api_key' => 're_REDACTED-STORED']);

        self::assertSame([], $this->errors($settings, 'resend', 're_REDACTED-A', guard: $guard, tokens: $user));
        self::assertSame(['resend.save.rate_limited'], $this->errors($settings, 'resend', 're_REDACTED-B', guard: $guard, tokens: $user));

        $requests = new RequestStack();
        $requests->push(Request::create('/', server: ['REMOTE_ADDR' => '203.0.113.7']));
        self::assertSame([], $this->errors($settings, 'resend', 're_REDACTED-C', guard: $guard, requests: $requests), 'anonymous: keyed by address');
        self::assertSame(['re_REDACTED-A', 're_REDACTED-C'], $this->checked);
    }

    public function testOutsideAFormNothingIsChecked(): void
    {
        $validator = Validation::createValidatorBuilder()->setConstraintValidatorFactory($this->factory(new FakeSettings()))->getValidator();
        self::assertCount(0, $validator->validate(['value' => 're_REDACTED-TYPED', 'clear' => false], new ResendApiKeyAccepted()));
        self::assertSame([], $this->checked);
        self::assertSame(ResendApiKeyAcceptedValidator::class, (new ResendApiKeyAccepted())->validatedBy());

        $this->expectException(UnexpectedTypeException::class);
        $this->validator(new FakeSettings())->validate('x', new MailerDsn());
    }

    /**
     * Submits a settings-like form (`provider` + a SecretType-shaped `resend_api_key`) and returns the message
     * templates of the key errors.
     *
     * @return list<string>
     */
    private function errors(FakeSettings $settings, string $provider, string $key, bool $clear = false, bool $valid = true, ?TestMailGuard $guard = null, ?TokenStorage $tokens = null, ?RequestStack $requests = null): array
    {
        $validator = Validation::createValidatorBuilder()->setConstraintValidatorFactory($this->factory($settings, $valid, $guard, $tokens, $requests))->getValidator();
        $builder   = Forms::createFormFactoryBuilder()->addExtension(new ValidatorExtension($validator))->getFormFactory()->createBuilder(FormType::class);
        $builder->add('provider', TextType::class);
        $builder->add($builder->create('resend_api_key', FormType::class, ['constraints' => [new ResendApiKeyAccepted()], 'error_bubbling' => false])
            ->add('value', TextType::class)
            ->add('clear', CheckboxType::class));
        $form = $builder->getForm();
        $form->submit(['provider' => $provider, 'resend_api_key' => ['value' => $key] + ($clear ? ['clear' => '1'] : [])]);

        $child    = $form->get('resend_api_key');
        $messages = [];
        foreach ($child->getErrors() as $error) {
            $messages[] = $error->getMessageTemplate();
            self::assertSame('[redacted]', $error->getCause()?->getInvalidValue());
        }
        self::assertSame(count($messages) === 0, $form->isValid());

        return $messages;
    }

    private function factory(FakeSettings $settings, bool $valid = true, ?TestMailGuard $guard = null, ?TokenStorage $tokens = null, ?RequestStack $requests = null): ConstraintValidatorFactory
    {
        return new ConstraintValidatorFactory([ResendApiKeyAcceptedValidator::class => $this->validator($settings, $valid, $guard, $tokens, $requests)]);
    }

    private function validator(FakeSettings $settings, bool $valid = true, ?TestMailGuard $guard = null, ?TokenStorage $tokens = null, ?RequestStack $requests = null): ResendApiKeyAcceptedValidator
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($valid): JsonMockResponse {
            $header          = $options['normalized_headers']['authorization'][0] ?? '';
            $this->checked[] = substr((string) $header, strlen('Authorization: Bearer '));

            return $valid
                ? new JsonMockResponse(['data' => []])
                : new JsonMockResponse(['name' => 'validation_error', 'message' => 'API key is invalid'], ['http_code' => 400]);
        });

        return new ResendApiKeyAcceptedValidator(new ResendApiChecker($client), new MailSettings($settings, true), $guard, $tokens, $requests);
    }
}
