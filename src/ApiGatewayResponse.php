<?php

declare(strict_types=1);

namespace Happyr\BrefHookHandler;

use Bref\Lambda\InvocationResult;
use Happyr\BrefHookHandler\Exception\AssertionFailed;

class ApiGatewayResponse
{
    /**
     * @var InvocationResult
     */
    private $result;

    /**
     * @var array
     */
    private $payload;

    /**
     * @var string
     */
    private $url;

    /**
     * @var string|null
     */
    private $body;

    public function __construct(InvocationResult $result, string $url)
    {
        $this->result = $result;
        $this->payload = $result->getPayload();
        $this->url = $url;
    }

    public function getResult(): InvocationResult
    {
        return $this->result;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getBody(): string
    {
        if (null !== $this->body) {
            return $this->body;
        }

        $requestBody = $this->payload['body'] ?? '';
        if ($this->payload['isBase64Encoded'] ?? false) {
            $requestBody = base64_decode($requestBody);
        }

        return $this->body = $requestBody;
    }

    public function assertStatusCode(int $expected)
    {
        if ($expected !== $this->payload['statusCode']) {
            throw AssertionFailed::create('URL "%s" did not response with HTTP status code "%d"', $this->url, $expected);
        }
    }

    public function assertBodyContains(string $string)
    {
        if (false === \mb_stripos($this->getBody(), $string)) {
            throw AssertionFailed::create('URL "%s" does not have a body with string "%s"', $this->url, $string);
        }
    }
}
