<?php

namespace Happyr\BrefHookHandler;

use AsyncAws\CodeDeploy\CodeDeployClient;
use Bref\Context\Context;
use Bref\Event\Handler;
use Happyr\BrefHookHandler\Exception\AssertionFailed;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpKernel\KernelInterface;

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

    protected function executeCommand(KernelInterface $kernel, ArrayInput $input): void
    {
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);

        $exit = $application->run($input, new NullOutput());

        if (0 !== $exit) {
            throw AssertionFailed::create('Command "%s" exited with status code: %d', $input->getFirstArgument(), $exit);
        }
    }

    private function getCodeDeploy(): CodeDeployClient
    {
        return $this->codeDeploy ?? $this->codeDeploy = new CodeDeployClient();
    }
}
