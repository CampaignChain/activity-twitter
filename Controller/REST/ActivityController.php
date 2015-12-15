<?php
/*
 * This file is part of the CampaignChain package.
 *
 * (c) CampaignChain Inc. <info@campaignchain.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CampaignChain\Activity\TwitterBundle\Controller\REST;

use CampaignChain\CoreBundle\Controller\REST\BaseController;
use CampaignChain\CoreBundle\Entity\Activity;
use CampaignChain\CoreBundle\Entity\Module;
use FOS\RestBundle\Controller\Annotations as REST;
use JMS\Serializer\SerializerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Nelmio\ApiDocBundle\Annotation\ApiDoc;
use FOS\RestBundle\Request\ParamFetcher;
use Symfony\Component\HttpFoundation\Response;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;

class ActivityController extends BaseController
{
    const CONTROLLER_SERVICE = 'campaignchain.activity.controller.twitter.update_status';

    /**
     * Schedule a Twitter status
     *
     * Example Request
     * ===============
     *
     *      POST /api/v1/p/campaignchain/activity-twitter/statuses/schedule
     *
     * Example Input
     * =============
     *
    {
        "activity":{
            "name":"My Tweet",
            "location":100,
            "campaign":1,
            "campaignchain-twitter-update-status":{
                "message":"Some test status message"
            },
            "campaignchain_hook_campaignchain_due":{
                "date":"2015-12-20T12:00:00+0000"
            },
            "campaignchain_hook_campaignchain_assignee":{
                "user":1
            }
        }
    }
     *
     * Example Response
     * ================
     *
    {
        "response": [
            {
                "id": 116,
                "equalsOperation": true,
                "name": "My Tweet",
                "startDate": "2015-12-20T12:00:00+0000",
                "status": "open",
                "createdDate": "2015-12-14T21:59:04+0000"
            }
        ]
    }
     *
     * @ApiDoc(
     *  section="Packages: Twitter"
     * )
     *
     * @REST\Post("/statuses/schedule")
     * @ParamConverter("activity", converter="fos_rest.request_body")
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function scheduleStatusesAction(Request $request, Activity $activity)
    {
        try {
            $activityModuleService = $this->get(self::CONTROLLER_SERVICE);

            $form = $this->createForm(
                $activityModuleService->getActivityFormType('rest'),
                $activity
            );

            $form->handleRequest($request);

            if ($form->isValid()) {
                $activity = $activityModuleService->createActivity($activity, $form);

                $response = $this->forward(
                        'CampaignChainCoreBundle:REST/Activity:getActivities',
                        array(
                            'id' => $activity->getId()
                        )
                    );
                return $response->setStatusCode(Response::HTTP_CREATED);
            } else {
                return $this->errorResponse(
                    $form
                );
            }
        } catch (\Exception $e) {
            throw new \Exception($e);
            //return $this->errorResponse($e->getMessage(), $e->getCode());
        }
    }
}