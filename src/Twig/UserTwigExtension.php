<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Twig;

use App\Elastic\Bucket;
use App\People\Person;
use App\People\User;
use App\People\UserDirectory;
use App\Search\PeopleFacet;
use Twig\Attribute\AsTwigFunction;
use Twig\Markup;

/**
 * The avatars of people, of Forge and of Gerrit alike, see templates/_macros/user.html.twig for the markup around.
 *
 * The avatar is Gravatar's, by the email the index "user" knows of the number of a Person, the same for a person on
 * Forge and on Gerrit; without one its placeholder.
 */
final readonly class UserTwigExtension
{
    public const string PLACEHOLDER = 'd41d8cd98f00b204e9800998ecf8427e';

    public function __construct(
        private UserDirectory $users,
    ) {
    }

    /**
     * The key of a person in the filters of people, for the links to the issues and changes of the person, see
     * UserDirectory::keyOfPerson().
     */
    #[AsTwigFunction(name: 'user_key')]
    public function userKey(?Person $person): string
    {
        return $this->users->keyOfPerson($person);
    }

    /**
     * The person of a value of a filter of people, see PeopleFacet, null for a filter of no people.
     */
    #[AsTwigFunction(name: 'facet_person')]
    public function facetPerson(string $title, Bucket $bucket): ?Person
    {
        return PeopleFacet::person($title, $bucket);
    }

    /**
     * A user of Forge by number, as the history of an issue names the assignee. Null for one Forger does not know.
     */
    #[AsTwigFunction(name: 'forge_person')]
    public function forgePerson(int|string $id): ?Person
    {
        return $this->users->personOf('forge:' . (int) $id);
    }

    /**
     * The avatar of a person, the placeholder of Gravatar if no email of it is known: every person looks alike, and
     * the names start at the same place. Markup, so it stays an image when a template keeps it in a variable.
     */
    #[AsTwigFunction(name: 'user_avatar')]
    public function avatar(?Person $person, int $size = 36): Markup
    {
        $url = $this->users->avatarOfPerson($person, $size) ?? User::avatarUrl(self::PLACEHOLDER, $size);
        $image = sprintf(
            '<img src="%1$s" class="avatar" height="%2$d" width="%2$d" alt=""/>',
            htmlspecialchars($url, ENT_QUOTES),
            $size,
        );

        return new Markup($image, 'UTF-8');
    }
}
