<?php

namespace App\Form;

use App\Entity\Comment;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\File;

class CommentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('content', TextareaType::class, [
                'label' => 'Your comment',
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'Write your comment...'
                ]
            ])
            ->add('attachments', FileType::class, [
                'label' => 'Attachments (optional)',
                'mapped' => false,
                'required' => false,
                'multiple' => true,
                'attr' => [
                    'accept' => 'image/*,text/*,application/pdf,.doc,.docx,.xlsx,.zip,.rar,.mp4,.avi,.mov,.webm,.mkv,.php,.js,.ts,.json,.yaml,.yml,.xml,.html,.css,.scss,.twig,.tpl,.sh,.sql,.md,.csv,.env',
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
                                'text/*',
                                'application/zip',
                                'application/x-rar-compressed',
                                'video/mp4',
                                'video/x-msvideo',
                                'video/quicktime',
                                'video/webm',
                                'video/x-matroska',
                            ],
                            'mimeTypesMessage' => 'Please upload a valid file (image, video, PDF, Word, Excel, text or archive)',
                        ])
                    ])
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Comment::class,
        ]);
    }
}
