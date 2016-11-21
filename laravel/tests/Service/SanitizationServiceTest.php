<?php
/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace Tests\Service;


use App\Service\SanitizationService;

class SanitizationServiceTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @test
     * @dataProvider whitelistedTagsDataprovider
     *
     * @param $input
     * @param $expectedResult
     */
    public function sanitizeHtmlTest($input, $expectedResult)
    {
        $subjectUnderTest = new SanitizationService();
        self::assertSame($expectedResult, $subjectUnderTest->sanitizeHtml($input));
    }

    public function whitelistedTagsDataprovider()
    {
        return [
            '1 Headline and evil script' => [
                '<h1>huhu</h1><script>BÖÖÖÖÖÖÖSE</script>',
                '<h1>huhu</h1>BÖÖÖÖÖÖÖSE',
            ],
            'Evil Onclick' => [
                '<h1 onclick="doEvilStuff();">huhu</h1><script>BÖÖÖÖÖÖÖSE</script>',
                '<h1>huhu</h1>BÖÖÖÖÖÖÖSE',
            ],
            'Sad attribute' => [
                '<h1 class="dont-kill-me">huhu</h1><script>BÖÖÖÖÖÖÖSE</script>',
                '<h1>huhu</h1>BÖÖÖÖÖÖÖSE',
            ],
        ];
    }
}
