<?php
/*
 * Copyright 2016 CampaignChain, Inc. <info@campaignchain.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *    http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace CampaignChain\Activity\TwitterBundle\Validator;

use CampaignChain\Channel\TwitterBundle\REST\TwitterClient;
use CampaignChain\CoreBundle\Entity\Campaign;
use CampaignChain\CoreBundle\Exception\ExternalApiException;
use CampaignChain\CoreBundle\Util\ParserUtil;
use CampaignChain\CoreBundle\Util\SchedulerUtil;
use CampaignChain\CoreBundle\Validator\AbstractActivityValidator;
use CampaignChain\Location\TwitterBundle\Entity\TwitterUser;
use Doctrine\ORM\EntityManager;

class UpdateStatus extends AbstractActivityValidator
{
    protected $em;
    protected $restClient;
    protected $maxDuplicateInterval;
    protected $schedulerUtil;

    public function __construct(
        EntityManager $em,
        TwitterClient $restClient,
        $maxDuplicateInterval,
        SchedulerUtil $schedulerUtil
    )
    {
        $this->em = $em;
        $this->restClient = $restClient;
        $this->maxDuplicateInterval = $maxDuplicateInterval;
        $this->schedulerUtil = $schedulerUtil;
    }

    /**
     * Should the content be checked whether it can be executed?
     *
     * @param $content
     * @param \DateTime $startDate
     * @return bool
     */
    public function checkExecutable($content, \DateTime $startDate)
    {
        return empty(ParserUtil::extractURLsFromText($content->getMessage()));
    }

    /**
     * Search for identical Tweet content in the past if the content
     * contains no URL.
     *
     * If the message contains at least one URL, then we're fine, because
     * we will create unique shortened URLs for each time the Tweet will be
     * posted.
     *
     * @param object $content
     * @param \DateTime $startDate
     * @return array
     */
    public function isExecutableInChannel($content, \DateTime $startDate)
    {
        /*
         * If message contains no links, find out whether it has been posted before.
         */
        if($this->checkExecutable($content, $startDate)){
            if($this->schedulerUtil->isDueNow($startDate)) {
                /** @var TwitterUser $locationTwitter */
                $locationTwitter = $this->em
                    ->getRepository('CampaignChainLocationTwitterBundle:TwitterUser')
                    ->findOneByLocation($content->getOperation()->getActivity()->getLocation());

                // Connect to Twitter REST API
                $connection = $this->restClient->connectByActivity(
                    $content->getOperation()->getActivity()
                );

                $since = new \DateTime();
                $since->modify('-' . $this->maxDuplicateInterval);

                try {
                    $request = $connection->get(
                        'search/tweets.json?q='
                        . urlencode(
                            'from:' . $locationTwitter->getUsername() . ' '
                            . '"' . $content->getMessage() . '" '
                            . 'since:' . $since->format('Y-m-d')
                        )
                    );
                    $response = $request->send()->json();
                    $matches = $response['statuses'];
                } catch (\Exception $e) {
                    throw new ExternalApiException(
                        $e->getResponse()->getReasonPhrase(),
                        $e->getResponse()->getStatusCode(),
                        $e
                    );
                }

                /*
                 * Iterate through search matches to see if these are exact matches
                 * with the provided message.
                 */
                if (count($matches)) {
                    foreach ($matches as $match) {
                        if ($match['text'] == $content->getMessage()) {
                            // Found exact match.
                            return array(
                                'status' => false,
                                'message' =>
                                    'Same content has already been posted on Twitter: '
                                    . '<a href="https://twitter.com/ordnas/status/' . $match['id_str'] . '">'
                                    . 'https://twitter.com/ordnas/status/' . $match['id_str']
                                    . '</a>'
                            );
                        }
                    }

                    // No exact match found.
                    return array(
                        'status' => true,
                    );
                }
            }
        }

        return array(
            'status' => true,
        );
    }

    /**
     * @param $content
     * @param \DateTime $startDate
     * @return array
     */
    public function isExecutableInCampaign($content, \DateTime $startDate)
    {
        /** @var Campaign $campaign */
        $campaign = $content->getOperation()->getActivity()->getCampaign();

        if($campaign->getInterval()){
            $campaignIntervalDate = new \DateTime();
            $campaignIntervalDate->modify($campaign->getInterval());
            $maxDuplicateIntervalDate = new \DateTime();
            $maxDuplicateIntervalDate->modify($this->maxDuplicateInterval);

            if($maxDuplicateIntervalDate > $campaignIntervalDate){
                return array(
                    'status' => false,
                    'message' =>
                        'The campaign interval must be more than '
                        .ltrim($this->maxDuplicateInterval, '+').' '
                        .'to avoid a '
                        .'<a href="https://twittercommunity.com/t/duplicate-tweets/13264">duplicate Tweet error</a>.'
                );
            }
        }

        return parent::isExecutableInCampaign($content, null);
    }
}