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
 * Logging in here with the TYPO3 account (my.typo3.org) adds its email and the time to the person of the username,
 * see loggedIn() and App\Repository\UserRepository::recordLogin().
 */
final readonly class User
{
    public const string DATE_FORMAT = 'Y/m/d H:i:s';
    private const string GRAVATAR = 'https://www.gravatar.com/avatar/';

    /** The username of the TYPO3 account, in lower case as my.typo3.org has it, null while unknown */
    public ?string $username;

    /**
     * @param list<string>            $emails           the addresses known of the user, in lower case: the one
     *                                                  Forge shows, the ones the issues had
     * @param bool                    $gone             Forge does not know the user (any more): deleted, locked, or
     *                                                  a group
     * @param \DateTimeImmutable|null $typo3LoginOn     when the person logged in here with the TYPO3 account last
     * @param int|null                $goneStatus       the HTTP status Forge answered for a gone user, null for the
     *                                                  users marked as gone before it was stored
     * @param \DateTimeImmutable|null $gerritLookedUpOn when Gerrit was asked for the account of the username, see
     *                                                  App\Import\GerritAccountLookup
     */
    public function __construct(
        public ?int $forgeId,
        public string $name,
        ?string $username = null,
        public ?int $gerritId = null,
        public array $emails = [],
        public ?\DateTimeImmutable $fetchedOn = null,
        public bool $gone = false,
        public ?\DateTimeImmutable $typo3LoginOn = null,
        public ?int $goneStatus = null,
        public ?\DateTimeImmutable $gerritLookedUpOn = null,
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
            name: trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '')),
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
            name: (string) ($account['name'] ?? ''),
            username: isset($account['username']) ? (string) $account['username'] : null,
            gerritId: (int) $account['_account_id'],
            emails: null === $email ? [] : [$email],
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
            name: (string) ($person['name'] ?? ''),
            emails: null === $email ? [] : [$email],
        );
    }

    /**
     * @param array<string, mixed> $source a document of the index "user"
     */
    public static function fromDocument(array $source): self
    {
        return new self(
            forgeId: isset($source['forge_id']) ? (int) $source['forge_id'] : null,
            name: (string) ($source['name'] ?? ''),
            username: $source['username'] ?? null,
            gerritId: isset($source['gerrit_id']) ? (int) $source['gerrit_id'] : null,
            emails: self::emails((array) ($source['email'] ?? [])),
            fetchedOn: self::date($source['fetched_on'] ?? null),
            gone: (bool) ($source['gone'] ?? false),
            typo3LoginOn: self::date($source['typo3_login_on'] ?? null),
            goneStatus: isset($source['gone_status']) ? (int) $source['gone_status'] : null,
            gerritLookedUpOn: self::date($source['gerrit_looked_up_on'] ?? null),
        );
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
     * The same person known to two documents, one of Forge and one of Gerrit, in one: the numbers of both, the
     * addresses of both, the last login of either. Of the name, the first address, which gives the avatar, and what
     * Forge was asked, this one's first: the one Forge filled.
     */
    public function mergedWith(self $other): self
    {
        return new self(
            forgeId: $this->forgeId ?? $other->forgeId,
            name: '' === $this->name ? $other->name : $this->name,
            username: $this->username ?? $other->username,
            gerritId: $this->gerritId ?? $other->gerritId,
            emails: self::emails([...$this->emails, ...$other->emails]),
            fetchedOn: $this->fetchedOn ?? $other->fetchedOn,
            gone: $this->gone,
            typo3LoginOn: null === $this->typo3LoginOn || $other->typo3LoginOn > $this->typo3LoginOn ? $other->typo3LoginOn : $this->typo3LoginOn,
            goneStatus: $this->goneStatus,
            gerritLookedUpOn: $this->gerritLookedUpOn ?? $other->gerritLookedUpOn,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toDocument(): array
    {
        return [
            'username' => $this->username,
            'forge_id' => $this->forgeId,
            'gerrit_id' => $this->gerritId,
            'name' => $this->name,
            'email' => $this->emails,
            'fetched_on' => self::formatted($this->fetchedOn),
            'gone' => $this->gone,
            'typo3_login_on' => self::formatted($this->typo3LoginOn),
            'gone_status' => $this->goneStatus,
            'gerrit_looked_up_on' => self::formatted($this->gerritLookedUpOn),
        ];
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
            name: $this->name,
            username: $this->username,
            gerritId: $this->gerritId,
            emails: $this->emails,
            fetchedOn: $fetchedOn,
            gone: true,
            typo3LoginOn: $this->typo3LoginOn,
            goneStatus: $status,
            gerritLookedUpOn: $this->gerritLookedUpOn,
        );
    }

    /**
     * The same user after Gerrit was asked for the account of its username: with the number and the email of the
     * account, if Gerrit has one.
     */
    public function lookedUpInGerrit(\DateTimeImmutable $on, ?self $account): self
    {
        $user = null === $account ? $this : $this->mergedWith($account);

        return new self(
            forgeId: $user->forgeId,
            name: $user->name,
            username: $user->username,
            gerritId: $user->gerritId,
            emails: $user->emails,
            fetchedOn: $user->fetchedOn,
            gone: $user->gone,
            typo3LoginOn: $user->typo3LoginOn,
            goneStatus: $user->goneStatus,
            gerritLookedUpOn: $on,
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
            name: '' === $fromForge->name ? $this->name : $fromForge->name,
            username: $fromForge->username ?? $this->username,
            gerritId: $this->gerritId,
            emails: self::emails([...$fromForge->emails, ...$this->emails]),
            fetchedOn: $fromForge->fetchedOn,
            typo3LoginOn: $this->typo3LoginOn,
            gerritLookedUpOn: $this->gerritLookedUpOn,
        );
    }

    /**
     * The same person, logged in with the TYPO3 account: under its username, with the email of my.typo3.org added
     * after the others, so the avatar stays the one the pages showed, if there was one.
     */
    public function loggedIn(string $username, string $email, \DateTimeImmutable $on): self
    {
        return new self(
            forgeId: $this->forgeId,
            name: $this->name,
            username: $username,
            gerritId: $this->gerritId,
            emails: self::emails([...$this->emails, $email]),
            fetchedOn: $this->fetchedOn,
            gone: $this->gone,
            typo3LoginOn: $on,
            goneStatus: $this->goneStatus,
            gerritLookedUpOn: $this->gerritLookedUpOn,
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
