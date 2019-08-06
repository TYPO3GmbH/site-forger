<?php
declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Twig;

use App\Utility\AvatarUtility;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * AvatarExtension.
 **/
class AvatarExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('avatar', [$this, 'render'], ['is_safe' => ['html']]),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFunction('avatar', [$this, 'render'], ['is_safe' => ['html']]),
        ];
    }

    /**
     * @param string
     * @param int
     * @return string
     */
    public function render($email, $size = 36): string
    {
        return AvatarUtility::getAvatar((string) $email, (int) $size);
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'avatar';
    }
}
