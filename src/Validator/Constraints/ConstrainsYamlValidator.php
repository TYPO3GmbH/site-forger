<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Validator\Constraints;

use App\Elastic\Indices;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastica\Exception\InvalidException;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class ConstrainsYamlValidator extends ConstraintValidator
{
    public function __construct(
        private readonly Indices $indices,
    ) {
    }

    /**
     * Checks if the passed value is valid.
     *
     * @param mixed      $value      The value that should be validated
     * @param Constraint $constraint
     */
    public function validate($value, Constraint $constraint): void
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

            $search = $this->indices->issues()->createSearch($fullRequest);
            $search->search();
        } catch (InvalidException|ClientResponseException) {
            $this->context->buildViolation('Your query did not yield any results. Is it correct?')->addViolation();
        } catch (ParseException) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
