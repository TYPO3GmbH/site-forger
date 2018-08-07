<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Service;

class SanitizationService
{
    public function sanitizeHtml($content)
    {

        /*
         * Keep only whitelisted tags
         */
        $content = strip_tags($content, '<h1><h2><h3><h4><h5><h6><ul><li><ol><pre><code><p>');

        /*
         * Kill off all attributes because we don't want them
         */
        $content = preg_replace('/<([a-z][a-z0-9]*)[^>]*?(\/?)>/i', '<$1$2>', $content);

        return $content;
    }
}
