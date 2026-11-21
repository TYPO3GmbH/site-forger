<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Import;

use App\Http\ApiClient;

/**
 * The Forge API the issues are read from: with FORGE_ACCESS_TOKEN, or without a token after usePublic().
 *
 * Temporary: remove this class, api.forge.public and the fallback in ImportForgeCommand::checkAccess() once the
 * role of the token's account may view the issues again. ForgeLogin keeps checking that on the system page.
 *
 * A role of the token's account without the permission "View issues" sees less than the public: Forge answers 403.
 * The import then reads the issues as the public does, see ImportForgeCommand. It loses nothing a page shows: the
 * index holds only public issues, and the private notes and relations must not reach a page. The users have no such
 * way back: without a token Forge hides most of them, see ForgeUserSync.
 */
final class ForgeIssueApi
{
    private bool $public = false;

    public function __construct(
        private readonly ApiClient $forge,
        private readonly ApiClient $forgePublic,
    ) {
    }

    public function client(): ApiClient
    {
        return $this->public ? $this->forgePublic : $this->forge;
    }

    /**
     * Reads the issues without a token from now on, for the rest of the process.
     */
    public function usePublic(): void
    {
        $this->public = true;
    }

    public function isPublic(): bool
    {
        return $this->public;
    }
}
