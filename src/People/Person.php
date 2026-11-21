<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\People;

/**
 * A person as a document or a filter names it: the author or the assignee of an issue, the owner of a change.
 *
 * It has what the source has of the person. An issue has the number on Forge, a change the number on Gerrit and
 * the email. Changes imported before the number of their owner have only the name and the email.
 * See templates/_macros/user.html.twig for how the pages show a person.
 */
final readonly class Person
{
    public function __construct(
        public string $name,
        public ?int $forgeId = null,
        public ?int $gerritId = null,
        public ?string $email = null,
        public ?string $username = null,
    ) {
    }

    /**
     * A person as an issue has it in "author" or "assigned_to": the number on Forge and the name. Older imports
     * also kept the email. Null without a name.
     */
    public static function fromIssue(mixed $field): ?self
    {
        if (!is_array($field)) {
            return null;
        }
        $name = trim((string) ($field['name'] ?? ''));

        return '' === $name ? null : new self(
            $name,
            forgeId: isset($field['id']) ? (int) $field['id'] : null,
            email: isset($field['email']) ? (string) $field['email'] : null,
        );
    }
}
