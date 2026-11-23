<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Form;

use App\Entity\SprintBoard;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

/**
 * Who sees a sprint board, in the form that creates and edits it: everybody, or only the people it is shared with,
 * see SprintBoardVoter. The people are the keys of the search of people, separated by commas, see
 * assets/ts/sprint/board-members.ts. SprintController turns them into usernames when it saves the board.
 */
final class BoardAccessFields
{
    public const string MEMBERS = 'members';

    /**
     * @template T
     *
     * @param FormBuilderInterface<T> $builder
     */
    public static function add(FormBuilderInterface $builder): void
    {
        $builder
            ->add('restricted', ChoiceType::class, [
                'label' => 'Who sees the board',
                'expanded' => true,
                'choices' => ['Everybody' => false, 'Only the people below' => true],
            ])
            ->add(self::MEMBERS, TextType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'People',
                'help' => 'Usernames of TYPO3 accounts, separated by commas.',
            ]);
        // The people the board is shared with so far
        $builder->addEventListener(FormEvents::POST_SET_DATA, static function (FormEvent $event): void {
            $board = $event->getData();
            if ($board instanceof SprintBoard) {
                $usernames = $board->getMembers()->map(static fn ($member): string => $member->getUsername());
                $event->getForm()->get(self::MEMBERS)->setData(implode(', ', $usernames->toArray()));
            }
        });
    }
}
