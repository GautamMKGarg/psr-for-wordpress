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
     * PayPal Partner Attribution ID (BN code) injected automatically on every
     * request whose host is paypal.com or any subdomain (e.g. api.paypal.com,
     * api-m.sandbox.paypal.com).
     *
     * The header is only added when the caller has not already set it, so
     * callers can override it if needed.
     *
     * @see https://developer.paypal.com/api/rest/requests/#link-httprequestheaders
     */
    private const PAYPAL_ATTRIBUTION_HEADER = 'PayPal-Partner-Attribution-Id';
    private const PAYPAL_ATTRIBUTION_ID     = 'LogicBridgeTechnoMartLLP_SI';

    /**
     * Default request options for the WordPress HTTP API.
     *
     * These match the WP_Http::request() $args keys.
     *
     * PSR-18: "If the HTTP Client receives a redirect, it MUST NOT automatically
     * follow the redirect." — redirection must be 0 (disabled) by default.
     *
     * @var array<string, mixed>
     */
    private const DEFAULTS = [
        'timeout'     => 10,   // seconds — WP default is 5, we use 10 for API calls
        'redirection' => 0,    // PSR-18: must NOT follow redirects automatically
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

        $request = $this->withVendorHeaders($request);
        $args    = $this->buildRequestArgs($request);

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

        // PSR-7/PSR-18: any method can carry a body; forward it as-is.
        // Do NOT filter out body based on HTTP method — the PSR-7 request already
        // encodes the caller's intent and the integration test suite explicitly
        // verifies that bodies (and matching Content-Length headers) are forwarded
        // even for GET/HEAD/OPTIONS requests.
        $bodyContent  = (string) $request->getBody();
        $args['body'] = $bodyContent !== '' ? $bodyContent : null;

        return $args;
    }

    /**
     * Injects vendor-required headers that must be present on requests to
     * specific third-party APIs.
     *
     * Currently handles:
     *     PayPal - adds PayPal-Partner-Attribution-Id (BN code) on every
     *     request to paypal.com or any subdomain (api.paypal.com,
     *     api-m.sandbox.paypal.com, …).  The header is skipped when the
     *     caller has already supplied it.
     *
     * The method operates on the immutable PSR-7 request and returns a new
     * instance; the original request passed to sendRequest() is never mutated.
     */
    private function withVendorHeaders(RequestInterface $request): RequestInterface
    {
        $host = strtolower($request->getUri()->getHost());

        if (
            ($host === 'paypal.com' || str_ends_with($host, '.paypal.com'))
            && !$request->hasHeader(self::PAYPAL_ATTRIBUTION_HEADER)
        ) {
            $request = $request->withHeader(
                self::PAYPAL_ATTRIBUTION_HEADER,
                self::PAYPAL_ATTRIBUTION_ID
            );
        }

        return $request;
    }

    /**
     * Sends the request using wp_remote_request() and returns a PSR-7 response.
     *
     * @param array<string, mixed> $args
     * @throws NetworkException If wp_remote_request() returns WP_Error.
     */
    private function sendViaWordPress(string $url, array $args, RequestInterface $request): ResponseInterface
    {
        // WordPress's WP_Http hard-codes data_format='query' for GET and HEAD
        // (class-wp-http.php line 384: "All non-GET/HEAD requests should put the
        // arguments in the form body"). WpOrg\Requests then calls http_build_query()
        // on the body value to append it to the URL — but http_build_query() requires
        // array|object, so a raw JSON string like '[]' (from json_encode([])) throws
        // a TypeError in PHP 8.
        //
        // WordPress exposes no public API to override data_format from outside WP_Http.
        // For methods where this limitation applies and a non-empty body is present, we
        // delegate to sendViaRequests() which calls WpOrg\Requests directly with an
        // explicit data_format='body' — identical to what Guzzle 7 does (cURL directly,
        // no method-based body filtering).
        //
        // This path is only taken for the rare case of body-carrying GET/HEAD/OPTIONS
        // requests (e.g. Paystack's CompletePurchaseRequest sends GET with
        // json_encode([]) = '[]'). All normal POST/PUT/PATCH/DELETE traffic continues
        // through wp_remote_request() and benefits from WordPress proxy/SSL/filter
        // handling as usual.
        if (
            !empty($args['body'])
            && in_array($args['method'], ['GET', 'HEAD', 'OPTIONS', 'CONNECT', 'TRACE'], true)
        ) {
            return $this->sendViaRequests($url, $args, $request);
        }

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
        $maxRedirects = (int) ($args['redirection'] ?? self::DEFAULTS['redirection']);
        $options = [
            'timeout'          => $args['timeout'] ?? self::DEFAULTS['timeout'],
            'redirects'        => $maxRedirects,
            'verify'           => $args['sslverify'] ?? self::DEFAULTS['sslverify'],
            // PSR-18 : the client MUST NOT follow redirects automatically.
            'follow_redirects' => $maxRedirects > 0,
        ];

        // WpOrg\Requests defaults data_format to 'query' for DELETE (and GET/HEAD),
        // which causes it to call http_build_query() on a raw string body, producing
        // a TypeError. PSR-18 permits any method to carry a body, so we force
        // 'body' format whenever body data is present.
        if ($data !== null && $data !== '') {
            $options['data_format'] = 'body';
        }

        // WpOrg\Requests\Transport\Curl hardcodes CURLOPT_NOBODY=true for HEAD
        // requests, which silently discards the request body while preserving the
        // Content-Length header. The server then waits for body bytes that never
        // arrive and cURL times out (error 28). PSR-18 requires the client to
        // forward whatever body the PSR-7 request carries, so we register a
        // curl.before_send hook to un-set CURLOPT_NOBODY and re-attach the body.
        if ($method === 'HEAD' && $data !== null && $data !== '') {
            $hooks    = new \WpOrg\Requests\Hooks();
            $bodyData = $data;
            $hooks->register(
                'curl.before_send',
                static function (&$handle) use ($bodyData): void {
                    curl_setopt($handle, CURLOPT_NOBODY, false);
                    curl_setopt($handle, CURLOPT_POSTFIELDS, $bodyData);
                }
            );
            $options['hooks'] = $hooks;
        }

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

        // When the reason phrase is empty (e.g. the WP response omits it), call
        // createResponse() with one argument so PSR-17 factories that implement
        // the "empty = use default" convention (e.g. Nyholm) fill in the standard
        // phrase ("OK", "Bad Request", …) automatically.
        $response = $reasonPhrase !== ''
            ? $this->getResponseFactory()->createResponse($statusCode, $reasonPhrase)
            : $this->getResponseFactory()->createResponse($statusCode);
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
        // Pass only the status code so that PSR-17 factories that follow the
        // "empty string = use default reason phrase" convention (e.g. Nyholm)
        // fill in the standard phrase (OK, Not Found, …) automatically.
        $response = $this->getResponseFactory()->createResponse(
            $requestsResponse->status_code
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
