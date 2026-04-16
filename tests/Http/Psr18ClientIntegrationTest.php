<?php

declare(strict_types=1);

namespace GautamMKGarg\PsrForWordPress\Tests\Http;

use GautamMKGarg\PsrForWordPress\Http\Psr18Client;
use Http\Client\Tests\HttpClientTest;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;

/**
 * PSR-18 compliance integration tests using the php-http/client-integration-tests suite.
 *
 * Before running these tests, start the test HTTP server:
 *
 *   vendor/bin/http_test_server &
 *
 * Then run:
 *
 *   vendor/bin/phpunit --testsuite Integration
 */
class Psr18ClientIntegrationTest extends HttpClientTest
{
    protected function createHttpAdapter(): ClientInterface
    {
        $factory = new Psr17Factory();

        return new Psr18Client([], $factory, $factory);
    }
}
