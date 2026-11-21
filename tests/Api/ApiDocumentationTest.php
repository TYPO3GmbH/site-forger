<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Api;

use App\Api\ChangeMapper;
use App\Api\IssueMapper;
use Nelmio\ApiDocBundle\ApiDocGenerator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * The documentation at /api/doc describes every endpoint and every field the API returns.
 */
final class ApiDocumentationTest extends KernelTestCase
{
    public function testDescribesEveryEndpoint(): void
    {
        $paths = array_keys(self::document()['paths']);
        /** @var RouterInterface $router */
        $router = self::getContainer()->get('router');

        foreach ($router->getRouteCollection() as $name => $route) {
            if (str_starts_with($name, 'api_v1_')) {
                self::assertContains($route->getPath(), $paths, $name . ' is not documented');
            }
        }
    }

    public function testDescribesEveryFieldOfAnIssue(): void
    {
        $issue = new IssueMapper(ApiFixtures::urls())->detail(
            ApiFixtures::issue(),
            ApiFixtures::changesOfTheIssue(),
            ApiFixtures::subtasks(),
            ApiFixtures::indexedRelatedIssues(),
        );

        self::assertFieldsDescribed('IssueDetail', $issue);
    }

    public function testDescribesEveryFieldOfAChange(): void
    {
        $change = new ChangeMapper(ApiFixtures::urls())->detail(
            ApiFixtures::change(),
            ApiFixtures::issuesOfTheChange(),
        );

        self::assertFieldsDescribed('ChangeDetail', $change);
    }

    public function testEveryFieldHasADescription(): void
    {
        foreach (self::document()['components']['schemas'] as $schema => $definition) {
            foreach ($definition['properties'] ?? [] as $field => $property) {
                self::assertNotEmpty($property['description'] ?? '', $schema . '.' . $field . ' has no description');
            }
        }
    }

    public function testEveryFieldIsRequired(): void
    {
        // Every object has all of its fields, a field without a value is null
        foreach (self::document()['components']['schemas'] as $schema => $definition) {
            self::assertSame(
                array_keys($definition['properties'] ?? []),
                $definition['required'] ?? [],
                $schema . ' does not require all of its fields',
            );
        }
    }

    public function testEveryEnumIsAListOfValues(): void
    {
        $document = self::document();
        array_walk_recursive($document, static function (mixed $value, int|string $key): void {
            if ('enum' === $key) {
                self::fail('An enum is a list of values, not ' . var_export($value, true));
            }
        });
        $state = $document['components']['schemas']['IssueChange']['properties']['state'];
        self::assertSame(['ready', 'wip', 'merged', 'abandoned'], $state['enum']);
    }

    public function testEveryEndpointHasItsOwnOperationId(): void
    {
        $operationIds = [];
        foreach (self::document()['paths'] as $operations) {
            foreach ($operations as $operation) {
                self::assertMatchesRegularExpression('/^[a-z][A-Za-z]+$/', $operation['operationId'] ?? '');
                $operationIds[] = $operation['operationId'];
            }
        }

        self::assertSame($operationIds, array_unique($operationIds));
    }

    public function testNeedsNoLogin(): void
    {
        self::assertSame([], self::document()['security']);
    }

    /**
     * The fields of the object as JSON, those of its objects and lists of objects, too, in the schema of the document.
     */
    private static function assertFieldsDescribed(string $schema, object $model): void
    {
        $data = (array) json_decode((string) json_encode($model), true);
        $schemas = self::document()['components']['schemas'];
        $properties = $schemas[$schema]['properties'];

        self::assertSame([], array_values(array_diff(array_keys($properties), array_keys($data))), $schema);
        foreach ($data as $field => $value) {
            self::assertArrayHasKey($field, $properties, $schema . '.' . $field . ' is not documented');
            $reference = self::reference($properties[$field]);
            if (null !== $reference && is_array($value)) {
                $item = array_is_list($value) ? ($value[0] ?? []) : $value;
                self::assertSame(
                    array_keys($schemas[$reference]['properties']),
                    array_keys($item),
                    $schema . '.' . $field,
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $property
     */
    private static function reference(array $property): ?string
    {
        $ref = $property['$ref'] ?? $property['items']['$ref'] ?? $property['oneOf'][0]['$ref']
            ?? $property['allOf'][0]['$ref'] ?? null;

        return null === $ref ? null : basename((string) $ref);
    }

    /**
     * @return array<string, mixed>
     */
    private static function document(): array
    {
        self::bootKernel();
        /** @var ApiDocGenerator $generator */
        $generator = self::getContainer()->get('nelmio_api_doc.generator.default');

        return (array) json_decode($generator->generate()->toJson(), true);
    }
}
