<?php

declare(strict_types=1);

namespace App\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormKitAbstractType;
use Override;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Demo form (REQ-TWIG-005 / REQ-DEMO-015, FormKit profile `demo`): put the settings back in the MailKit 1.2 shape (a `resend` section with Resend switched on)
 * to try the 1.3 upgrade path.
 *
 * @extends FormKitAbstractType<array<string, never>>
 */
#[FormKitConfig('demo')]
final class DemoLegacyResendType extends FormKitAbstractType
{
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('simulate', SubmitType::class, ['label' => 'Simulate a MailKit 1.2 install with Resend on', 'attr' => ['class' => 'nowo-ui-btn nowo-ui-btn--outline', 'data-testid' => 'demo-legacy-resend']]);
    }
}
