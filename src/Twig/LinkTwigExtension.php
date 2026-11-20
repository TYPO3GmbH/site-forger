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
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/**
 * Where an issue or a Gerrit change is linked to: its detail page in Forger, or its page in Forge or Gerrit.
 *
 * Lists, cards and rows lead to the detail page. Forge and Gerrit are linked from the detail page, and where an
 * issue or a change may not be imported, like a missing backport.
 */
final readonly class LinkTwigExtension
{
    public const string FORGE_ISSUE = 'https://forge.typo3.org/issues/%d';
    public const string GERRIT_CHANGE = 'https://review.typo3.org/c/Packages/TYPO3.CMS/+/%d/';

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

    #[AsTwigFunction(name: 'forge_issue_url')]
    public function forgeIssueUrl(int|string $id): string
    {
        return sprintf(self::FORGE_ISSUE, (int) $id);
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

    #[AsTwigFunction(name: 'gerrit_change_url')]
    public function gerritChangeUrl(int|string $number): string
    {
        return sprintf(self::GERRIT_CHANGE, (int) $number);
    }
}
