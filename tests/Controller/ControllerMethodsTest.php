<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The actions of the controllers need Elasticsearch, so no test runs most of them: a method one of them calls on
 * itself and nobody has any more would only fail on the page.
 */
final class ControllerMethodsTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string}>
     */
    public static function controllers(): iterable
    {
        foreach (glob(__DIR__ . '/../../src/Controller/*Controller.php') ?: [] as $file) {
            $class = 'App\\Controller\\' . basename($file, '.php');
            if (class_exists($class)) {
                yield $class => [$class];
            }
        }
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('controllers')]
    public function testEveryMethodCalledOnItselfExists(string $class): void
    {
        $source = (string) file_get_contents((string) (new \ReflectionClass($class))->getFileName());
        preg_match_all('/\$this->(\w+)\(/', $source, $matches);

        foreach (array_unique($matches[1]) as $method) {
            self::assertTrue(method_exists($class, $method), sprintf('%s calls %s(), which it does not have', $class, $method));
        }
    }
}
