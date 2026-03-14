<?php

declare(strict_types=1);

namespace GautamMKGarg\PsrForWordPress\Http;

use GautamMKGarg\PsrForWordPress\Http\Exception\NetworkException;
use GautamMKGarg\PsrForWordPress\Http\Exception\RequestException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use WpOrg\Requests\Requests;

/**
 * PSR-18 HTTP Client backed by the WordPress HTTP API.
 *
 * When running inside WordPress (i.e., when wp_remote_request() is available),
 * this client uses WordPress's own HTTP transport — which means:
 *  - WordPress proxy settings are respected
 *  - WordPress SSL certificate handling is used
 *  - WordPress filters (http_request_args, pre_http_request) are applied
 *
 * When running outside WordPress (e.g., in unit tests or standalone scripts),
 * this client falls back to WpOrg\Requests\Requests directly.
 *
 * Discovery instantiation:
 * php-http/discovery calls new Psr18Client() with no arguments.
 * The default timeout is 10 seconds — higher than WordPress's 5-second default
 * but chosen as a reasonable middle ground for payment gateway APIs.
 * For longer-running requests, either:
 *  1. Pass ['timeout' => 30] to the constructor when manually instantiating
 *  2. Use the WordPress http_request_args filter to override globally
 *
 * PSR-17 factories:
 * By default, the client auto-discovers PSR-17 factories via php-http/discovery.
 * For contexts where discovery is not available, the factories can be injected:
 *   new Psr18Client([], $myResponseFactory, $myStreamFactory)
 *
 * @see https://developer.wordpress.org/reference/functions/wp_remote_request/
 * @see https://github.com/WordPress/Requests
 */
final class Psr18Client implements ClientInterface
{
    /**
     * Default request options for the WordPress HTTP API.
     *
     * These match the WP_Http::request() $args keys.
     *
     * @var array<string, mixed>
     */
    private const DEFAULTS = [
        'timeout'     => 10,   // seconds — WP default is 5, we use 10 for API calls
        'redirection' => 5,    // max redirects
        'sslverify'   => true, // verify SSL certificates
    ];

    /**
     * @param array<string, mixed>         $options         Override any of the DEFAULTS.
     *                                                       Supported keys: timeout, redirection, sslverify, user-agent
     *                                                       All keys are passed directly to wp_remote_request() as $args.
     * @param ResponseFactoryInterface|null $responseFactory PSR-17 factory for creating responses.
     *                                                       Defaults to auto-discovery via Psr17FactoryDiscovery.
     * @param StreamFactoryInterface|null   $streamFactory   PSR-17 factory for creating streams.
     *                                                       Defaults to auto-discovery via Psr17FactoryDiscovery.
     */
    public function __construct(
        private readonly array $options = [],
        private ?ResponseFactoryInterface $responseFactory = null,
        private ?StreamFactoryInterface $streamFactory = null
    ) {
    }

    /**
     * Sends a PSR-7 HTTP request using WordPress HTTP API (or WpOrg\Requests).
     *
     * @throws RequestException If the request is invalid (e.g., empty URI).
     * @throws NetworkException If a network-level error occurs (connection failure, timeout).
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $url = (string) $request->getUri();

        if ($url === '') {
            throw new RequestException(
                'Cannot send a request with an empty URI.',
                $request
            );
        }

        $args = $this->buildRequestArgs($request);

        if (function_exists('wp_remote_request')) {
            return $this->sendViaWordPress($url, $args, $request);
        }

        return $this->sendViaRequests($url, $args, $request);
    }

    /**
     * Converts a PSR-7 request into a WordPress HTTP API args array.
     *
     * @return array<string, mixed>
     */
    private function buildRequestArgs(RequestInterface $request): array
    {
        $args = array_merge(self::DEFAULTS, $this->options);

        // Method
        $args['method'] = strtoupper($request->getMethod());

        // Headers: PSR-7 allows multiple values per header. WP accepts array values.
        // We join multiple values with a comma per HTTP spec (RFC 7230 §3.2.2).
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }
        $args['headers'] = $headers;

