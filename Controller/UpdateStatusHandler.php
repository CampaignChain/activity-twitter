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

use Symfony\Component\HttpFoundation\Session\Session;
use CampaignChain\CoreBundle\Entity\Operation;

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

    public function processOperationDetail(Operation $operation, $data)
    {
        $status = $this->operationService->getStatusByOperation($operation);
        return $status->setMessage($data['message']);
    }
}