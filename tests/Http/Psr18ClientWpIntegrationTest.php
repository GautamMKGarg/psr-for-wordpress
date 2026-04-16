<?php

declare(strict_types=1);

namespace GautamMKGarg\PsrForWordPress\Tests\Http;

use GautamMKGarg\PsrForWordPress\Http\Psr18Client;
use Http\Client\Tests\HttpClientTest;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;

/**
 * PSR-18 compliance integration tests run through the WordPress code path.
 *
 * Requires the WP function stubs defined in tests/WpFunctions.php to be loaded
 * before this suite runs (handled by tests/wp-bootstrap.php).
 * Because wp_remote_request() is defined in the global namespace, Psr18Client
 * will call sendViaWordPress() instead of sendViaRequests() for every request,
 * verifying that the WordPress code path is also fully PSR-18 compliant.
 *
 * Run with:
 *
 *   vendor/bin/phpunit -c phpunit-wp.xml.dist
 */
class Psr18ClientWpIntegrationTest extends HttpClientTest
{
    protected function createHttpAdapter(): ClientInterface
    {
        $factory = new Psr17Factory();

        return new Psr18Client([], $factory, $factory);
    }
}
