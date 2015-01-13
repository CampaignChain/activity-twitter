<?php
/*
 * This file is part of the CampaignChain package.
 *
 * (c) Sandro Groganz <sandro@campaignchain.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CampaignChain\Activity\TwitterBundle\Controller;

use CampaignChain\CoreBundle\Entity\Location;
use CampaignChain\CoreBundle\Entity\Medium;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Session\Session;
use CampaignChain\CoreBundle\Entity\Operation;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use CampaignChain\Operation\TwitterBundle\Form\Type\UpdateStatusOperationType;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\GetSetMethodNormalizer;

class UpdateStatusController extends Controller
{
    const BUNDLE_NAME = 'campaignchain/activity-twitter';
    const MODULE_IDENTIFIER = 'campaignchain-twitter-update-status';
    const OPERATION_IDENTIFIER = self::MODULE_IDENTIFIER;

    const DATETIME_FORMAT_TWITTER = 'F j, Y';

    public function newAction(Request $request)
    {
        $wizard = $this->get('campaignchain.core.activity.wizard');
        $campaign = $wizard->getCampaign();
        $activity = $wizard->getActivity();
        $location = $wizard->getLocation();

        $activity->setEqualsOperation(true);

        $activityType = $this->get('campaignchain.core.form.type.activity');
        $activityType->setBundleName(self::BUNDLE_NAME);
        $activityType->setModuleIdentifier(self::MODULE_IDENTIFIER);
        $updateStatusOperation = new UpdateStatusOperationType($this->getDoctrine()->getManager(), $this->get('service_container'));

        $locationService = $this->get('campaignchain.core.location');
        $location = $locationService->getLocation($location->getId());
        $updateStatusOperation->setLocation($location);

        $operationForms[] = array(
            'identifier' => self::OPERATION_IDENTIFIER,
            'form' => $updateStatusOperation,
            'label' => 'Twitter Message',
        );
        $activityType->setOperationForms($operationForms);
        $activityType->setCampaign($campaign);

        $form = $this->createForm($activityType, $activity);

        $form->handleRequest($request);

        if ($form->isValid()) {
            $activity = $wizard->end();

            // Get the operation module.
            $operationService = $this->get('campaignchain.core.operation');
            $operationModule = $operationService->getOperationModule('campaignchain/operation-twitter', 'campaignchain-twitter-update-status');

            // The activity equals the operation. Thus, we create a new operation with the same data.
            $operation = new Operation();
            $operation->setName($activity->getName());
            $operation->setActivity($activity);
            $activity->addOperation($operation);
            $operationModule->addOperation($operation);
            $operation->setOperationModule($operationModule);

            // The Operation creates a Location, i.e. the Twitter post
            // will be accessible through a URL after publishing.
            // Get the location module for the user stream.
            $locationService = $this->get('campaignchain.core.location');
            $locationModule = $locationService->getLocationModule(
                'campaignchain/location-twitter',
                'campaignchain-twitter-status'
            );

            $location = new Location();
            $location->setLocationModule($locationModule);
            $location->setParent($activity->getLocation());
            $location->setName($activity->getName());
            $location->setStatus(Medium::STATUS_UNPUBLISHED);
            $location->setOperation($operation);
            $operation->addLocation($location);

            // Get the status data from request.
            $status = $form->get(self::OPERATION_IDENTIFIER)->getData();
            // Link the status with the operation.
            $status->setOperation($operation);

            $repository = $this->getDoctrine()->getManager();

            // Make sure that data stays intact by using transactions.
            try {
                $repository->getConnection()->beginTransaction();

                $repository->persist($activity);
                $repository->persist($status);

                // We need the activity ID for storing the hooks. Hence we must flush here.
                $repository->flush();

                // TODO: Make sure that data stays intact. If below flushing does not work, we have to roll back above flush.
                $hookService = $this->get('campaignchain.core.hook');
                $activity = $hookService->processHooks(self::BUNDLE_NAME, self::MODULE_IDENTIFIER, $activity, $form, true);

                $repository->flush();

                $repository->getConnection()->commit();
            } catch (\Exception $e) {
                $repository->getConnection()->rollback();
                throw $e;
            }

            $this->get('session')->getFlashBag()->add(
                'success',
                'Your new Twitter activity <a href="'.$this->generateUrl('campaignchain_core_activity_edit', array('id' => $activity->getId())).'">'.$activity->getName().'</a> was created successfully.'
            );

            // Status Update to be sent immediately?
            // TODO: This is an intermediary hardcoded hack and should be instead handled by the scheduler.
            if ($form->get('campaignchain_hook_campaignchain_due')->has('execution_choice') && $form->get('campaignchain_hook_campaignchain_due')->get('execution_choice')->getData() == 'now') {
                $job = $this->get('campaignchain.job.operation.twitter.update_status');
                $job->execute($operation->getId());
                // TODO: Add different flashbag which includes link to posted message on Facebook
            }

            return $this->redirect($this->generateUrl('campaignchain_core_activities'));

            //return $this->redirect($this->generateUrl('task_success'));
        }

        return $this->render(
            'CampaignChainCoreBundle:Operation:new.html.twig',
            array(
                'page_title' => 'New Twitter Status',
                'activity' => $activity,
                'campaign' => $campaign,
                'channel_module' => $wizard->getChannelModule(),
                'channel_module_bundle' => $wizard->getChannelModuleBundle(),
                'location' => $wizard->getLocation(),
                'form' => $form->createView(),
                'form_submit_label' => 'Save',
                'form_cancel_route' => 'campaignchain_core_activities_new'
            ));

    }

    public function editAction(Request $request, $id)
    {
        $activityService = $this->get('campaignchain.core.activity');
        $activity = $activityService->getActivity($id);
        $campaign = $activity->getCampaign();

        // Get the one operation.
        $operation = $activityService->getOperation($id);
        $operationService = $this->get('campaignchain.operation.twitter.status');
        $status = $operationService->getStatusByOperation($operation);

        $activityType = $this->get('campaignchain.core.form.type.activity');
        $activityType->setBundleName(self::BUNDLE_NAME);
        $activityType->setModuleIdentifier(self::MODULE_IDENTIFIER);
        $updateStatusOperation = new UpdateStatusOperationType($this->getDoctrine()->getManager(), $this->get('service_container'));
        $updateStatusOperation->setStatus($status);
        $operationForms[] = array(
            'identifier' => self::OPERATION_IDENTIFIER,
            'form' => $updateStatusOperation,
            'label' => 'Twitter Message',
        );
        $activityType->setOperationForms($operationForms);
        $activityType->setCampaign($campaign);

        $form = $this->createForm($activityType, $activity);

        $form->handleRequest($request);

        if ($form->isValid()) {
            // Get the status data from request.
            $status = $form->get(self::OPERATION_IDENTIFIER)->getData();

            $repository = $this->getDoctrine()->getManager();

            // The activity equals the operation. Thus, we update the operation with the same data.
            $activityService = $this->get('campaignchain.core.activity');
            $operation = $activityService->getOperation($id);
            $operation->setName($activity->getName());
            $repository->persist($operation);

            $repository->persist($status);

            $hookService = $this->get('campaignchain.core.hook');
            $activity = $hookService->processHooks(self::BUNDLE_NAME, self::MODULE_IDENTIFIER, $activity, $form);
            $repository->persist($activity);

            $repository->flush();

//            // Status Update should be sent immediately
//            if ($form->get('actions')->get('send')->isClicked()) {
//                $job = $this->get('campaignchain.job.operation.twitter.update_status');
//                $job->execute($operation);
//
//                // TODO: If this previously was a scheduled activity, then reset the schedule
//            }

            $this->get('session')->getFlashBag()->add(
                'success',
                'Your Twitter activity <a href="'.$this->generateUrl('campaignchain_core_activity_edit', array('id' => $activity->getId())).'">'.$activity->getName().'</a> was edited successfully.'
            );

            if ($form->get('campaignchain_hook_campaignchain_due')->has('execution_choice') && $form->get('campaignchain_hook_campaignchain_due')->get('execution_choice')->getData() == 'now') {
                $job = $this->get('campaignchain.job.operation.twitter.update_status');
                $job->execute($operation->getId());
                // TODO: Add different flashbag which includes link to posted message on Facebook
            }

            return $this->redirect($this->generateUrl('campaignchain_core_activities'));
        }

        return $this->render(
            'CampaignChainCoreBundle:Operation:new.html.twig',
            array(
                'page_title' => 'Edit Twitter Status',
                'activity' => $activity,
                'form' => $form->createView(),
                'form_submit_label' => 'Save',
                'form_cancel_route' => 'campaignchain_core_activities'
            ));
    }

    public function editModalAction(Request $request, $id)
    {
        $activityService = $this->get('campaignchain.core.activity');
        $activity = $activityService->getActivity($id);
        $campaign = $activity->getCampaign();

        // Get the one operation.
        $operation = $activityService->getOperation($id);
        $operationService = $this->get('campaignchain.operation.twitter.status');
        $status = $operationService->getStatusByOperation($operation);

        $activityType = $this->get('campaignchain.core.form.type.activity');
        $activityType->setBundleName(self::BUNDLE_NAME);
        $activityType->setModuleIdentifier(self::MODULE_IDENTIFIER);
        $updateStatusOperation = new UpdateStatusOperationType($this->getDoctrine()->getManager(), $this->get('service_container'));
        $updateStatusOperation->setStatus($status);
        $operationForms[] = array(
            'identifier' => self::OPERATION_IDENTIFIER,
            'form' => $updateStatusOperation,
            'label' => 'Twitter Message',
        );
        $activityType->setOperationForms($operationForms);
        $activityType->setCampaign($campaign);
        $activityType->setView('modal');

        $form = $this->createForm($activityType, $activity);

        $form->handleRequest($request);

        return $this->render(
            'CampaignChainCoreBundle:Base:new_modal.html.twig',
            array(
                'page_title' => 'Edit Twitter Status',
                'form' => $form->createView(),
            ));
    }

    public function editApiAction(Request $request, $id)
    {
        $responseData = array();

        $data = $request->get('campaignchain_core_activity');

        $responseData['data'] = $data;

        $activityService = $this->get('campaignchain.core.activity');
        $activity = $activityService->getActivity($id);
        $activity->setName($data['name']);

        // The activity equals the operation. Thus, we update the operation with the same data.
        $operation = $activityService->getOperation($id);
        $operation->setName($data['name']);

        $operationService = $this->get('campaignchain.operation.twitter.status');
        $status = $operationService->getStatusByOperation($operation);

        $status->setMessage($data[self::OPERATION_IDENTIFIER]['message']);

        $repository = $this->getDoctrine()->getManager();
        $repository->persist($activity);
        $repository->persist($operation);
        $repository->persist($status);

        $hookService = $this->get('campaignchain.core.hook');
        $hookService->processHooks(self::BUNDLE_NAME, self::MODULE_IDENTIFIER, $activity, $data);

        $repository->flush();

//        // Status Update should be sent immediately
//        if (isset($data['actions']['send']) && $data['actions']['send'] == 1) {
//            $job = $this->get('campaignchain.job.operation.twitter.update_status');
//            $job->execute($operation);
//            // TODO: Add different flashbag which includes link to posted message on Facebook
//            // TODO: If this previously was a scheduled activity, then reset the schedule
//        }

        $encoders = array(new JsonEncoder());
        $normalizers = array(new GetSetMethodNormalizer());
        $serializer = new Serializer($normalizers, $encoders);

        $response = new Response($serializer->serialize($responseData, 'json'));
        return $response->setStatusCode(Response::HTTP_OK);
    }

    public function readAction(Request $request, $id)
    {
        $activityService = $this->get('campaignchain.core.activity');
        $activity = $activityService->getActivity($id);
        $campaign = $activity->getCampaign();

        // Get the one operation.
        $operation = $activityService->getOperation($id);
        $operationService = $this->get('campaignchain.operation.twitter.status');
        $status = $operationService->getStatusByOperation($operation);

        // Connect to Twitter REST API
        $client = $this->get('campaignchain.channel.twitter.rest.client');
        $connection = $client->connectByActivity($status->getOperation()->getActivity());

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
                $this->get('session')->getFlashBag()->add(
                    'warning',
                    'This Tweet might not have been published yet.'
                );
                $message = $status->getMessage();
                $notAccessible = true;
            }
        }

        $locationTwitter = $this->getDoctrine()
            ->getRepository('CampaignChainLocationTwitterBundle:TwitterUser')
            ->findOneByLocation($activity->getLocation());

        $tweetUrl = $status->getUrl();

        return $this->render(
            'CampaignChainOperationTwitterBundle::read.html.twig',
            array(
                'page_title' => $activity->getName(),
                'tweet_is_protected' => $isProtected,
                'tweet_not_accessible' => $notAccessible,
                'message' => $message,
                'status' => $status,
                'activity' => $activity,
                'activity_date' => $activity->getStartDate()->format(self::DATETIME_FORMAT_TWITTER),
                'location_twitter' => $locationTwitter,
            ));
    }
}