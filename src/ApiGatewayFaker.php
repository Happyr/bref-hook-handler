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
    private $baseUri;

    public function __construct(string $functionName, ?string $baseUri = null, ?LambdaClient $lambdaClient = null)
    {
        $this->functionName = $functionName;
        $this->lambda = $lambdaClient ?? new LambdaClient();
        $this->baseUri = [];
        if (null !== $baseUri) {
            $this->baseUri = parse_url($baseUri);
            if (!is_array($this->baseUri)) {
                throw new \RuntimeException(sprintf('Could not parse baseUri "%s"', $baseUri));
            }
        }
    }

    /**
     * @throws InvocationFailed
     */
    public function invoke(array $payload): InvocationResult
    {
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

    /**
     * @throws InvocationFailed
     */
    public function request(string $method, string $url, array $headers = [], string $body = '', array $context = []): ApiGatewayResponse
    {
        $urlParts = parse_url($url);
        $schema = $urlParts['scheme'] ?? ($this->baseUri['scheme'] ?? 'https');
        $host = $urlParts['host'] ?? ($this->baseUri['host'] ?? 'example.org');
        $path = ($this->baseUri['path'] ?? '').$urlParts['path'] ?? '/';

        $defaultHeaders = [
            'Accept' => '*/*',
            'Cache-Control' => 'no-cache',
            'Host' => $host,
            'User-Agent' => 'Lambda/Hook',
            'X-Forwarded-For' => '1.1.1.1',
            'X-Forwarded-Port' => 'https' === $schema ? '443' : '80',
            'X-Forwarded-Proto' => $schema,
        ];
        $headers = array_merge($defaultHeaders, $headers);

        $payload = [
            'path' => $path,
            'httpMethod' => $method,
            'headers' => $headers,
            'queryStringParameters' => $urlParts['query'] ?? null,
            'requestContext' => $context,
            'body' => $body,
            'isBase64Encoded' => false,
        ];

        return new ApiGatewayResponse($this->invoke($payload), sprintf('%s://%s%s', $schema, $host, $path));
    }
}
