<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThan;

class UserProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, [
                'label' => 'First Name',
                'required' => false,
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Last Name',
                'required' => false,
            ])
            ->add('alertThresholdEnabled', CheckboxType::class, [
                'label' => 'Enable monthly alert',
                'required' => false,
            ])
            ->add('monthlyAlertThreshold', NumberType::class, [
                'label' => 'Monthly threshold (€)',
                'required' => false,
                'attr' => [
                    'min' => 0,
                    'step' => 0.01,
                ],
                'constraints' => [
                    new GreaterThan([
                        'value' => 0,
                        'message' => 'The threshold must be greater than 0',
                    ])
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
