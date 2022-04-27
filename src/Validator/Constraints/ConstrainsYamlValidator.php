<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Validator\Constraints;

use Elastica\Exception\InvalidException;
use Elastica\Exception\ResponseException;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use T3G\ElasticUtility\ElasticUtility;

class ConstrainsYamlValidator extends ConstraintValidator
{
    /**
     * Checks if the passed value is valid.
     *
     * @param mixed $value The value that should be validated
     * @param Constraint $constraint
     */
    public function validate($value, Constraint $constraint)
    {
        if (!$constraint instanceof ConstrainsYaml) {
            throw new UnexpectedTypeException($constraint, ConstrainsYaml::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        try {
            $fullRequest = [
                'query' => Yaml::parse($value),
                'size' => 10000,
            ];

            $search = ElasticUtility::getIndexByName('issue', 'en')->createSearch($fullRequest);
            $search->search();
        } catch (InvalidException | ResponseException $e) {
            $this->context->buildViolation('Your query did not yield any results. Is it correct?')->addViolation();
        } catch (ParseException $e) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
