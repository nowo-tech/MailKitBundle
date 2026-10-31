<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Validator;

use Nowo\MailKitBundle\Dsn\DsnHostGuard;
use Nowo\MailKitBundle\Dsn\MailerDsnValidator;
use Nowo\MailKitBundle\Validator\DeliverableSender;
use Nowo\MailKitBundle\Validator\DeliverableSenderValidator;
use Nowo\MailKitBundle\Validator\MailerDsn;
use Nowo\MailKitBundle\Validator\MailerDsnConstraintValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ValidatorsTest extends TestCase
{
    public function testConstraintsThroughTheValidator(): void
    {
        $validator = $this->validator();

        self::assertCount(0, $validator->validate(null, [new MailerDsn(), new DeliverableSender()]));
        self::assertCount(0, $validator->validate('', [new MailerDsn(), new DeliverableSender()]));
        self::assertCount(0, $validator->validate('smtp://8.8.8.8:587', new MailerDsn()));
        $violations = $validator->validate('smtp://user:REDACTED-TEST@127.0.0.1', new MailerDsn());
        self::assertCount(1, $violations);
        self::assertSame('dsn.host_not_allowed', $violations[0]?->getMessageTemplate());
        self::assertSame('[redacted]', $violations[0]->getInvalidValue());

        self::assertCount(0, $validator->validate('noreply@example.com', new DeliverableSender()));
        $sender = $validator->validate('root@localhost', new DeliverableSender());
        self::assertCount(1, $sender);
        self::assertSame('sender.not_deliverable', $sender[0]?->getMessageTemplate());
        self::assertSame(MailerDsnConstraintValidator::class, (new MailerDsn())->validatedBy());
    }

    public function testWrongTypes(): void
    {
        $dsn = new MailerDsnConstraintValidator(new MailerDsnValidator());
        try {
            $dsn->validate('x', new NotBlank());
            self::fail('Wrong constraint accepted.');
        } catch (UnexpectedTypeException) {
        }
        $sender = new DeliverableSenderValidator();
        try {
            $sender->validate('x', new NotBlank());
            self::fail('Wrong constraint accepted.');
        } catch (UnexpectedTypeException) {
        }

        // The validator turns UnexpectedValueException into a type violation.
        $validator = $this->validator();
        self::assertCount(1, $validator->validate(42, new MailerDsn()));
        self::assertCount(1, $validator->validate(42, new DeliverableSender()));
    }

    private function validator(): ValidatorInterface
    {
        $factory = new class extends ConstraintValidatorFactory {
            public function __construct()
            {
                parent::__construct([MailerDsnConstraintValidator::class => new MailerDsnConstraintValidator(new MailerDsnValidator(['smtp'], new DsnHostGuard(resolveDns: false)))]);
            }
        };

        return Validation::createValidatorBuilder()->setConstraintValidatorFactory($factory)->getValidator();
    }
}
