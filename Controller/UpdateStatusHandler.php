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

namespace CampaignChain\Activity\TwitterBundle\Controller;

use CampaignChain\Channel\TwitterBundle\REST\TwitterClient;
use CampaignChain\CoreBundle\Controller\Module\AbstractActivityHandler;
use CampaignChain\CoreBundle\Entity\Campaign;
use CampaignChain\CoreBundle\Exception\ExternalApiException;
use CampaignChain\CoreBundle\Util\ParserUtil;
use CampaignChain\Location\TwitterBundle\Entity\TwitterUser;
use CampaignChain\Operation\TwitterBundle\Job\UpdateStatus;
use Doctrine\ORM\EntityManager;
use Symfony\Bundle\TwigBundle\TwigEngine;
use CampaignChain\CoreBundle\Entity\Operation;
use CampaignChain\Operation\TwitterBundle\EntityService\Status;
use Symfony\Component\Form\Form;
use CampaignChain\CoreBundle\Entity\Location;

class UpdateStatusHandler extends AbstractActivityHandler
{
    const DATETIME_FORMAT_TWITTER = 'F j, Y';

    protected $em;
    protected $detailService;
    protected $restClient;
    protected $job;
    protected $session;
    protected $templating;
    protected $maxDuplicateInterval;

    public function __construct(
        EntityManager $em,
        Status $detailService,
        TwitterClient $restClient,
        UpdateStatus $job,
        $session,
        TwigEngine $templating,
        $maxDuplicateInterval
    )
    {
        $this->em = $em;
        $this->detailService = $detailService;
        $this->restClient = $restClient;
        $this->job = $job;
        $this->session = $session;
        $this->templating = $templating;
        $this->maxDuplicateInterval = $maxDuplicateInterval;
    }

    public function getContent(Location $location, Operation $operation = null)
    {
        if($operation) {
            return $this->detailService->getStatusByOperation($operation);
        }

        return null;
    }

    public function processContent(Operation $operation, $data)
    {
        try {
            if(is_array($data)) {
                // If the status has already been created, we modify its data.
                $status = $this->detailService->getStatusByOperation($operation);
                $status->setMessage($data['message']);
            } else {
                $status = $data;
            }
        } catch (\Exception $e) {
            // Status has not been created yet, so do it from the form data.
            $status = $data;
        }

        return $status;
    }

    public function postPersistNewEvent(Operation $operation, Form $form, $content = null)
    {
        // Content to be published immediately?
        $this->publishNow($operation, $form);
    }

    public function postPersistEditEvent(Operation $operation, Form $form, $content = null)
    {
        // Content to be published immediately?
        $this->publishNow($operation, $form);
    }

    public function readAction(Operation $operation)
    {
        $status = $this->detailService->getStatusByOperation($operation);

        // Connect to Twitter REST API
        $connection = $this->restClient->connectByActivity($operation->getActivity());

        $isProtected = false;
        $notAccessible = false;

        try {
            $request = $connection->get('statuses/oembed.json?id='.$status->getIdStr());
            $response = $request->send()->json();
            $message = $response['html'];
        } catch (\Exception $e) {
            // Check whether it is a protected tweet.
            if(
                'Forbidden' == $e->getResponse()->getReasonPhrase() &&
                '403'       == $e->getResponse()->getStatusCode()
            ){
                $this->session->getFlashBag()->add(
                    'warning',
                    'This is a protected tweet.'
                );
                $message = $status->getMessage();
            } else {
//                    throw new \Exception(
//                        'TWitter API error: '.
//                        'Reason: '.$e->getResponse()->getReasonPhrase().','.
//                        'Status: '.$e->getResponse()->getStatusCode().','
//                    );
                $this->session->getFlashBag()->add(
                    'warning',
                    'This Tweet might not have been published yet.'
                );
                $message = $status->getMessage();
                $notAccessible = true;
            }
        }

        $locationTwitter = $this->em
            ->getRepository('CampaignChainLocationTwitterBundle:TwitterUser')
            ->findOneByLocation($operation->getActivity()->getLocation());

        $tweetUrl = $status->getUrl();

        return $this->templating->renderResponse(
            'CampaignChainOperationTwitterBundle::read.html.twig',
            array(
                'page_title' => $operation->getActivity()->getName(),
                'tweet_is_protected' => $isProtected,
                'tweet_not_accessible' => $notAccessible,
                'message' => $message,
                'status' => $status,
                'activity' => $operation->getActivity(),
                'activity_date' => $operation->getActivity()->getStartDate()->format(self::DATETIME_FORMAT_TWITTER),
                'location_twitter' => $locationTwitter,
            ));
    }

    private function publishNow(Operation $operation, Form $form)
    {
        if ($form->get('campaignchain_hook_campaignchain_due')->has('execution_choice') && $form->get('campaignchain_hook_campaignchain_due')->get('execution_choice')->getData() == 'now') {
            $this->job->execute($operation->getId());
            $content = $this->detailService->getStatusByOperation($operation);
            $this->session->getFlashBag()->add(
                'success',
                'The Tweet was published. <a href="'.$content->getUrl().'">View it on Twitter</a>.'
            );

            return true;
        }

        return false;
    }

    /**
     * Should the content be checked whether it can be executed?
     *
     * @param $content
     * @return bool
     */
    public function checkExecutable($content)
    {
        return empty(ParserUtil::extractURLsFromText($content->getMessage()));
    }

    /**
     * Search for identical Tweet content in the past 7 days if the content
     * contains no URL.
     *
     * If the message contains at least one URL, then we're fine, because
     * we will create unique shortened URLs for each time the Tweet will be
     * posted.
     *
     * @param object $content
     * @return array
     */
    public function isExecutableInChannel($content)
    {
        /*
         * If message contains no links, find out whether it has been posted before.
         */
        if($this->checkExecutable($content)){
            /** @var TwitterUser $locationTwitter */
            $locationTwitter = $this->em
                ->getRepository('CampaignChainLocationTwitterBundle:TwitterUser')
                ->findOneByLocation($content->getOperation()->getActivity()->getLocation());

            // Connect to Twitter REST API
            $connection = $this->restClient->connectByActivity(
                $content->getOperation()->getActivity()
            );

            $since = new \DateTime();
            $since->modify('-'.$this->maxDuplicateInterval);

            try {
                $request = $connection->get(
                    'search/tweets.json?q='
                    .urlencode(
                        'from:'.$locationTwitter->getUsername().' '
                        .'"'.$content->getMessage().'" '
                        .'since:'.$since->format('Y-m-d')
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
            if(count($matches)){
                foreach ($matches as $match) {
                    if($match['text'] == $content->getMessage()){
                        // Found exact match.
                        return array(
                            'status' => false,
                            'message' =>
                                'Same content has already been posted on Twitter: '
                                .'<a href="https://twitter.com/ordnas/status/'.$match['id_str'].'">'
                                .'https://twitter.com/ordnas/status/'.$match['id_str']
                                .'</a>'
                        );
                    }
                }

                // No exact match found.
                return array(
                    'status' => true,
                );
            }
        }

        return array(
            'status' => true,
        );
    }

    public function isExecutableInCampaign($content)
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

        return parent::isExecutableInCampaign($content);
    }
}