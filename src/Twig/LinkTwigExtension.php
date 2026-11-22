<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Twig;

use App\Document\CommitMessage;
use App\Document\Issue;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/**
 * Where an issue or a Gerrit change is linked to: its detail page in Forger, or its page in Forge or Gerrit. Also the
 * profile of a person on my.typo3.org.
 *
 * Lists, cards and rows lead to the detail page. Forge and Gerrit are linked from the detail page, and where an
 * issue or a change may not be imported, like a missing backport.
 */
final readonly class LinkTwigExtension
{
    public const string FORGE_ISSUE = 'https://forge.typo3.org/issues/%d';
    public const string FORGE_ISSUE_EDIT = 'https://forge.typo3.org/issues/%d/edit';
    public const string FORGE_JOURNAL = 'https://forge.typo3.org/issues/%d#change-%d';
    public const string FORGE_USER = 'https://forge.typo3.org/users/%d';
    public const string TYPO3_PROFILE = 'https://my.typo3.org/u/%s';
    public const string GERRIT_CHANGE = 'https://review.typo3.org/c/Packages/TYPO3.CMS/+/%d/';
    public const string GERRIT_REPOSITORY = 'https://review.typo3.org/Packages/TYPO3.CMS';
    public const string CONTRIBUTION_GUIDE = 'https://docs.typo3.org/m/typo3/guide-contributionworkflow/main/en-us/';

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[AsTwigFunction(name: 'issue_path')]
    public function issuePath(int|string $id): string
    {
        return $this->urlGenerator->generate('issue_show', ['id' => (int) $id]);
    }

    #[AsTwigFunction(name: 'change_path')]
    public function changePath(int|string $number): string
    {
        return $this->urlGenerator->generate('change_show', ['number' => (int) $number]);
    }

    #[AsTwigFunction(name: 'forge_user_url')]
    public function forgeUserUrl(int $id): string
    {
        return sprintf(self::FORGE_USER, $id);
    }

    /**
     * The public profile of a TYPO3 account on my.typo3.org. Its username is the one of Forge and Gerrit.
     */
    #[AsTwigFunction(name: 'typo3_profile_url')]
    public function typo3ProfileUrl(string $username): string
    {
        return sprintf(self::TYPO3_PROFILE, rawurlencode(mb_strtolower($username)));
    }

    #[AsTwigFunction(name: 'forge_issue_url')]
    public function forgeIssueUrl(int|string $id): string
    {
        return sprintf(self::FORGE_ISSUE, (int) $id);
    }

    /**
     * A plain text as HTML, its web addresses linked, like the pipeline the CI names. They open in a new tab.
     */
    #[AsTwigFilter(name: 'autolink', isSafe: ['html'])]
    public function autolink(string $text): string
    {
        $html = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE);

        // A dot or a bracket at the end belongs to the sentence, not to the address
        return (string) preg_replace(
            '~https?://[^\s<>"\']+[^\s<>"\'.,;:!?)\]]~',
            '<a href="$0" target="_blank" rel="noopener">$0</a>',
            $html,
        );
    }

    /**
     * A commit message as HTML, the issues of its footers "Resolves:" and "Related:" linked to their pages.
     * Only imported issues have a page, the others stay text, as do numbers elsewhere in the message.
     *
     * @param list<int|string> $imported the issues of the footers that are in the issue index
     */
    #[AsTwigFilter(name: 'link_footer_issues', isSafe: ['html'])]
    public function linkFooterIssues(string $message, array $imported = []): string
    {
        $imported = array_map(intval(...), $imported);
        $footers = implode('|', [CommitMessage::RESOLVES, CommitMessage::RELATED]);
        $html = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE);

        return (string) preg_replace_callback(
            '/^((?:' . $footers . '):)(.*)$/mi',
            fn (array $line): string => $line[1] . preg_replace_callback(
                '/#?(\d{1,7})\b/',
                fn (array $issue): string => in_array((int) $issue[1], $imported, true)
                    ? sprintf('<a href="%s">%s</a>', $this->issuePath($issue[1]), $issue[0])
                    : $issue[0],
                $line[2]
            ),
            $html
        );
    }

    /**
     * @return array{text: string, footer: string}
     *
     * @see CommitMessage::parts()
     */
    #[AsTwigFunction(name: 'commit_message_parts')]
    public function commitMessageParts(string $body): array
    {
        return CommitMessage::parts($body);
    }

    /**
     * The form of Forge to comment on an issue and to change its fields. Forge asks to log in first.
     */
    #[AsTwigFunction(name: 'forge_issue_edit_url')]
    public function forgeIssueEditUrl(int|string $id): string
    {
        return sprintf(self::FORGE_ISSUE_EDIT, (int) $id);
    }

    /**
     * An entry of the history of an issue on Forge, by the id of its journal.
     */
    #[AsTwigFunction(name: 'forge_journal_url')]
    public function forgeJournalUrl(int|string $issue, int $journal): string
    {
        return sprintf(self::FORGE_JOURNAL, (int) $issue, $journal);
    }

    /**
     * @see CommitMessage::forIssue()
     */
    #[AsTwigFunction(name: 'issue_commit_message')]
    public function issueCommitMessage(Issue $issue): string
    {
        return CommitMessage::forIssue($issue);
    }

    #[AsTwigFunction(name: 'gerrit_change_url')]
    public function gerritChangeUrl(int|string $number): string
    {
        return sprintf(self::GERRIT_CHANGE, (int) $number);
    }

    /**
     * A patch set of a change on Gerrit. With a path the diff of that file in it, "/COMMIT_MSG" is the commit message,
     * the first page of a review.
     */
    #[AsTwigFunction(name: 'gerrit_patch_set_url')]
    public function gerritPatchSetUrl(int|string $number, int $patchSet, string $path = ''): string
    {
        $url = sprintf(self::GERRIT_CHANGE, (int) $number) . $patchSet;
        if ('' === $path) {
            return $url;
        }

        return $url . '/' . implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }

    /**
     * The repository of the core in Gerrit, to fetch a patch set from.
     */
    #[AsTwigFunction(name: 'gerrit_repository_url')]
    public function gerritRepositoryUrl(): string
    {
        return self::GERRIT_REPOSITORY;
    }

    /**
     * A page of the Contribution Guide of the TYPO3 Core, by its path like "Account/Index.html". Without a path
     * its start page.
     */
    #[AsTwigFunction(name: 'contribution_guide_url')]
    public function contributionGuideUrl(string $page = ''): string
    {
        return self::CONTRIBUTION_GUIDE . ltrim($page, '/');
    }
}
