<?php

namespace App\Form;

use App\Entity\Priority;
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
            ->add('title')
            ->add('description')
            ->add('priority', EntityType::class, [
                'class' => Priority::class,
                'choice_label' => 'name',
            ])
            ->add('attachments', FileType::class, [
               'label' => 'Pièces jointes',
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
                            'mimeTypesMessage' => 'Veuillez uploader un fichier valide (image, PDF, Word, Excel, texte, archive)',
                        ])
                    ])
               ]
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Ticket::class,
        ]);
    }
}
