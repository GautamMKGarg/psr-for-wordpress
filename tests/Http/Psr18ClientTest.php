<?php

declare(strict_types=1);

namespace GautamMKGarg\PsrForWordPress\Tests\Http;

use GautamMKGarg\PsrForWordPress\Http\Exception\NetworkException;
use GautamMKGarg\PsrForWordPress\Http\Exception\RequestException;
use GautamMKGarg\PsrForWordPress\Http\Psr18Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Psr18Client.
 *
 * These tests use WpOrg\Requests as the backend (non-WordPress context)
 * by ensuring wp_remote_request() is not available. In a real WordPress
 * context, WordPress functions are used instead.
 *
 * Integration tests that require WordPress should be run with a WP test suite.
 */
class Psr18ClientTest extends TestCase
{
    private Psr17Factory $factory;
    private Psr18Client $client;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        // Inject Nyholm factories directly to avoid needing php-http/discovery in tests
        $this->client  = new Psr18Client([], $this->factory, $this->factory);
    }

    /**
     * @test
     */
    public function it_throws_request_exception_for_empty_uri(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('Cannot send a request with an empty URI.');

        $request = $this->factory->createRequest('GET', '');
        $this->client->sendRequest($request);
    }

    /**
     * @test
     */
    public function request_exception_carries_the_original_request(): void
    {
        $request = $this->factory->createRequest('GET', '');

        try {
            $this->client->sendRequest($request);
            $this->fail('Expected RequestException was not thrown.');
        } catch (RequestException $exception) {
            $this->assertSame($request, $exception->getRequest());
        }
    }

    /**
     * @test
     */
    public function network_exception_carries_the_original_request(): void
    {
        // Use an invalid domain that should trigger a DNS resolution error.
        $request = $this->factory->createRequest('GET', 'http://this-domain-should-not-exist-psr-for-wordpress.invalid/');

        try {
            $this->client->sendRequest($request);
            $this->fail('Expected NetworkException was not thrown.');
        } catch (NetworkException $exception) {
            $this->assertSame($request, $exception->getRequest());
        }
    }

    /**
     * @test
     */
    public function it_can_be_created_with_custom_options(): void
    {
        $client = new Psr18Client([
            'timeout'     => 30,
            'redirection' => 0,
            'sslverify'   => false,
        ]);

        $this->assertInstanceOf(Psr18Client::class, $client);
    }

    /**
     * @test
     */
    public function it_implements_psr18_client_interface(): void
    {
        $this->assertInstanceOf(\Psr\Http\Client\ClientInterface::class, $this->client);
    }

    /**
     * @test
     */
    public function it_has_no_required_constructor_arguments(): void
    {
        // This confirms discovery compatibility:
        // php-http/discovery calls new Psr18Client() with zero arguments.
        $reflection  = new \ReflectionClass(Psr18Client::class);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            $this->assertTrue(true);
            return;
        }

        $requiredParams = array_filter(
            $constructor->getParameters(),
            fn(\ReflectionParameter $p) => !$p->isOptional()
        );

        $this->assertCount(
            0,
            $requiredParams,
            'Psr18Client must be instantiable with zero arguments for php-http/discovery compatibility.'
        );
    }

    /**
     * @test
     */
    public function it_accepts_injected_psr17_factories(): void
    {
        $factory = new Psr17Factory();
        $client  = new Psr18Client([], $factory, $factory);

        $this->assertInstanceOf(Psr18Client::class, $client);
    }

    // -------------------------------------------------------------------------
    // PayPal Partner Attribution header
    // -------------------------------------------------------------------------

    /**
     * Helper: call the private withVendorHeaders() method via reflection so we
     * can inspect the resulting PSR-7 request without making a network call.
     */
    private function applyVendorHeaders(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\RequestInterface
    {
        $method = new \ReflectionMethod(Psr18Client::class, 'withVendorHeaders');
        $method->setAccessible(true);

        return $method->invoke($this->client, $request);
    }

    /**
     * @test
     */
    public function it_adds_paypal_attribution_header_for_paypal_com(): void
    {
        $request = $this->factory->createRequest('POST', 'https://paypal.com/v2/checkout/orders');
        $result  = $this->applyVendorHeaders($request);

        $this->assertSame(
            'LogicBridgeTechnoMartLLP_SI',
            $result->getHeaderLine('PayPal-Partner-Attribution-Id')
        );
    }

    /**
     * @test
     */
    public function it_adds_paypal_attribution_header_for_api_subdomain(): void
    {
        $request = $this->factory->createRequest('POST', 'https://api-m.paypal.com/v2/checkout/orders');
        $result  = $this->applyVendorHeaders($request);

        $this->assertSame(
            'LogicBridgeTechnoMartLLP_SI',
            $result->getHeaderLine('PayPal-Partner-Attribution-Id')
        );
    }

    /**
     * @test
     */
    public function it_adds_paypal_attribution_header_for_sandbox_subdomain(): void
    {
        $request = $this->factory->createRequest('POST', 'https://api-m.sandbox.paypal.com/v2/checkout/orders');
        $result  = $this->applyVendorHeaders($request);

        $this->assertSame(
            'LogicBridgeTechnoMartLLP_SI',
            $result->getHeaderLine('PayPal-Partner-Attribution-Id')
        );
    }

    /**
     * @test
     */
    public function it_does_not_add_paypal_attribution_header_for_other_domains(): void
    {
        foreach (['https://notpaypal.com/api', 'https://example.com'] as $url) {
            $request = $this->factory->createRequest('POST', $url);
            $result  = $this->applyVendorHeaders($request);

            $this->assertFalse(
                $result->hasHeader('PayPal-Partner-Attribution-Id'),
                "Header must NOT be injected for {$url}"
            );
        }
    }

    /**
     * @test
     */
    public function it_does_not_override_existing_paypal_attribution_header(): void
    {
        $request = $this->factory
            ->createRequest('POST', 'https://api.paypal.com/v2/checkout/orders')
            ->withHeader('PayPal-Partner-Attribution-Id', 'CallerProvidedValue');

        $result = $this->applyVendorHeaders($request);

        $this->assertSame(
            'CallerProvidedValue',
            $result->getHeaderLine('PayPal-Partner-Attribution-Id'),
            'Caller-supplied header must not be overridden'
        );
    }
}
