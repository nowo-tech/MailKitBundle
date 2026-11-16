<?php

declare(strict_types=1);

namespace App\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormKitAbstractType;
use Override;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Demo form (REQ-TWIG-005 / REQ-DEMO-015, FormKit profile `demo`): send one message through MailerInterface.
 *
 * @extends FormKitAbstractType<array{to: string, subject: string}>
 */
#[FormKitConfig('demo')]
final class DemoSendType extends FormKitAbstractType
{
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('to', EmailType::class, ['label' => 'To', 'constraints' => [new NotBlank(), new Email()]])
            ->add('subject', TextType::class, ['label' => 'Subject', 'constraints' => [new NotBlank(), new Length(max: 120)]])
            ->add('send', SubmitType::class, ['label' => 'Send through MailerInterface', 'attr' => ['class' => 'nowo-ui-btn nowo-ui-btn--primary', 'data-testid' => 'demo-send']]);
    }
}
