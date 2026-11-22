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
 * A person of the index "user", see UserRepository: a TYPO3 account with its numbers on Forge and Gerrit.
 * Forge and Gerrit both log in with the TYPO3 account: the Forge login and the Gerrit username are its username.
 *
 * Taken over from the issue index (fromIssue()) a user has no username and was never asked for
 * ("fetchedOn" null): Forge tells the rest, see App\Import\ForgeUserSync. Gerrit tells all of the owner of a
 * change with the change (fromGerrit()). A person of Gerrit only has no Forge number.
 *
 * Logging in here with the TYPO3 account (my.typo3.org) adds its email, its name and the time to the person of the
 * username, see loggedIn() and App\Repository\UserRepository::recordLogin().
 *
 * Each source keeps its own part, and none writes the part of another: Forge its number, name and answer, Gerrit
 * its number and name. The part "account" is the TYPO3 account, the login of Forge, Gerrit and Forger: its
 * username, which joins the parts, and the name and time of the last login here. The document has them apart, see
 * toDocument(). The pages show one name of them, see displayName().
 */
final readonly class User
{
    public const string DATE_FORMAT = 'Y/m/d H:i:s';

    /**
     * The form of the document, in the field "schema". Raise it when the form changes: the job "Update users" writes
     * the documents of an older form again, see App\Import\UserForms.
     */
    public const int SCHEMA = 2;
    private const string GRAVATAR = 'https://www.gravatar.com/avatar/';

    /** The username of the TYPO3 account, in lower case as my.typo3.org has it, null while unknown */
    public ?string $username;

    /**
     * @param string                  $forgeName        the name of the user on Forge, empty without one
     * @param list<string>            $emails           the addresses known of the user, in lower case: the one
     *                                                  Forge shows, the ones the issues had
     * @param bool                    $gone             Forge does not know the user (any more): deleted, locked, or
     *                                                  a group
     * @param \DateTimeImmutable|null $typo3LoginOn     when the person logged in here with the TYPO3 account last
     * @param int|null                $goneStatus       the HTTP status Forge answered for a gone user, null for the
     *                                                  users marked as gone before it was stored
     * @param \DateTimeImmutable|null $gerritLookedUpOn when Gerrit was asked for the account of the username, see
     *                                                  App\Import\GerritAccountLookup
     * @param string|null             $gerritName       the name of the account on Gerrit, which the pages show, see
     *                                                  displayName(). Empty when the activity has none, null while
     *                                                  not looked for, see App\Import\GerritNames
     * @param string|null             $accountName      the name the TYPO3 account sent at the last login here, see
     *                                                  loggedIn(): it wins over the others
     */
    public function __construct(
        public ?int $forgeId,
        public string $forgeName,
        ?string $username = null,
        public ?int $gerritId = null,
        public array $emails = [],
        public ?\DateTimeImmutable $fetchedOn = null,
        public bool $gone = false,
        public ?\DateTimeImmutable $typo3LoginOn = null,
        public ?int $goneStatus = null,
        public ?\DateTimeImmutable $gerritLookedUpOn = null,
        public ?string $gerritName = null,
        public ?string $accountName = null,
    ) {
        $username = mb_strtolower(trim((string) $username));
        $this->username = '' === $username ? null : $username;
    }

    /**
     * A user as Forge answers for "users/<id>.json": the email only if the user shows it.
     *
     * @param array<string, mixed> $user the "user" of the answer
     */
    public static function fromForge(array $user, \DateTimeImmutable $fetchedOn): self
    {
        $email = self::email($user['mail'] ?? null);

        return new self(
            forgeId: (int) $user['id'],
            forgeName: trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '')),
            username: isset($user['login']) ? (string) $user['login'] : null,
            emails: null === $email ? [] : [$email],
            fetchedOn: $fetchedOn,
        );
    }

    /**
     * A user as Gerrit tells an account with DETAILED_ACCOUNTS, the owner of a change: number, name, username and
     * email at once.
     *
     * @param array<string, mixed> $account
     */
    public static function fromGerrit(array $account): ?self
    {
        if (!isset($account['_account_id'])) {
            return null;
        }
        $email = self::email($account['email'] ?? null);

        return new self(
            forgeId: null,
            forgeName: '',
            username: isset($account['username']) ? (string) $account['username'] : null,
            gerritId: (int) $account['_account_id'],
            emails: null === $email ? [] : [$email],
            gerritName: trim((string) ($account['name'] ?? '')),
        );
    }

    /**
     * A user as an issue names it, "author" or "assigned_to": the number, the name, and the email the import kept
     * before the index "user", if any.
     *
     * @param array<string, mixed> $person
     */
    public static function fromIssue(array $person): ?self
    {
        if (!isset($person['id'])) {
            return null;
        }
        $email = self::email($person['email'] ?? null);

        return new self(
            forgeId: (int) $person['id'],
            forgeName: trim((string) ($person['name'] ?? '')),
            emails: null === $email ? [] : [$email],
        );
    }

    /**
     * A document of the index "user", in the current form or in the one before "schema", which had the parts of the
     * sources side by side in one level.
     *
     * @param array<string, mixed> $source
     */
    public static function fromDocument(array $source): self
    {
        if (!isset($source['schema'])) {
            return self::fromFirstForm($source);
        }
        $forge = (array) ($source['forge'] ?? []);
        $gerrit = (array) ($source['gerrit'] ?? []);
        $account = (array) ($source['account'] ?? []);

        return new self(
            forgeId: isset($forge['id']) ? (int) $forge['id'] : null,
            forgeName: (string) ($forge['name'] ?? ''),
            username: isset($account['username']) ? (string) $account['username'] : null,
            gerritId: isset($gerrit['id']) ? (int) $gerrit['id'] : null,
            emails: self::emails((array) ($source['email'] ?? [])),
            fetchedOn: self::date($forge['fetched_on'] ?? null),
            gone: (bool) ($forge['gone'] ?? false),
            typo3LoginOn: self::date($account['login_on'] ?? null),
            goneStatus: isset($forge['gone_status']) ? (int) $forge['gone_status'] : null,
            gerritLookedUpOn: self::date($gerrit['looked_up_on'] ?? null),
            gerritName: isset($gerrit['name']) ? (string) $gerrit['name'] : null,
            accountName: isset($account['name']) ? (string) $account['name'] : null,
        );
    }

    /**
     * The form before "schema": one name, the one of Forge, or of Gerrit for a person of Gerrit only.
     *
     * @param array<string, mixed> $source
     */
    private static function fromFirstForm(array $source): self
    {
        $forgeId = isset($source['forge_id']) ? (int) $source['forge_id'] : null;
        $name = trim((string) ($source['name'] ?? ''));
        $gerritName = isset($source['gerrit_name']) ? (string) $source['gerrit_name'] : null;

        return new self(
            forgeId: $forgeId,
            forgeName: null === $forgeId ? '' : $name,
            username: $source['username'] ?? null,
            gerritId: isset($source['gerrit_id']) ? (int) $source['gerrit_id'] : null,
            emails: self::emails((array) ($source['email'] ?? [])),
            fetchedOn: self::date($source['fetched_on'] ?? null),
            gone: (bool) ($source['gone'] ?? false),
            typo3LoginOn: self::date($source['typo3_login_on'] ?? null),
            goneStatus: isset($source['gone_status']) ? (int) $source['gone_status'] : null,
            gerritLookedUpOn: self::date($source['gerrit_looked_up_on'] ?? null),
            gerritName: null === $forgeId && null === $gerritName && '' !== $name ? $name : $gerritName,
            accountName: isset($source['account_name']) ? (string) $source['account_name'] : null,
        );
    }

    /**
     * The name the pages show. Each source keeps its own name, so none overwrites another: first the one the TYPO3
     * account sent at the last login, then the one of Gerrit, where most of the work is, then the one of Forge. A
     * profile on Forge may have a short form, like "Ge Rie" for "Georg Ringer".
     */
    public function displayName(): string
    {
        return self::filled($this->accountName) ?? self::filled($this->gerritName) ?? $this->forgeName;
    }

    /**
     * Every name the person has, the one shown first: a person is found by each of them.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_values(array_unique(array_filter(
            [$this->displayName(), self::filled($this->gerritName), self::filled($this->forgeName)],
            static fn (?string $name): bool => null !== $name && '' !== $name,
        )));
    }

    /**
     * The same user with the name of the account on Gerrit, empty when the activity has none.
     */
    public function withGerritName(string $name): self
    {
        return new self(
            forgeId: $this->forgeId,
            forgeName: $this->forgeName,
            username: $this->username,
            gerritId: $this->gerritId,
            emails: $this->emails,
            fetchedOn: $this->fetchedOn,
            gone: $this->gone,
            typo3LoginOn: $this->typo3LoginOn,
            goneStatus: $this->goneStatus,
            gerritLookedUpOn: $this->gerritLookedUpOn,
            gerritName: trim($name),
            accountName: $this->accountName,
        );
    }

    /**
     * The same user without the account on Gerrit: it belongs to another username. Gerrit is asked again for the
     * account of the username, see App\Import\GerritAccountLookup.
     */
    public function withoutGerrit(): self
    {
        return new self(
            forgeId: $this->forgeId,
            forgeName: $this->forgeName,
            username: $this->username,
            emails: $this->emails,
            fetchedOn: $this->fetchedOn,
            gone: $this->gone,
            typo3LoginOn: $this->typo3LoginOn,
            goneStatus: $this->goneStatus,
            accountName: $this->accountName,
        );
    }

    private static function filled(?string $name): ?string
    {
        $name = trim((string) $name);

        return '' === $name ? null : $name;
    }

    /**
     * The id of the document: the username, "forge:<forge_id>" or "gerrit:<gerrit_id>" without one.
     */
    public function id(): string
    {
        return $this->username ?? (null === $this->forgeId ? 'gerrit:' . $this->gerritId : 'forge:' . $this->forgeId);
    }

    /**
     * @return list<string> "forge:<number>" and "gerrit:<number>", as far as the person has the numbers
     */
    public function keys(): array
    {
        $keys = [];
        if (null !== $this->forgeId) {
            $keys[] = 'forge:' . $this->forgeId;
        }
        if (null !== $this->gerritId) {
            $keys[] = 'gerrit:' . $this->gerritId;
        }

        return $keys;
    }

    /**
     * The same person known to two documents, one of Forge and one of Gerrit, in one: the part of each source, the
     * addresses of both, the last login of either. Of a part both have, and of the first address, which gives the
     * avatar, this one's first: the one Forge filled.
     */
    public function mergedWith(self $other): self
    {
        return new self(
            forgeId: $this->forgeId ?? $other->forgeId,
            forgeName: '' === $this->forgeName ? $other->forgeName : $this->forgeName,
            username: $this->username ?? $other->username,
            gerritId: $this->gerritId ?? $other->gerritId,
            emails: self::emails([...$this->emails, ...$other->emails]),
            fetchedOn: $this->fetchedOn ?? $other->fetchedOn,
            gone: $this->gone,
            typo3LoginOn: null === $this->typo3LoginOn || $other->typo3LoginOn > $this->typo3LoginOn ? $other->typo3LoginOn : $this->typo3LoginOn,
            goneStatus: $this->goneStatus,
            gerritLookedUpOn: $this->gerritLookedUpOn ?? $other->gerritLookedUpOn,
            gerritName: self::filled($this->gerritName) ?? $other->gerritName ?? $this->gerritName,
            accountName: self::filled($this->accountName) ?? $other->accountName,
        );
    }

    /**
     * The document: each source in its own part, the TYPO3 account with its username among them, the name shown
     * and the addresses beside them. A part without a value is left out.
     *
     * @return array<string, mixed>
     */
    public function toDocument(): array
    {
        return [
            'schema' => self::SCHEMA,
            'name' => $this->displayName(),
            'email' => $this->emails,
            'forge' => self::part([
                'id' => $this->forgeId,
                'name' => '' === $this->forgeName ? null : $this->forgeName,
                'fetched_on' => self::formatted($this->fetchedOn),
                'gone' => $this->gone ? true : null,
                'gone_status' => $this->goneStatus,
            ]),
            'gerrit' => self::part([
                'id' => $this->gerritId,
                'name' => $this->gerritName,
                'looked_up_on' => self::formatted($this->gerritLookedUpOn),
            ]),
            'account' => self::part([
                'username' => $this->username,
                'name' => $this->accountName,
                'login_on' => self::formatted($this->typo3LoginOn),
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>|null the fields with a value, null without one
     */
    private static function part(array $fields): ?array
    {
        $fields = array_filter($fields, static fn (mixed $value): bool => null !== $value);

        return [] === $fields ? null : $fields;
    }

    /**
     * The same user, marked as unknown to Forge: what the index knew of it stays.
     *
     * @param int $status the HTTP status Forge answered
     */
    public function asGone(\DateTimeImmutable $fetchedOn, int $status): self
    {
        return new self(
            forgeId: $this->forgeId,
            forgeName: $this->forgeName,
            username: $this->username,
            gerritId: $this->gerritId,
            emails: $this->emails,
            fetchedOn: $fetchedOn,
            gone: true,
            typo3LoginOn: $this->typo3LoginOn,
            goneStatus: $status,
            gerritLookedUpOn: $this->gerritLookedUpOn,
            gerritName: $this->gerritName,
            accountName: $this->accountName,
        );
    }

    /**
     * The same user after Gerrit was asked for the account of its username: with the number and the email of the
     * account, if Gerrit has one. Gerrit finds an account by more than its username, like an email or a name: an
     * account of another username is not the one of this user.
     */
    public function lookedUpInGerrit(\DateTimeImmutable $on, ?self $account): self
    {
        $own = null !== $account && null !== $this->username && $account->username === $this->username;
        $user = $own ? $this->mergedWith($account) : $this;

        return new self(
            forgeId: $user->forgeId,
            forgeName: $user->forgeName,
            username: $user->username,
            gerritId: $user->gerritId,
            emails: $user->emails,
            fetchedOn: $user->fetchedOn,
            gone: $user->gone,
            typo3LoginOn: $user->typo3LoginOn,
            goneStatus: $user->goneStatus,
            gerritLookedUpOn: $on,
            gerritName: $user->gerritName,
            accountName: $user->accountName,
        );
    }

    /**
     * Whether Forge was asked for the user. A user marked as gone before the status was stored counts as not asked:
     * Forge may have refused it for the token only.
     */
    public function wasAsked(): bool
    {
        return null !== $this->fetchedOn && (!$this->gone || null !== $this->goneStatus);
    }

    /**
     * The same user with what Forge told now, and what only the index knows: the number on Gerrit, the addresses
     * Forge does not show, the last login.
     */
    public function updatedBy(self $fromForge): self
    {
        return new self(
            forgeId: $this->forgeId,
            forgeName: '' === $fromForge->forgeName ? $this->forgeName : $fromForge->forgeName,
            username: $fromForge->username ?? $this->username,
            gerritId: $this->gerritId,
            emails: self::emails([...$fromForge->emails, ...$this->emails]),
            fetchedOn: $fromForge->fetchedOn,
            typo3LoginOn: $this->typo3LoginOn,
            gerritLookedUpOn: $this->gerritLookedUpOn,
            gerritName: $this->gerritName,
            accountName: $this->accountName,
        );
    }

    /**
     * The same person, logged in with the TYPO3 account: under its username, with the email of my.typo3.org added
     * after the others, so the avatar stays the one the pages showed, if there was one. The name the account sent
     * wins over the names of Gerrit and Forge, see displayName().
     */
    public function loggedIn(string $username, string $email, \DateTimeImmutable $on, string $name = ''): self
    {
        return new self(
            forgeId: $this->forgeId,
            forgeName: $this->forgeName,
            username: $username,
            gerritId: $this->gerritId,
            emails: self::emails([...$this->emails, $email]),
            fetchedOn: $this->fetchedOn,
            gone: $this->gone,
            typo3LoginOn: $on,
            goneStatus: $this->goneStatus,
            gerritLookedUpOn: $this->gerritLookedUpOn,
            gerritName: $this->gerritName,
            accountName: self::filled($name) ?? $this->accountName,
        );
    }

    /**
     * The person as the pages show it: the name shown, the numbers, the username, and the first address, which gives
     * the avatar. The address stays in the page as the hash of the avatar only.
     */
    public function asPerson(): Person
    {
        return new Person(
            $this->displayName(),
            forgeId: $this->forgeId,
            gerritId: $this->gerritId,
            email: $this->emails[0] ?? null,
            username: $this->username,
        );
    }

    /**
     * The address of the avatar in a size, with the placeholder of Gravatar for those without one of their own.
     */
    public static function avatarUrl(string $hash, int $size): string
    {
        return self::GRAVATAR . $hash . '?s=' . $size . '&d=mp&r=g';
    }

    /**
     * The hash Gravatar finds the avatar by, of the first address: null without one, the pages show the placeholder.
     */
    public function avatar(): ?string
    {
        return [] === $this->emails ? null : self::avatarHash($this->emails[0]);
    }

    /**
     * The hash Gravatar finds the avatar of an email by.
     */
    public static function avatarHash(string $email): string
    {
        return md5(mb_strtolower(trim($email)));
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        $date = null === $value ? false : \DateTimeImmutable::createFromFormat(self::DATE_FORMAT, (string) $value, new \DateTimeZone('UTC'));

        return false === $date ? null : $date;
    }

    private static function formatted(?\DateTimeImmutable $date): ?string
    {
        return $date?->setTimezone(new \DateTimeZone('UTC'))->format(self::DATE_FORMAT);
    }

    /**
     * The emails without those that are none, each once.
     *
     * @param array<mixed> $emails
     *
     * @return list<string>
     */
    private static function emails(array $emails): array
    {
        return array_values(array_unique(array_filter(array_map(self::email(...), $emails))));
    }

    /**
     * An email in lower case, null if it is none ("Unknown", empty).
     */
    private static function email(mixed $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return str_contains($email, '@') ? $email : null;
    }
}
