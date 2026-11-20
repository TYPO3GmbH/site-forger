<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Form;

use App\Entity\SprintBoardCategory;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<\App\Entity\SprintBoard>
 */
class SprintBoard extends AbstractType
{
    /**
     * @template T
     *
     * @param FormBuilderInterface<T> $builder
     * @param array<mixed>            $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', null, ['required' => true, 'attr' => ['class' => 'form-control']])
            ->add(
                'identifier',
                TextType::class,
                [
                    'required' => true,
                    'label' => 'Identifier (only allowed lower case and -)',
                    'attr' => ['pattern' => '^[a-z0-9]+(?:-[a-z0-9]+)*$'],
                ]
            )
            ->add(
                'category',
                EntityType::class,
                [
                    'class' => SprintBoardCategory::class,
                    'query_builder' => fn (EntityRepository $er) => $er->createQueryBuilder('sb')
                        ->orderBy('sb.name', 'ASC'),
                    'choice_label' => 'name',
                    'required' => true,
                    'label' => 'Category',
                    'attr' => ['class' => 'form-control'],
                ]
            )
            ->add(
                'query',
                TextareaType::class,
                [
                    'attr' => [
                        'class' => 'form-control text-monospace',
                        'rows' => 20,
                        'data-editor' => 'yaml',
                    ],
                ]
            )
            ->add(
                'submit',
                SubmitType::class,
                [
                    'label' => 'Save',
                    'attr' => ['class' => 'btn btn-primary pull-right'],
                ]
            );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => \App\Entity\SprintBoard::class,
            'entity_manager' => null,
        ]);
    }
}
