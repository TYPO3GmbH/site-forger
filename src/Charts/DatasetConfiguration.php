<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Charts;

final class DatasetConfiguration
{
    private array $data = [];

    public function __construct(
        private readonly string $label,
        private readonly array $options,
        private readonly array $query
    ) {
    }

    public function getQuery(): array
    {
        return $this->query;
    }

    public function setData(array $data): self
    {
        $this->data = $data;

        return $this;
    }

    public function compileDataSet(): array
    {
        return array_merge([
            'label' => $this->label,
            'data' => $this->data,
        ], $this->options);
    }
}
