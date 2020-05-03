<?php

declare(strict_types=1);

namespace Happyr\BrefHookHandler;

use AsyncAws\Lambda\LambdaClient;
use Bref\Lambda\InvocationFailed;
use Bref\Lambda\InvocationResult;

/**
 * Invoke Lambda in a way it looks like it came from API Gateway.
 *
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class ApiGatewayFaker
{
    /** @var LambdaClient */
    private $lambda;
    private $functionName;

    public function __construct(string $functionName, ?LambdaClient $lambdaClient = null)
    {
        $this->functionName = $functionName;
        $this->lambda = $lambdaClient ?? new LambdaClient();
    }

    /**
     * @throws InvocationFailed
     */
    public function request(string $method, string $url, array $headers = [], string $body = '', array $context = []): InvocationResult
    {
        $urlParts = parse_url($url);
        $schema = $urlParts['scheme'] ?? 'https';
        $defaultHeaders = [
            'Accept' => '*/*',
            'Cache-Control' => 'no-cache',
            'Host' => $urlParts['host'] ?? 'example.org',
            'User-Agent' => 'Lambda/Hook',
            'X-Forwarded-For' => '1.1.1.1',
            'X-Forwarded-Port' => 'https' === $schema ? '443' : '80',
            'X-Forwarded-Proto' => $schema,
        ];
        $headers = array_merge($defaultHeaders, $headers);

        $payload = [
            'path' => $urlParts['path'] ?? '/',
            'httpMethod' => $method,
            'headers' => $headers,
            'queryStringParameters' => $urlParts['query'] ?? null,
            'requestContext' => $context,
            'body' => $body,
            'isBase64Encoded' => false,
        ];

        $response = $this->lambda->invoke([
            'FunctionName' => $this->functionName,
            'LogType' => 'Tail',
            'Payload' => json_encode($payload),
        ]);

        $resultPayload = json_decode($response->getPayload(), true);
        $invocationResult = new InvocationResult($response, $resultPayload);

        $error = $response->getFunctionError();
        if ($error) {
            throw new InvocationFailed($invocationResult);
        }

        return $invocationResult;
    }
}
