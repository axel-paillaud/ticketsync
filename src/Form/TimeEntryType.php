<?php

namespace App\Form;

use App\Entity\TimeEntry;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\LessThanOrEqual;

class TimeEntryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('description', TextareaType::class, [
                'label' => 'Work Description',
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'Describe the work performed...'
                ],
                'constraints' => [
                    new NotBlank([
                        'message' => 'Please provide a description of the work.'
                    ])
                ]
            ])
            ->add('hours', NumberType::class, [
                'label' => 'Hours Worked',
                'attr' => [
                    'step' => '0.25',
                    'min' => '0.25',
                    'max' => '24',
                    'placeholder' => 'e.g., 1.5 (1h30) or 0.75 (45min)'
                ],
                'html5' => true,
                'constraints' => [
                    new NotBlank([
                        'message' => 'Please enter the hours worked.'
                    ]),
                    new GreaterThan([
                        'value' => 0,
                        'message' => 'Hours must be greater than 0.'
                    ]),
                    new LessThanOrEqual([
                        'value' => 24,
                        'message' => 'Hours cannot exceed 24 per entry.'
                    ])
                ]
            ])
            ->add('workDate', DateType::class, [
                'label' => 'Work Date',
                'widget' => 'single_text',
                'html5' => true,
                'attr' => [
                    'max' => (new \DateTime())->format('Y-m-d'),
                ],
                'constraints' => [
                    new NotBlank([
                        'message' => 'Please select the work date.'
                    ])
                ]
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TimeEntry::class,
        ]);
    }
}
