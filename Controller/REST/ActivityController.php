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

namespace CampaignChain\Activity\TwitterBundle\Controller\REST;

use CampaignChain\CoreBundle\Controller\REST\BaseModuleController;
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

/**
 * @REST\NamePrefix("campaignchain_activity_twitter_rest_")
 *
 * Class ActivityController
 * @package CampaignChain\Activity\TwitterBundle\Controller\REST
 */
class ActivityController extends BaseModuleController
{
    const CONTROLLER_SERVICE = 'campaignchain.activity.controller.twitter.update_status';

    /**
     * Get a specific Twitter status.
     *
     * Example Request
     * ===============
     *
     *      GET /api/v1/p/campaignchain/activity-twitter/statuses/82
     *
     * Example Response
     * ================
     *
    [
        {
            "twitter_status": {
                "id": 26,
                "message": "Alias quaerat natus iste libero. Et dolor assumenda odio sequi. http://www.schmeler.biz/nostrum-quia-eaque-quo-accusantium-voluptatem.html",
                "createdDate": "2015-12-14T11:02:23+0000"
            }
        },
        {
            "status_location": {
                "id": 63,
                "status": "unpublished",
                "createdDate": "2015-12-14T11:02:23+0000"
            }
        },
        {
            "activity": {
                "id": 82,
                "equalsOperation": true,
                "name": "Announcement 26 on Twitter",
                "startDate": "2012-01-10T05:23:34+0000",
                "status": "paused",
                "createdDate": "2015-12-14T11:02:23+0000"
            }
        },
        {
            "operation": {
                "id": 58,
                "name": "Announcement 26 on Twitter",
                "startDate": "2012-01-10T05:23:34+0000",
                "status": "open",
                "createdDate": "2015-12-14T11:02:23+0000"
            }
        }
    ]
     *
     * @ApiDoc(
     *  section="Packages: Twitter",
     *  requirements={
     *      {
     *          "name"="id",
     *          "requirement"="\d+"
     *      }
     *  }
     * )
     *
     * @param string $id The ID of an Activity, e.g. '42'.
     *
     * @return CampaignChain\CoreBundle\Entity\Bundle
     */
    public function getStatusAction($id)
    {
        return $this->getActivity(
            $id,
            array(
                'twitter_status' => 'CampaignChain\Operation\TwitterBundle\Entity\Status',
            )
        );
    }

    /**
     * Schedule a Twitter status
     *
     * Example Request
     * ===============
     *
     *      POST /api/v1/p/campaignchain/activity-twitter/statuses
     *
     * Example Input
     * =============
     *
    {
        "activity":{
            "name":"My Tweet",
            "location":100,
            "campaign":1,
            "campaignchain_twitter_update_status":{
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
     * See:
     *
     *      GET /api/v1/p/campaignchain/activity-twitter/statuses/{id}
     *
     * @ApiDoc(
     *  section="Packages: Twitter"
     * )
     *
     * @REST\Post("/statuses")
     * @ParamConverter("activity", converter="fos_rest.request_body")
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function postStatusesAction(Request $request, Activity $activity)
    {
        return $this->postActivity(
            'CampaignChainActivityTwitterBundle:REST/Activity:getStatus',
            $request,
            $activity
        );
    }
}