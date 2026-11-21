<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Setup;

use App\Enum\SystemCheckEnum;

/**
 * Whether a token or secret of the environment is set, a row of the setup on the system page. Never its value.
 */
final readonly class SetupSetting
{
    /**
     * @param string|null $detail what to know beyond the result, like who Forge logs in as; null for nothing
     */
    public function __construct(
        public string $name,
        public string $usedFor,
        public SystemCheckEnum $result,
        public ?string $detail = null,
    ) {
    }
}
