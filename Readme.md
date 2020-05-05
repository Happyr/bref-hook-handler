# Bref Hook Handler

[![Latest Version](https://img.shields.io/github/release/Happyr/bref-hook-handler.svg?style=flat-square)](https://github.com/Happyr/bref-hook-handler/releases)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
[![Total Downloads](https://img.shields.io/packagist/dt/happyr/bref-hook-handler.svg?style=flat-square)](https://packagist.org/packages/happyr/bref-hook-handler)

This small library helps you to make sure the new version of your lambda application
is actually working before you directing traffic it. It makes it simple to run a
"PreTrafficHook".

## Install

```
composer require happyr/bref-hook-handler
```

We also need `serverless-plugin-canary-deployments` from
[davidgf](https://github.com/davidgf/serverless-plugin-canary-deployments):

```
npm i --save-dev serverless-plugin-canary-deployments
```

## Configure

The idea is to create a new lambda function that can verify that everything is
working. When we are sure all things are green, we will signal CodeDeploy to allow
real traffic.

### Example serverless.yml

```yaml
service: canary-example
frameworkVersion: ">=1.69.0 <2.0.0"

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
    timeout: 10
    layers:
      - ${bref:layer.php-74}
```

### Example prehook.php

The prehook script is where you start your application kernel, test writing to
the database, dispatch a message on the queue etc etc. If you use the `HookHandler`
it will automatically communicate back to CodeDeploy.

One can of course add as much or little logic as one need.

```php
<?php

require dirname(__DIR__).'/vendor/autoload.php';

use Happyr\BrefHookHandler\ApiGatewayFaker;
use Happyr\BrefHookHandler\HookHandler;

return new class($apiGateway) extends HookHandler {

    protected function validateDeployment(): bool
    {
        $apiGateway = new ApiGatewayFaker(\getenv('HOOK_VERIFY_FUNCTION_NAME'));
        $response = $apiGateway->request('GET', '/');
        $response->assertStatusCode(200);
        $response->assertBodyContains('Welcome to our site');

        $kernel = new \App\Kernel('prod', false);
        $kernel->boot();
        $kernel->getContainer()->get(CacheAccessChecker::class)->verify();

        // If no exceptions were thrown and we return true, then we will
        // signal CodeDeploy to allow traffic
        return true;
    }
};
```

### Making HTTP requests

In the example above we are making a HTTP request to our homepage. We cannot use
API Gateway because it does not route traffic to the new Lambda version. So we invoke
the lambda version directly with parameters that look like it comes from ApiGateway.
The `ApiGatewayFaker` helps us with that.

This is the only reason why we need to configure `lambda:InvokeFunction` in the
IAM Role.

### Note

If the prehook.php does not make a request to CodeDeploy then the deployment will
hey stuck at "Checking Stack update progress". This is a good thing. This ensures
that the prehook script always reports "Succeeded".

Check the CloudWatch logs if this happens.

## Cool, lets to canary deployments!

It is tempting to change the `deploymentSettings.type` to something else but "AllAtOnce"
to expose your new version to 10% of the requests first... But it might not be optimal.
Consider reading this article first: https://lumigo.io/blog/canary-deployment-for-aws-lambda/