        // Body: only pass for methods that typically have a body.
        // For GET, HEAD, OPTIONS — pass null. For others, always pass the body
        // string (even if empty) so WP doesn't fall back to incorrect defaults.
        $method = $args['method'];
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS', 'CONNECT', 'TRACE'], true)) {
            $args['body'] = null;
        } else {
            $bodyContent  = (string) $request->getBody();
            $args['body'] = $bodyContent !== '' ? $bodyContent : null;
        }

        return $args;
    }

    /**
     * Sends the request using wp_remote_request() and returns a PSR-7 response.
     *
     * @param array<string, mixed> $args
     * @throws NetworkException If wp_remote_request() returns WP_Error.
     */
    private function sendViaWordPress(string $url, array $args, RequestInterface $request): ResponseInterface
    {
        /** @var array|\WP_Error $response */
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            /** @var \WP_Error $response */
            throw new NetworkException(
                $response->get_error_message(),
                $request
            );
        }

        return $this->buildResponse($response);
    }

    /**
     * Sends the request using WpOrg\Requests\Requests directly (non-WordPress context).
     *
     * @param array<string, mixed> $args
     * @throws NetworkException If Requests throws an exception.
     */
    private function sendViaRequests(string $url, array $args, RequestInterface $request): ResponseInterface
    {
        $method  = $args['method'] ?? 'GET';
        $headers = $args['headers'] ?? [];
        $data    = $args['body'] ?? null;
        $options = [
            'timeout'          => $args['timeout'] ?? self::DEFAULTS['timeout'],
            'redirects'        => $args['redirection'] ?? self::DEFAULTS['redirection'],
            'verify'           => $args['sslverify'] ?? self::DEFAULTS['sslverify'],
            'follow_redirects' => ($args['redirection'] ?? self::DEFAULTS['redirection']) > 0,
        ];

        try {
            $response = Requests::request($url, $headers, $data, $method, $options);
        } catch (\Throwable $exception) {
            throw new NetworkException(
                $exception->getMessage(),
                $request,
                $exception
            );
        }

        return $this->buildResponseFromRequests($response);
    }

    /**
     * Converts a WordPress HTTP API response array into a PSR-7 ResponseInterface.
     *
     * @param array{
     *   headers: \WpOrg\Requests\Utility\CaseInsensitiveDictionary|array,
     *   body: string,
     *   response: array{code: int|string, message: string},
     *   cookies: array,
     *   http_response: \WP_HTTP_Requests_Response|null
     * } $wpResponse
     */
    private function buildResponse(array $wpResponse): ResponseInterface
    {
        $statusCode   = (int) ($wpResponse['response']['code'] ?? 200);
        $reasonPhrase = (string) ($wpResponse['response']['message'] ?? '');
        $body         = (string) ($wpResponse['body'] ?? '');

        $response = $this->getResponseFactory()->createResponse($statusCode, $reasonPhrase);
        $response = $response->withBody($this->getStreamFactory()->createStream($body));

        // WordPress headers: CaseInsensitiveDictionary or array
        $headers = $wpResponse['headers'] ?? [];
        foreach ($headers as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $singleValue) {
                    $response = $response->withAddedHeader((string) $name, (string) $singleValue);
                }
            } else {
                $response = $response->withHeader((string) $name, (string) $value);
            }
        }

        return $response;
    }

    /**
     * Converts a WpOrg\Requests\Response into a PSR-7 ResponseInterface.
     */
    private function buildResponseFromRequests(\WpOrg\Requests\Response $requestsResponse): ResponseInterface
    {
        $response = $this->getResponseFactory()->createResponse(
            $requestsResponse->status_code,
            ''
        );
        $response = $response->withBody(
            $this->getStreamFactory()->createStream($requestsResponse->body)
        );

        // WpOrg\Requests headers: HeaderDictionary (iterable)
        foreach ($requestsResponse->headers as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $singleValue) {
                    $response = $response->withAddedHeader((string) $name, (string) $singleValue);
                }
            } else {
                $response = $response->withHeader((string) $name, (string) $value);
            }
        }

        return $response;
    }

    /**
     * Returns the response factory, discovering one if not injected.
     */
    private function getResponseFactory(): ResponseFactoryInterface
    {
        if ($this->responseFactory === null) {
            // Use php-http/discovery if available, otherwise fall back to Nyholm
            if (class_exists(\Http\Discovery\Psr17FactoryDiscovery::class)) {
                $this->responseFactory = \Http\Discovery\Psr17FactoryDiscovery::findResponseFactory();
            } else {
                $this->responseFactory = new \Nyholm\Psr7\Factory\Psr17Factory();
            }
        }

        return $this->responseFactory;
    }

    /**
     * Returns the stream factory, discovering one if not injected.
     */
    private function getStreamFactory(): StreamFactoryInterface
    {
        if ($this->streamFactory === null) {
            // Use php-http/discovery if available, otherwise fall back to Nyholm
            if (class_exists(\Http\Discovery\Psr17FactoryDiscovery::class)) {
                $this->streamFactory = \Http\Discovery\Psr17FactoryDiscovery::findStreamFactory();
            } else {
                $this->streamFactory = new \Nyholm\Psr7\Factory\Psr17Factory();
            }
        }

        return $this->streamFactory;
    }
}
