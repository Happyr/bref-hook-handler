<?php

namespace Happyr\BredHookHandler;

use AsyncAws\CodeDeploy\CodeDeployClient;
use Bref\Context\Context;
use Bref\Event\Handler;

abstract class HookHandler implements Handler
{
    /**
     * @var CodeDeployClient
     */
    private $codeDeploy;

    public function __construct(?CodeDeployClient $codeDeploy = null)
    {
        $this->codeDeploy = $codeDeploy ?? new CodeDeployClient();
    }

    abstract protected function validateDeployment(): bool;

    public function handle($event, Context $context)
    {
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

            $this->codeDeploy->putLifecycleEventHookExecutionStatus($input);
        }
    }

}
