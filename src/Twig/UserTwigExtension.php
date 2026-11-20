<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Twig;

use App\People\User;
use App\People\UserDirectory;
use App\Search\PeopleFacet;
use Twig\Attribute\AsTwigFunction;
use Twig\Markup;

/**
 * The avatars of people, of Forge and of Gerrit alike, see templates/_macros/user.html.twig for the markup around.
 *
 * A user is what the documents have of a person, with its "name": an issue its "author" or "assigned_to" (with the
 * number on Forge, "id"), a change its owner, see changeOwner() (with the number on Gerrit, "gerrit_id", and the
 * "email"). The avatar is Gravatar's, by the email the index "user" knows of the number, the same for a person on
 * Forge and on Gerrit; without one its placeholder.
 */
final readonly class UserTwigExtension
{
    private const string PLACEHOLDER = 'd41d8cd98f00b204e9800998ecf8427e';

    public function __construct(
        private UserDirectory $users,
    ) {
    }

    /**
     * The owner of a change as a user: the change keeps the name, the email and the number on Gerrit apart.
     *
     * @param array<string, mixed> $change a document of the review index
     *
     * @return array{name: string, email: string|null, gerrit_id: int|null}|null null without an owner
     */
    #[AsTwigFunction(name: 'change_owner')]
    public function changeOwner(array $change): ?array
    {
        $name = trim((string) ($change['owner'] ?? ''));

        return '' === $name ? null : [
            'name' => $name,
            'email' => isset($change['ownerEmail']) ? (string) $change['ownerEmail'] : null,
            'gerrit_id' => isset($change['owner_id']) ? (int) $change['owner_id'] : null,
        ];
    }

    /**
     * The key of a person in the filters of people, for the links to the issues and changes of the person, see
     * UserDirectory::keyOfUser().
     *
     * @param array<string, mixed>|null $user
     */
    #[AsTwigFunction(name: 'user_key')]
    public function userKey(?array $user): string
    {
        return $this->users->keyOfUser($user);
    }

    /**
     * The person of a value of a filter of people, see PeopleFacet, null for a filter of no people.
     *
     * @param array<string, mixed> $bucket
     *
     * @return array<string, mixed>|null
     */
    #[AsTwigFunction(name: 'facet_user')]
    public function facetUser(string $title, array $bucket): ?array
    {
        return PeopleFacet::user($title, $bucket);
    }

    /**
     * The avatar of a user, the placeholder of Gravatar if no email of it is known: every person looks alike, and the
     * names start at the same place. Markup, so it stays an image when a template keeps it in a variable.
     *
     * @param array<string, mixed>|null $user
     */
    #[AsTwigFunction(name: 'user_avatar')]
    public function avatar(?array $user, int $size = 36): Markup
    {
        $url = $this->users->avatarOfUser($user, $size) ?? User::avatarUrl(self::PLACEHOLDER, $size);

        return new Markup(sprintf('<img src="%1$s" class="avatar" height="%2$d" width="%2$d" alt=""/>', htmlspecialchars($url, ENT_QUOTES), $size), 'UTF-8');
    }
}
