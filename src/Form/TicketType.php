<?php

namespace App\Form;

use App\Entity\Organization;
use App\Entity\Priority;
use App\Entity\Status;
use App\Entity\Ticket;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\File;

class TicketType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', null, [
                'label' => 'Title',
            ])
            ->add('description', null, [
                'label' => 'Description',
            ])
            ->add('status', EntityType::class, [
                'class' => Status::class,
                'choice_label' => 'name',
                'label' => 'Status',
                'disabled' => !$options['is_admin'],
            ])
            ->add('priority', EntityType::class, [
                'class' => Priority::class,
                'choice_label' => 'name',
                'label' => 'Priority',
            ]);

        // Only admins can choose organization when creating/editing tickets
        if ($options['is_admin']) {
            $builder->add('organization', EntityType::class, [
                'class' => Organization::class,
                'choice_label' => 'name',
                'label' => 'Organization',
                'placeholder' => 'Select an organization',
            ]);
        }

        $builder->add('attachments', FileType::class, [
               'label' => 'Attachments',
               'mapped' => false,
               'required' => false,
               'multiple' => true,
               'attr' => [
                    'accept' => 'image/*,.pdf,.doc,.docx,.txt,.xlsx,.odt,.zip,.rar',
               ],
               'constraints' => [
                    new All([
                        new File([
                            'maxSize' => '10M',
                            'mimeTypes' => [
                                'image/*',
                                'application/pdf',
                                'application/msword',
                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                'application/vnd.ms-excel',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.oasis.opendocument.text',
                                'text/plain',
                                'application/zip',
                                'application/x-rar-compressed'
                            ],
                            'mimeTypesMessage' => 'Please upload a valid file (image, PDF, Word, Excel, text, archive)',
                        ])
                    ])
               ]
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Ticket::class,
            'is_admin' => false,
        ]);
    }
}
