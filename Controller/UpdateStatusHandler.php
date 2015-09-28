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

use CampaignChain\CoreBundle\Entity\Location;
use CampaignChain\CoreBundle\Entity\Medium;
use CampaignChain\Operation\TwitterBundle\EntityService\Status;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Session\Session;
use CampaignChain\CoreBundle\Entity\Operation;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use CampaignChain\Operation\TwitterBundle\Form\Type\UpdateStatusOperationType;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\GetSetMethodNormalizer;

class UpdateStatusHandler
{
    protected $operationService;

    public function setOperationService($operationService)
    {
        $this->operationService = $operationService;
    }

    public function getOperationDetail(Operation $operation)
    {
        return $this->operationService->getStatusByOperation($operation);
    }
}