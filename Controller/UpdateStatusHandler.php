<?php
/*
 * This file is part of the CampaignChain package.
 *
 * (c) CampaignChain Inc. <info@campaignchain.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CampaignChain\Activity\TwitterBundle\Controller;

use CampaignChain\Channel\TwitterBundle\REST\TwitterClient;
use CampaignChain\CoreBundle\Controller\Module\ActivityModuleHandlerInterface;
use CampaignChain\CoreBundle\Entity\Location;
use Doctrine\ORM\EntityManager;
use Symfony\Bundle\TwigBundle\TwigEngine;
use Symfony\Component\HttpFoundation\Session\Session;
use CampaignChain\CoreBundle\Entity\Operation;
use CampaignChain\Operation\TwitterBundle\EntityService\Status;

class UpdateStatusHandler implements ActivityModuleHandlerInterface
{
    const DATETIME_FORMAT_TWITTER = 'F j, Y';

    protected $detailService;
    protected $restClient;
    protected $em;
    protected $session;
    protected $templating;

    public function __construct(
        EntityManager $em,
        Status $detailService,
        TwitterClient $restClient,
        $session,
        TwigEngine $templating
    )
    {
        $this->detailService = $detailService;
        $this->restClient = $restClient;
        $this->em = $em;
        $this->session = $session;
        $this->templating = $templating;
    }

    public function getOperationDetail(Location $location, Operation $operation = null)
    {
        if($operation) {
            return $this->detailService->getStatusByOperation($operation);
        }

        return null;
    }

    public function processOperationDetail(Operation $operation, $data)
    {
        $status = $this->detailService->getStatusByOperation($operation);
        return $status->setMessage($data['message']);
    }

    public function readOperationDetail(Operation $operation)
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
                $this->get('session')->getFlashBag()->add(
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
}