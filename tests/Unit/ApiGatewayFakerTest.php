<?php

declare(strict_types=1);

namespace Tests\Happyr\BrefHookHandler\Unit;

use AsyncAws\Core\Test\ResultMockFactory;
use AsyncAws\Lambda\LambdaClient;
use AsyncAws\Lambda\Result\InvocationResponse;
use Happyr\BrefHookHandler\ApiGatewayFaker;
use PHPUnit\Framework\TestCase;

class ApiGatewayFakerTest extends TestCase
{
    public function testRequestPayloadBody()
    {
        $context = ['ctxt_lorem' => 'ipsum'];

        $callback = function (string $payload) use ($context) {
            $data = json_decode($payload, true);
            $this->assertEquals('POST', $data['httpMethod']);
            $this->assertEquals('/start', $data['path']);
            $this->assertEquals('body-string', $data['body']);
            $this->assertEquals('example.org', $data['headers']['Host']);
            $this->assertEquals('foobar', $data['headers']['User-Agent']);
            $this->assertEquals('123', $data['headers']['abc']);
            $this->assertEquals($context, $data['requestContext']);
        };
        $faker = new ApiGatewayFaker('foo', '', $this->getLambda($callback));
        $faker->request('POST', '/start', ['User-Agent' => 'foobar', 'abc' => '123'], 'body-string', $context);
    }

    public function testRequestPayload()
    {
        $callback = function (string $payload) {
            $data = json_decode($payload, true);
            $this->assertEquals('GET', $data['httpMethod']);
            $this->assertEquals('/bar/biz', $data['path']);
            $this->assertEquals('ab=2&cd=ef', $data['queryStringParameters']);
            $this->assertEquals('', $data['body']);
            $this->assertEquals('foo.com', $data['headers']['Host']);
            $this->assertEquals('https', $data['headers']['X-Forwarded-Proto']);
            $this->assertEquals('443', $data['headers']['X-Forwarded-Port']);
        };
        $faker = new ApiGatewayFaker('foo', '', $this->getLambda($callback));
        $faker->request('GET', 'https://foo.com/bar/biz?ab=2&cd=ef');
    }

    public function testBaseUrl()
    {
        $callback = function (string $payload) {
            $data = json_decode($payload, true);
            $this->assertEquals('/bar/biz', $data['path']);
            $this->assertEquals('foo.com', $data['headers']['Host']);
            $this->assertEquals('https', $data['headers']['X-Forwarded-Proto']);
        };
        $faker = new ApiGatewayFaker('foo', 'https://foo.com', $this->getLambda($callback));
        $faker->request('GET', '/bar/biz');

        // test a base path
        $callback = function (string $payload) {
            $data = json_decode($payload, true);
            $this->assertEquals('/bar/biz', $data['path']);
            $this->assertEquals('foo.com', $data['headers']['Host']);
            $this->assertEquals('http', $data['headers']['X-Forwarded-Proto']);
        };
        $faker = new ApiGatewayFaker('foo', 'http://foo.com/bar', $this->getLambda($callback));
        $faker->request('GET', '/biz');
    }

    private function getLambda(callable $callback)
    {
        $lambda = $this->getMockBuilder(LambdaClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['invoke'])
            ->getMock();

        $result = ResultMockFactory::create(InvocationResponse::class, [
            'StatusCode' => 200,
            'Payload' => 'OK',
        ]);
        $lambda->expects($this->once())
            ->method('invoke')
            ->with($this->callback(function ($input) use ($callback) {
                if (!is_array($input) || !isset($input['Payload'])) {
                    $this->fail('Argument to LambdaClient::invoke() must be array');
                }

                return $callback($input['Payload']) ?? true;
            }))
            ->willReturn($result);

        return $lambda;
    }
}
