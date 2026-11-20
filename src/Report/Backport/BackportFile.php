<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Report\Backport;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The backports app:backports found, for the backport view. The file is in var/, which the deployments keep.
 */
final readonly class BackportFile
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/var/backports.json')]
        private string $path,
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>|null by Change-Id, null before the first run
     */
    public function read(): ?array
    {
        if (!is_file($this->path)) {
            return null;
        }

        return (array) json_decode((string) file_get_contents($this->path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, array<string, mixed>> $backports by Change-Id
     */
    public function write(array $backports): void
    {
        file_put_contents($this->path, json_encode($backports, JSON_THROW_ON_ERROR));
    }

    public function path(): string
    {
        return $this->path;
    }
}
