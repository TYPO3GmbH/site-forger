<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Service;

use Symfony\Component\Asset\Packages;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * The icons a category of the sprint boards may take: those of the group "actions" of the template bundle, the
 * set the function icon() renders, read from the same file as it, see the IconExtension of the bundle.
 */
class IconCatalog
{
    public const string GROUP = 'actions';

    /**
     * @var list<string>|null
     */
    private ?array $identifiers = null;

    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly Packages $packages,
    ) {
    }

    /**
     * @return list<string> the identifiers, like "actions-rocket", sorted by name
     */
    public function identifiers(): array
    {
        if (null !== $this->identifiers) {
            return $this->identifiers;
        }
        $file = basename($this->packages->getUrl('bundles/template/icons/icons.json', 'template'));
        $path = $this->kernel->locateResource('@TemplateBundle/Resources/public/icons/' . $file);
        $json = (string) file_get_contents($path);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $identifiers = [];
        foreach (is_array($data['icons'] ?? null) ? $data['icons'] : [] as $identifier => $icon) {
            if (self::GROUP === ($icon['category'] ?? null)) {
                $identifiers[] = (string) $identifier;
            }
        }
        sort($identifiers);

        return $this->identifiers = $identifiers;
    }
}
