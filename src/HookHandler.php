<?php

namespace Happyr\BrefHookHandler;

use AsyncAws\CodeDeploy\CodeDeployClient;
use Bref\Context\Context;
use Bref\Event\Handler;

/**
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
abstract class HookHandler implements Handler
{
    /**
     * @var CodeDeployClient|null
     */
    private $codeDeploy;

    public function __construct(?CodeDeployClient $codeDeploy = null)
    {
        $this->codeDeploy = $codeDeploy;
    }

    abstract protected function validateDeployment(): bool;

    public function handle($event, Context $context)
    {
        echo 'DeploymentId: '.$event['DeploymentId']."\n";
        try {
            $valid = $this->validateDeployment();
        } catch (\Throwable $e) {
            $valid = false;

            throw $e;
        } finally {
            $input = [
                'deploymentId' => $event['DeploymentId'],
                'lifecycleEventHookExecutionId' => $event['LifecycleEventHookExecutionId'],
                'status' => $valid ? 'Succeeded' : 'Failed',
            ];

            $this->getCodeDeploy()->putLifecycleEventHookExecutionStatus($input);
        }
    }

    private function getCodeDeploy(): CodeDeployClient
    {
        return $this->codeDeploy ?? $this->codeDeploy = new CodeDeployClient();
    }
}
