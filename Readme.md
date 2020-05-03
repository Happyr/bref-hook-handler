# Bref Hook Handler

[![Latest Version](https://img.shields.io/github/release/Happyr/bref-hook-handler.svg?style=flat-square)](https://github.com/Happyr/bref-hook-handler/releases)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
[![Total Downloads](https://img.shields.io/packagist/dt/happyr/bref-hook-handler.svg?style=flat-square)](https://packagist.org/packages/happyr/bref-hook-handler)

Do you want to make sure the new version of your lambda application is actually
working before directing traffic? This package is here to help.

## Install

```
composer require happyr/bref-hook-handler
```

We also need `serverless-plugin-canary-deployments` from [davidgf](https://github.com/davidgf/serverless-plugin-canary-deployments)

```
npm i --save-dev serverless-plugin-canary-deployments
```

## Configure

The idea is to create a new lambda function that can verify that everything is
working. When we are sure all things are green, we will signal CodeDeploy to allow
real traffic.

### Modify serverless.yml

```yaml
service: canary-example

# Bref and canary plugins
plugins:
  - ./vendor/bref/bref
  - serverless-plugin-canary-deployments

provider:
  name: aws
  region: eu-north-1
  runtime: provided
  memorySize: 1792
  environment:
    # Optional add the name of our main lambda function as env var
    HOOK_VERIFY_FUNCTION_NAME: ${self:service}-${opt:stage, "dev"}-website

  # Add IAM roles to use CodeDeploy and to be able to invoke our lambda function.
  iamRoleStatements:
    - Effect: Allow
      Action:
        - codedeploy:*
      Resource:
        - "*"
    - Effect: Allow
      Action:
        - lambda:InvokeFunction
      Resource:
        - arn:aws:lambda:${self:provider.region}:99999999:function:${self:service}-${opt:stage, "dev"}-website

functions:
  website:
    handler: public/index.php
    description: ''
    timeout: 8
    layers:
      - ${bref:layer.php-74-fpm}
    events:
      - http: 'ANY /'
      - http: 'ANY /{proxy+}'

    # Add deployment settings. This says: Deploy all at once if "preHook" says it is okey
    deploymentSettings:
      type: AllAtOnce
      alias: Live
      preTrafficHook: preHook

  # Define a PHP script to run to verify deployment.
  preHook:
    handler: prehook.php
    description: 'To verify deployment before allowing traffic'
    layers:
        - ${bref:layer.php-74}
```

### Create prehook.php

The prehook script is where you start your application kernel, test writing to
the database, dispatch a message on the queue etc etc. If you use the `HookHandler`
it will automatically communicate back to CodeDeploy.

One can of course add as much or little logic as one need.

```php
<?php

require dirname(__DIR__).'/vendor/autoload.php';

use Happyr\BredHookHandler\HookHandler;
use Happyr\BredHookHandler\ApiGatewayFaker;

return new class extends HookHandler {
    protected function validateDeployment(): bool
    {
        return
            $this->verifyKernelBookt() &&
            $this->verifyStartpage() &&
            $this->verifyDatabaseConnection();
    }

    private function verifyStartpage(): bool
    {
        $functionName = getenv('HOOK_VERIFY_FUNCTION_NAME');
        $client = new ApiGatewayFaker($functionName);
        $response = $client->request('GET', '/');
        $payload = $response->getPayload();

        if ($payload['statusCode'] !== 200) {
            return false;
        }

        // Check if the startpage contains string
        if (false === strpos($payload['body'], 'Welcome to the startpage')) {
            return false;
        }

        return true;
    }

    private function verifyKernelBookt(): bool
    {
        // This will throw exception if failed.
        $kernel = new \App\Kernel('prod', false);
        $kernel->boot();

        return true;
    }

    private function verifyDatabaseConnection(): bool
    {
        // any custom logic

        return true;
    }
};
```

### Making HTTP requests

In the example above we are making a HTTP request to our startpage. We cannot use
API Gateway because it does not route traffic to the new Lambda version. So we invoke
the lambda version directly with parameter that looks like it comes from ApiGateway.
The `ApiGatewayFaker` helps us with that.

This is the only reason why we need the IAM role to lambda:InvokeFunction.

### Notes

If the prehook.php does not ping CodeDeploy then the deployment will stuck at
"Checking Stack update progress". This is a good thing. This ensures that the prehook
script always reports "Succeeded".

Check the CloudWatch logs if this happens.