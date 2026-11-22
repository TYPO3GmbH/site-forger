<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\People;

use App\Entity\Interest;
use App\People\InterestInput;
use App\People\InterestSelection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class InterestInputTest extends TestCase
{
    private const array CATEGORIES = ['Documentation', 'Fluid'];

    public function testItTakesPathsByLineOrCommaAndOnlyKnownCategories(): void
    {
        $input = self::input([
            'name' => ' Docs ',
            'paths' => "*.rst\r\n/typo3/sysext/core/Documentation/, *.rst",
            'categories' => ['Documentation', 'Unknown'],
        ]);

        self::assertTrue($input->isValid());
        self::assertSame('Docs', $input->name);
        self::assertSame(['*.rst', 'typo3/sysext/core/Documentation'], $input->paths);
        self::assertSame(['Documentation'], $input->categories);
    }

    public function testItNeedsANameAndAPathOrACategory(): void
    {
        $input = self::input(['name' => '', 'paths' => '', 'categories' => ['Unknown']]);

        self::assertFalse($input->isValid());
        self::assertCount(2, $input->errors);
    }

    public function testItRefusesWhatIsNoPath(): void
    {
        self::assertFalse(self::input(['name' => 'Docs', 'paths' => 'docs and more'])->isValid());
        self::assertFalse(self::input(['name' => 'Docs', 'paths' => '<script>'])->isValid());
    }

    public function testTheSelectionIsAllInterestsOneOfThemOrEverything(): void
    {
        $docs = self::interest(1, ['*.rst'], ['Documentation']);
        $fluid = self::interest(2, ['typo3/sysext/fluid'], []);

        $mine = InterestSelection::of('', [$docs, $fluid]);
        self::assertSame(InterestSelection::MINE, $mine->key);
        self::assertSame(['*.rst', 'typo3/sysext/fluid'], $mine->paths);
        self::assertSame(['Documentation'], $mine->categories);

        $one = InterestSelection::of('2', [$docs, $fluid]);
        self::assertSame('2', $one->key);
        self::assertSame([], $one->categories);

        self::assertTrue(InterestSelection::of('all', [$docs])->isEverything());
        self::assertTrue(InterestSelection::of('', [])->isEverything());
        // Somebody else's interest is none of the user's
        self::assertSame(InterestSelection::MINE, InterestSelection::of('3', [$docs])->key);
    }

    /**
     * @param array<string, mixed> $form
     */
    private static function input(array $form): InterestInput
    {
        return InterestInput::fromForm(Request::create('/', 'POST', $form)->request, self::CATEGORIES);
    }

    /**
     * @param list<string> $paths
     * @param list<string> $categories
     */
    private static function interest(int $id, array $paths, array $categories): Interest
    {
        $interest = new Interest('jdoe', 'Interest ' . $id, $paths, $categories, new \DateTimeImmutable());
        new \ReflectionProperty(Interest::class, 'id')->setValue($interest, $id);

        return $interest;
    }
}
