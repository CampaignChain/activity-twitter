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
use CampaignChain\Operation\TwitterBundle\Job\UpdateStatus;
use Doctrine\ORM\EntityManager;
use Symfony\Bundle\TwigBundle\TwigEngine;
use CampaignChain\CoreBundle\Entity\Operation;
use CampaignChain\Operation\TwitterBundle\EntityService\Status;
use Symfony\Component\Form\Form;
use CampaignChain\CoreBundle\Entity\Location;
use CampaignChain\Operation\TwitterBundle\Validator\UpdateStatus as Validator;

class UpdateStatusHandler extends AbstractActivityHandler
{
    const DATETIME_FORMAT_TWITTER = 'F j, Y';

    protected $em;
    protected $detailService;
    protected $restClient;
    protected $job;
    protected $session;
    protected $templating;
    protected $validator;

    public function __construct(
        EntityManager $em,
        Status $detailService,
        TwitterClient $restClient,
        UpdateStatus $job,
        $session,
        TwigEngine $templating,
        Validator $validator
    )
    {
        $this->em = $em;
        $this->detailService = $detailService;
        $this->restClient = $restClient;
        $this->job = $job;
        $this->session = $session;
        $this->templating = $templating;
        $this->validator = $validator;
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
            // Validate whether we can execute the Activity?
            $isExecutable = $this->validator->isExecutableByChannel(
                $this->detailService->getContent($operation), new \DateTime()
            );
            if(!$isExecutable['status']) {
                throw new \Exception($isExecutable['message']);
            }

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
}