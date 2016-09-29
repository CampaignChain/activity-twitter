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
use CampaignChain\CoreBundle\Entity\Activity;
use CampaignChain\CoreBundle\Entity\Campaign;
use CampaignChain\CoreBundle\Exception\ExternalApiException;
use CampaignChain\CoreBundle\Util\ParserUtil;
use CampaignChain\CoreBundle\Util\SchedulerUtil;
use CampaignChain\CoreBundle\Validator\AbstractActivityValidator;
use CampaignChain\Location\TwitterBundle\Entity\TwitterUser;
use CampaignChain\Operation\TwitterBundle\Entity\Status;
use Doctrine\ORM\EntityManager;
use Symfony\Bundle\FrameworkBundle\Routing\Router;

class UpdateStatus extends AbstractActivityValidator
{
    protected $em;
    protected $restClient;
    protected $maxDuplicateInterval;
    protected $schedulerUtil;
    protected $router;

    public function __construct(
        EntityManager $em,
        TwitterClient $restClient,
        $maxDuplicateInterval,
        SchedulerUtil $schedulerUtil,
        Router $router
    )
    {
        $this->em = $em;
        $this->restClient = $restClient;
        $this->maxDuplicateInterval = $maxDuplicateInterval;
        $this->schedulerUtil = $schedulerUtil;
        $this->router = $router;
    }

    /**
     * Should the content be checked whether it can be executed?
     *
     * @param $content
     * @param \DateTime $startDate
     * @return bool
     */
    public function mustValidate($content, \DateTime $startDate)
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
        if($this->mustValidate($content, $startDate)){
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
                                    . '</a>. '
                                    . 'Either change the message or leave at least '
                                    . $this->maxDuplicateInterval.' between yours and the other post.'
                            );
                        }
                    }

                    // No exact match found.
                    return array(
                        'status' => true,
                    );
                }
            }  else {
                // Check if post with same content is scheduled for same Location.
                /** @var Activity $newActivity */
                $newActivity = clone $content->getOperation()->getActivity();
                $newActivity->setStartDate($startDate);

                $closestActivities = array();

                $closestActivities[] = $this->em->getRepository('CampaignChainCoreBundle:Activity')
                    ->getClosestScheduledActivity($newActivity, '-'.$this->maxDuplicateInterval);
                $closestActivities[] = $this->em->getRepository('CampaignChainCoreBundle:Activity')
                    ->getClosestScheduledActivity($newActivity, '+'.$this->maxDuplicateInterval);

                foreach($closestActivities as $closestActivity) {
                    if ($closestActivity) {
                        $isUniqueContent = $this->isUniqueContent($closestActivity, $content);
                        if ($isUniqueContent['status'] == false) {
                            return $isUniqueContent;
                        }
                    }
                }
            }
        }

        return array(
            'status' => true,
        );
    }

    /**
     * Compares the status message of an already scheduled Activity with the
     * content of a new/edited Activity.
     *
     * @param Activity $existingActivity
     * @param Status $content
     * @return array
     */
    protected function isUniqueContent(Activity $existingActivity, Status $content)
    {
        /** @var Status $existingStatus */
        $existingStatus =
            $this->em->getRepository('CampaignChainOperationTwitterBundle:Status')
                ->findOneByOperation($existingActivity->getOperations()[0]);

        if($existingStatus->getMessage() == $content->getMessage()){
            return array(
                'status' => false,
                'message' =>
                    'Same status message has already been scheduled: '
                    . '<a href="' . $this->router->generate('campaignchain_activity_twitter_update_status_edit', array(
                        'id' => $existingActivity->getId()
                    )) . '">'
                    . $existingActivity->getName()
                    . '</a>. '
                    . 'Either change the message or leave at least '
                    . $this->maxDuplicateInterval.' between yours and the other post.'
            );
        } else {
            return array(
                'status' => true
            );
        }
    }

    /**
     * @param $content
     * @param \DateTime $startDate
     * @return array
     */
    public function isExecutableInCampaign($content, \DateTime $startDate)
    {
        $errMsg = 'The campaign interval must be more than '
            .$this->maxDuplicateInterval.' '
            .'to avoid a '
            .'<a href="https://twittercommunity.com/t/duplicate-tweets/13264">duplicate Tweet error</a>.';

        return $this->isExecutableInCampaignByInterval(
            $content, $startDate, '+'.$this->maxDuplicateInterval, $errMsg
        );
    }
}