<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Stream;

use App\Charts\TimeSeries;
use App\Document\StreamEvent;
use App\Elastic\Bucket;
use App\People\Person;
use App\People\PersonActivity;
use App\Search\PeopleQuery;

/**
 * Reads the answers to the requests of PeopleQuery.
 */
final class StreamPeople
{
    /**
     * The people found, by the latest event of each, in the order of the answer.
     *
     * @param array<string, mixed>|null $aggregation the aggregation PeopleQuery::PEOPLE of PeopleQuery::search()
     *
     * @return list<Person>
     */
    public static function found(?array $aggregation): array
    {
        $people = [];
        foreach (Bucket::listOf($aggregation) as $bucket) {
            $author = StreamEvent::fromDocument($bucket->topHit(PeopleQuery::LATEST))->author;
            if (null !== $author) {
                $people[] = $author;
            }
        }

        return $people;
    }

    /**
     * What each person did, by the key of the person: the answer of PeopleQuery::activity().
     *
     * @param array<string, mixed>|null $aggregation the aggregation PeopleQuery::PEOPLE
     * @param list<string>              $weeks       the keys of the weeks, see TimeSeries::keys()
     *
     * @return array<string, PersonActivity>
     */
    public static function activity(?array $aggregation, array $weeks): array
    {
        $people = [];
        foreach (Bucket::listOf($aggregation) as $bucket) {
            $people[$bucket->key] = new PersonActivity(
                $bucket->countOf(PeopleQuery::REVIEWS),
                $bucket->countOf(PeopleQuery::PATCHES),
                $bucket->countOf(PeopleQuery::ISSUES),
                $bucket->countOf(PeopleQuery::COMMENTS),
                TimeSeries::align(TimeSeries::counts($bucket->aggregation(PeopleQuery::WEEKS)), $weeks),
            );
        }

        return $people;
    }
}
