<?php

declare(strict_types=1);

namespace App\Form;

use Nowo\FormKitBundle\Form\FormKitAbstractType;
use Override;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Demo form (REQ-TWIG-005): put the settings back in the MailKit 1.2 shape (a `resend` section with Resend switched on)
 * to try the 1.3 upgrade path.
 *
 * @extends FormKitAbstractType<array<string, never>>
 */
final class DemoLegacyResendType extends FormKitAbstractType
{
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('simulate', SubmitType::class, ['label' => 'Simulate a MailKit 1.2 install with Resend on', 'attr' => ['class' => 'btn btn-outline-warning', 'data-testid' => 'demo-legacy-resend']]);
    }
}
