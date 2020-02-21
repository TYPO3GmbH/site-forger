<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
class ConstrainsYaml extends Constraint
{
    public $message = 'The YAML you entered is not valid.';

    public function validatedBy()
    {
        return \get_class($this) . 'Validator';
    }
}
