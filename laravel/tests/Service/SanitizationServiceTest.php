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
            'img with onload' => [
                '<img onload="alert(\'bööse\');" />',
                ''
            ],
            'custom tag with onload' => [
                '<object-foo onload="alert();"></object-foo>',
                ''
            ],
            'custom tag with number as first character' => [
                '<1test onload="alert();"></1test>',
                ''
            ],
            'all common tags' => [
                '<a><abbr><acronym><address><applet><area><b><base><basefont><bdo><bgsound><big><blockquote><blink><body><br><button><caption><center><cite><code><col><colgroup><dd><dfn><del><dir><dl><div><dt><embed><em><fieldset><font><form><frame><frameset><h1><h2><h3><h4><h5><h6><head><hr><html><iframe><img><input><ins><isindex><i><kbd><label><legend><li><link><marquee><menu><meta><noframe><noscript><optgroup><option><ol><p><pre><q><s><samp><script><select><small><span><strike><strong><style><sub><sup><table><td><th><tr><tbody><textarea><tfoot><thead><title><tt><u><ul><var>',
                '<code><h1><h2><h3><h4><h5><h6><li><ol><p><pre><ul>'
            ]
        ];
    }
}
