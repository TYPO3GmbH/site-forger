<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Elastic;

use Elastica\Client;
use Elastica\Index;
use Elastica\Mapping;
use Elastica\Reindex;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Yaml\Yaml;

/**
 * Keeps the Elasticsearch indices in line with the Elasticorn configuration in config/Elasticorn.
 *
 * Safe to run any time and as often as needed: deployments and every import run it, so there is
 * no separate setup step. A cheap check (one request per index) decides whether anything has to
 * be done; only then indices are created or rebuilt.
 *
 * Uses the Elasticorn layout: every index type has two physical indices "<type>_<language>_a" and
 * "<type>_<language>_b", the alias "<type>_<language>" (used by the application) and the alias
 * "<type>" point to the active one. Mapping changes are applied by building the inactive index with
 * the new configuration, copying the documents and switching the aliases, so the data stays available.
 *
 * Indices of the types listed under "removed_types" in Elasticorn.yaml are deleted.
 */
final readonly class IndexSetup
{
    public const string CREATED = 'created';
    public const string UNCHANGED = 'unchanged';
    public const string REMAPPED = 'remapped';
    public const string MIGRATED = 'migrated';
    public const string RECONNECTED = 'reconnected';
    public const string REMOVED = 'removed';

    /**
     * Stored in the "_meta" of the mapping, identifies the configuration an index was built with.
     */
    /**
     * The active index was built from the configuration as it is now.
     */
    public const string CONFIGURATION_CURRENT = 'current';
    /**
     * The configuration changed since: app:elasticsearch:setup builds the index again.
     */
    public const string CONFIGURATION_OUTDATED = 'outdated';
    /**
     * The index was built before the configuration was stored with it, app:elasticsearch:setup compares the mapping.
     */
    public const string CONFIGURATION_UNKNOWN = 'unknown';
    /**
     * No index carries the alias.
     */
    public const string CONFIGURATION_MISSING = 'missing';
    private const string CONFIG_HASH = 'forger_config_hash';
    private const string LOCK = 'elasticsearch-index-setup';

    public function __construct(
        private LockFactory $lockFactory,
        #[Autowire('%kernel.project_dir%/config/Elasticorn')]
        private string $configurationPath,
    ) {
    }

    /**
     * Brings all configured indices up to date. Waits while another process does the same.
     *
     * @return array<string, array{action: string, documents: int}> keyed by "<type>_<language>"
     */
    public function ensureAll(): array
    {
        $lock = $this->lockFactory->createLock(self::LOCK, 1800);
        $lock->acquire(true);
        try {
            $results = [];
            foreach ($this->getTypes() as $type) {
                foreach ($this->getLanguages() as $position => $language) {
                    $results[$this->aliasName($type, $language)] = $this->ensure($type, $language, 0 === $position);
                }
            }
            foreach ($this->getRemovedTypes() as $type) {
                foreach ($this->getLanguages() as $language) {
                    $documents = $this->remove($type, $language);
                    if (null !== $documents) {
                        $results[$this->aliasName($type, $language)] = ['action' => self::REMOVED, 'documents' => $documents];
                    }
                }
            }

            return $results;
        } finally {
            $lock->release();
        }
    }

    /**
     * Determines what ensureAll() would do, without changing anything.
     *
     * @return array<string, string> action keyed by "<type>_<language>"
     */
    public function planAll(): array
    {
        $plan = [];
        foreach ($this->getTypes() as $type) {
            foreach ($this->getLanguages() as $language) {
                $plan[$this->aliasName($type, $language)] = $this->plan($type, $language);
            }
        }
        foreach ($this->getRemovedTypes() as $type) {
            foreach ($this->getLanguages() as $language) {
                if ([] !== $this->findIndicesOfRemovedType($type, $language)) {
                    $plan[$this->aliasName($type, $language)] = self::REMOVED;
                }
            }
        }

        return $plan;
    }

    /**
     * Which physical index each configured alias points to, and whether it was built from the configuration
     * as it is now. Only reads, unlike planAll() it never creates an index to compare with.
     *
     * @return array<string, array{index: string|null, configuration: string}> keyed by "<type>_<language>",
     *                                                                         the configuration is one of the CONFIGURATION_* constants
     */
    public function describeAll(): array
    {
        $indices = [];
        foreach ($this->getTypes() as $type) {
            foreach ($this->getLanguages() as $language) {
                $alias = $this->aliasName($type, $language);
                $active = $this->findActiveIndex($alias, $type, $language);
                $configuration = match (true) {
                    null === $active => self::CONFIGURATION_MISSING,
                    $this->isBuiltFromConfiguration($active, $type, $language) => self::CONFIGURATION_CURRENT,
                    null === $this->getConfigurationHash($active) => self::CONFIGURATION_UNKNOWN,
                    default => self::CONFIGURATION_OUTDATED,
                };
                $indices[$alias] = ['index' => $active?->getName(), 'configuration' => $configuration];
            }
        }

        return $indices;
    }

    /**
     * Deletes the indices of a removed type, their aliases go with them.
     *
     * @return int|null the number of deleted documents, null if there was nothing to delete
     */
    private function remove(string $type, string $language): ?int
    {
        $indices = $this->findIndicesOfRemovedType($type, $language);
        if ([] === $indices) {
            return null;
        }
        $documents = 0;
        foreach ($indices as $index) {
            $documents += $index->count();
            $index->delete();
        }

        return $documents;
    }

    /**
     * The physical indices and an index Elasticsearch created on its own under the alias name.
     *
     * @return list<Index>
     */
    private function findIndicesOfRemovedType(string $type, string $language): array
    {
        $names = [
            $this->physicalIndexName($type, $language, 'a'),
            $this->physicalIndexName($type, $language, 'b'),
            $this->aliasName($type, $language),
        ];
        // Only real indices, Index::exists() is also true for an alias
        $existing = $this->client()->getStatus()->getIndexNames();

        return array_values(array_map(
            $this->client()->getIndex(...),
            array_intersect($names, $existing),
        ));
    }

    /**
     * @return array{action: string, documents: int}
     */
    private function ensure(string $type, string $language, bool $primaryLanguage): array
    {
        $alias = $this->aliasName($type, $language);
        $active = $this->findActiveIndex($alias, $type, $language);

        if (null !== $active && $this->isBuiltFromConfiguration($active, $type, $language)) {
            return ['action' => self::UNCHANGED, 'documents' => $active->count()];
        }

        // The physical indices exist but the alias is gone: reconnect the one holding the data instead of replacing it
        $action = self::UNCHANGED;
        if (null === $active && !$this->client()->getIndex($alias)->exists()) {
            $active = $this->findDisconnectedIndex($type, $language);
            if (null !== $active) {
                $this->switchAliases($active, $type, $alias, $primaryLanguage);
                if ($this->isBuiltFromConfiguration($active, $type, $language)) {
                    return ['action' => self::RECONNECTED, 'documents' => $active->count()];
                }
                $action = self::RECONNECTED;
            }
        }

        if (null !== $active) {
            $inactive = $this->otherIndex($active, $type, $language);
            $this->createIndex($inactive, $type, $language);
            if (null === $this->getConfigurationHash($active) && $this->hasConfiguredMapping($active, $inactive)) {
                // Built before configuration hashes were stored (e.g. by Elasticorn) and matching: only record the hash
                $this->storeConfigurationHash($active, $type, $language);

                return ['action' => $action, 'documents' => $active->count()];
            }
            $documents = $this->copyDocuments($active, $inactive);
            $this->switchAliases($inactive, $type, $alias, $primaryLanguage);
            // Empty the previous index, so only the active one holds documents
            $this->createIndex($active, $type, $language);

            return ['action' => self::REMAPPED, 'documents' => $documents];
        }

        $primary = $this->client()->getIndex($this->physicalIndexName($type, $language, 'a'));
        $secondary = $this->client()->getIndex($this->physicalIndexName($type, $language, 'b'));
        $this->createIndex($primary, $type, $language);
        $this->createIndex($secondary, $type, $language);

        // An index with the alias name was created by Elasticsearch itself when documents were written
        // before the indices existed. It has a dynamic mapping, so its documents are moved over.
        $unmanaged = $this->client()->getIndex($alias);
        if ($unmanaged->exists()) {
            $documents = $this->copyDocuments($unmanaged, $primary);
            $unmanaged->delete();
            $this->switchAliases($primary, $type, $alias, $primaryLanguage);

            return ['action' => self::MIGRATED, 'documents' => $documents];
        }

        $this->switchAliases($primary, $type, $alias, $primaryLanguage);

        return ['action' => self::CREATED, 'documents' => 0];
    }

    private function plan(string $type, string $language): string
    {
        $alias = $this->aliasName($type, $language);
        $active = $this->findActiveIndex($alias, $type, $language);
        if (null !== $active) {
            if ($this->isBuiltFromConfiguration($active, $type, $language)) {
                return self::UNCHANGED;
            }
            if (null !== $this->getConfigurationHash($active)) {
                return self::REMAPPED;
            }

            // Compare with a temporary index, as Elasticsearch stores the mapping normalized
            $probe = $this->client()->getIndex($this->physicalIndexName($type, $language, 'probe'));
            $this->createIndex($probe, $type, $language);
            try {
                return $this->hasConfiguredMapping($active, $probe) ? self::UNCHANGED : self::REMAPPED;
            } finally {
                $probe->delete();
            }
        }
        if ($this->client()->getIndex($alias)->exists()) {
            return self::MIGRATED;
        }

        return null !== $this->findDisconnectedIndex($type, $language) ? self::RECONNECTED : self::CREATED;
    }

    /**
     * @return list<string>
     */
    private function getTypes(): array
    {
        $types = [];
        foreach (new \DirectoryIterator($this->configurationPath) as $directory) {
            if ($directory->isDir() && !$directory->isDot() && is_file($directory->getPathname() . '/IndexConfiguration.yaml')) {
                $types[] = strtolower($directory->getFilename());
            }
        }
        sort($types);

        return $types;
    }

    /**
     * @return list<string>
     */
    private function getRemovedTypes(): array
    {
        $removed = Yaml::parseFile($this->configurationPath . '/Elasticorn.yaml')['removed_types'] ?? [];
        $stillConfigured = array_intersect($removed, $this->getTypes());
        if ([] !== $stillConfigured) {
            throw new \LogicException(sprintf('The index types "%s" are configured and listed as removed in Elasticorn.yaml.', implode('", "', $stillConfigured)));
        }

        return $removed;
    }

    /**
     * @return list<string>
     */
    private function getLanguages(): array
    {
        return Yaml::parseFile($this->configurationPath . '/Elasticorn.yaml')['languages'] ?? [];
    }

    private function findActiveIndex(string $alias, string $type, string $language): ?Index
    {
        foreach (['a', 'b'] as $suffix) {
            $index = $this->client()->getIndex($this->physicalIndexName($type, $language, $suffix));
            if ($index->exists() && $index->hasAlias($alias)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * The physical index holding the data, if none of them carries the alias: the one with the most
     * documents, on a tie the newer one (the active index is always the most recently built one).
     */
    private function findDisconnectedIndex(string $type, string $language): ?Index
    {
        $candidates = [];
        foreach (['a', 'b'] as $suffix) {
            $index = $this->client()->getIndex($this->physicalIndexName($type, $language, $suffix));
            if ($index->exists()) {
                $candidates[] = ['index' => $index, 'documents' => $index->count(), 'created' => (int) $index->getSettings()->get('creation_date')];
            }
        }
        usort($candidates, static fn (array $a, array $b): int => [$b['documents'], $b['created']] <=> [$a['documents'], $a['created']]);

        return $candidates[0]['index'] ?? null;
    }

    private function otherIndex(Index $index, string $type, string $language): Index
    {
        $suffix = str_ends_with($index->getName(), '_a') ? 'b' : 'a';

        return $this->client()->getIndex($this->physicalIndexName($type, $language, $suffix));
    }

    private function isBuiltFromConfiguration(Index $index, string $type, string $language): bool
    {
        return $this->getConfigurationHash($index) === $this->configurationHash($type, $language);
    }

    private function getConfigurationHash(Index $index): ?string
    {
        return $index->getMapping()['_meta'][self::CONFIG_HASH] ?? null;
    }

    /**
     * Whether every configured field is mapped in the active index exactly as configured.
     *
     * Fields that are not configured are ignored: documents contain more fields than the
     * configuration, Elasticsearch adds them to the mapping dynamically.
     */
    private function hasConfiguredMapping(Index $active, Index $configured): bool
    {
        $withoutMeta = static function (array $mapping): array {
            unset($mapping['_meta']);

            return $mapping;
        };

        return $this->contains(
            $this->normalize($withoutMeta($active->getMapping())),
            $this->normalize($withoutMeta($configured->getMapping()))
        );
    }

    private function contains(mixed $actual, mixed $expected): bool
    {
        if (!is_array($expected) || array_is_list($expected)) {
            return $actual === $expected;
        }
        if (!is_array($actual)) {
            return false;
        }

        return array_all(
            $expected,
            fn (mixed $value, int|string $key): bool => array_key_exists($key, $actual) && $this->contains($actual[$key], $value),
        );
    }

    private function createIndex(Index $index, string $type, string $language): void
    {
        if ($index->exists()) {
            $index->delete();
        }
        $index->create(['settings' => $this->getSettings($type)]);
        $mapping = new Mapping($this->getMapping($type, $language));
        $mapping->setMeta([self::CONFIG_HASH => $this->configurationHash($type, $language)]);
        $index->setMapping($mapping);
    }

    private function storeConfigurationHash(Index $index, string $type, string $language): void
    {
        $index->setMapping(new Mapping()->setMeta([self::CONFIG_HASH => $this->configurationHash($type, $language)]));
    }

    private function copyDocuments(Index $source, Index $target): int
    {
        $source->refresh();
        $response = new Reindex($source, $target, [Reindex::WAIT_FOR_COMPLETION => true])->run()->getData();
        if ([] !== ($response['failures'] ?? [])) {
            throw new \RuntimeException(sprintf('Copying documents from "%s" to "%s" failed: %s', $source->getName(), $target->getName(), json_encode(array_slice($response['failures'], 0, 3))), 1795072801);
        }
        $target->refresh();

        $expected = $source->count();
        $copied = $target->count();
        if ($copied !== $expected) {
            throw new \RuntimeException(sprintf('Copied %d of %d documents from "%s" to "%s"', $copied, $expected, $source->getName(), $target->getName()), 1795072802);
        }

        return $copied;
    }

    private function switchAliases(Index $index, string $type, string $alias, bool $primaryLanguage): void
    {
        $index->addAlias($alias, true);
        if ($primaryLanguage) {
            // Elasticorn reads the applied mapping via the alias "<type>"
            $index->addAlias($type, true);
        }
    }

    private function configurationHash(string $type, string $language): string
    {
        return hash('xxh128', json_encode($this->normalize([$this->getSettings($type), $this->getMapping($type, $language)]), JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function getSettings(string $type): array
    {
        return $this->readYaml($type, 'IndexConfiguration.yaml');
    }

    /**
     * Mirrors Elasticorn: fields with an analyzer get the analyzer of the language.
     *
     * @return array<string, mixed>
     */
    private function getMapping(string $type, string $language): array
    {
        $mapping = $this->readYaml($type, 'Mapping.yaml');
        foreach ($mapping as $field => $configuration) {
            if (is_array($configuration) && array_key_exists('analyzer', $configuration)) {
                $mapping[$field]['analyzer'] = $language;
            }
        }

        return $mapping;
    }

    /**
     * @return array<string, mixed>
     */
    private function readYaml(string $type, string $file): array
    {
        $path = $this->configurationPath . '/' . $type . '/' . $file;

        return is_file($path) ? (Yaml::parseFile($path) ?? []) : [];
    }

    private function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map($this->normalize(...), $value);
    }

    private function aliasName(string $type, string $language): string
    {
        return $type . '_' . $language;
    }

    private function physicalIndexName(string $type, string $language, string $suffix): string
    {
        return $type . '_' . $language . '_' . $suffix;
    }

    private function client(): Client
    {
        return ElasticUtility::getClient();
    }
}
