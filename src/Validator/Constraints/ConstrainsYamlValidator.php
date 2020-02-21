<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Validator\Constraints;

use App\Client\ElasticClient;
use Elastica\Exception\InvalidException;
use Elastica\Exception\ResponseException;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class ConstrainsYamlValidator extends ConstraintValidator
{
    /**
     * @var ElasticClient
     */
    private $client;
    /**
     * @var string
     */
    private $elastic_index_name;

    public function __construct(ElasticClient $client, string $elastic_index_name)
    {
        $this->client = $client;
        $this->elastic_index_name = $elastic_index_name;
    }

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
            $search = $this->client->getIndex($this->elastic_index_name)->createSearch($fullRequest);
            $search->addType('issue');
            $search->search();
        } catch (InvalidException | ResponseException $e) {
            $this->context->buildViolation('Your query did not yield any results. Is it correct?')->addViolation();
        } catch (ParseException $e) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
