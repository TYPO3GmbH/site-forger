<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Service;

use App\Client\GeneralClient;
use GuzzleHttp\Exception\BadResponseException;
use Woeler\DiscordPhp\Message\AbstractDiscordMessage;

class DiscordService
{
    /**
     * @var GeneralClient
     */
    protected $client;

    public function __construct(GeneralClient $client)
    {
        $this->client = $client;
    }

    /**
     * @param AbstractDiscordMessage $message
     * @param string                 $url
     */
    public function sendMessage(AbstractDiscordMessage $message, string $url): void
    {
        $sleep = 0;

        while (null !== $sleep) {
            if ($sleep > 0) {
                usleep($sleep * 1000);
            }
            try {
                $response = $this->client->request(
                    'POST',
                    $url,
                    [
                        'headers' => [
                            'Content-Type' => 'application/json',
                        ],
                        'body' => json_encode($message->formatForDiscord()),
                    ]
                );
            } catch (BadResponseException $e) {
                // We don't want to break on 429 too many requests
                if (429 !== $e->getResponse()->getStatusCode()) {
                    throw $e;
                }
            }

            $result = json_decode((string) $response->getBody(), true);
            if (isset($result['retry_after'])) {
                $sleep = $result['retry_after'];
            } else {
                $sleep = null;
            }
        }
    }
}
