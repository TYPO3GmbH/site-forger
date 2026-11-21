<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Api\OpenApi;

use OpenApi\Analysis;
use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Completes the schemas of the objects in App\Api\Model for the OpenAPI document at /api/doc.json.
 *
 * Every object of the API has all of its fields, also those without a value, so each field is required: a client
 * gets "null", not a missing key. A field may name a backed enum as its values, like "enum: ReviewStateEnum::class".
 * NelmioApiDocBundle keeps the class name there, this writes the values of the enum instead.
 */
#[AutoconfigureTag('nelmio_api_doc.swagger.processor')]
final class ModelSchemaProcessor
{
    public function __invoke(Analysis $analysis): void
    {
        $components = $analysis->openapi?->components;
        if (null === $components || Generator::isDefault($components) || Generator::isDefault($components->schemas)) {
            return;
        }
        foreach ($components->schemas as $schema) {
            if (Generator::isDefault($schema->properties)) {
                continue;
            }
            $required = [];
            foreach ($schema->properties as $property) {
                $required[] = $property->property;
                self::expandEnum($property);
            }
            $schema->required = $required;
        }
    }

    private static function expandEnum(OA\Schema $schema): void
    {
        $enum = $schema->enum;
        if (!is_string($enum) || !enum_exists($enum) || !is_subclass_of($enum, \BackedEnum::class)) {
            return;
        }
        $schema->enum = array_map(static fn (\BackedEnum $case): int|string => $case->value, $enum::cases());
    }
}
