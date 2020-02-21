<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BackPortBlacklistEntry extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('changeId', null, ['required' => true, 'attr' => ['class' => 'form-control']])
            ->add('comment', null, ['attr' => ['class' => 'form-control']])
            ->add(
                'submit',
                SubmitType::class,
                [
                    'label' => 'Save',
                    'attr' => ['class' => 'btn btn-primary pull-right'
                    ],
                ]
            );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => \App\Entity\BackportBlacklistEntry::class,
            'csrf_protection' => getenv('APP_ENV') !== 'test',
            'entity_manager' => null,
        ]);
    }
}
