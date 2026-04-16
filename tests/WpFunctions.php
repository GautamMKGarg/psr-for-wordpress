<?php

declare(strict_types=1);

/**
 * Minimal WordPress HTTP function stubs for integration testing.
 *
 * These replicate the runtime behaviour of wp_remote_request() and is_wp_error()
 * without requiring a full WordPress installation or database connection.
 *
 * The stubs delegate to WpOrg\Requests\Requests — the same library WordPress
 * uses internally — so the Psr18Client::sendViaWordPress() code path is
 * exercised with a real HTTP backend.
 *
 * IMPORTANT: this file must only be loaded by the WordPress-specific bootstrap
 * (tests/wp-bootstrap.php). Loading it alongside the regular integration tests
 * would cause function_exists('wp_remote_request') to return true, forcing
 * Psr18Client to take the WordPress path for all test suites.
 */

if (!class_exists(\WP_Error::class)) {
    /**
     * Minimal stub of WordPress's WP_Error class.
     */
    class WP_Error
    {
        private string $code;
        private string $message;

        public function __construct(string $code = '', string $message = '')
        {
            $this->code    = $code;
            $this->message = $message;
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}

if (!function_exists('is_wp_error')) {
    /**
     * Stub of WordPress's is_wp_error().
     */
    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof \WP_Error;
    }
}

if (!function_exists('wp_remote_request')) {
    /**
     * Stub of WordPress's wp_remote_request().
     *
     * Accepts the same $args array that WordPress accepts and returns either
     * a WordPress-format response array or a WP_Error on network failure.
     *
     * The response array shape matches what Psr18Client::buildResponse() expects:
     *   [
     *     'headers'       => array<string, string>,
     *     'body'          => string,
     *     'response'      => ['code' => int, 'message' => string],
     *     'cookies'       => [],
     *     'http_response' => null,
     *   ]
     *
     * The 'message' (reason phrase) is intentionally left empty because
     * WpOrg\Requests\Response does not expose the raw reason phrase and
     * Psr18Client::buildResponse() already handles empty message strings
     * gracefully by falling back to the PSR-17 factory defaults.
     *
     * @param string               $url
     * @param array<string, mixed> $args
     * @return array<string, mixed>|\WP_Error
     */
    function wp_remote_request(string $url, array $args = []): array|\WP_Error
    {
        $method       = strtoupper((string) ($args['method']      ?? 'GET'));
        $headers      = (array)  ($args['headers']     ?? []);
        $body         = isset($args['body']) && $args['body'] !== '' ? (string) $args['body'] : null;
        $timeout      = (int)    ($args['timeout']     ?? 10);
        $maxRedirects = (int)    ($args['redirection'] ?? 0);

        $options = [
            'timeout'          => $timeout,
            'redirects'        => $maxRedirects,
            // PSR-18: must NOT follow redirects automatically.
            'follow_redirects' => $maxRedirects > 0,
            'verify'           => (bool) ($args['sslverify'] ?? true),
        ];

        // Force raw-body format for any method that carries a body so that
        // WpOrg\Requests does not call http_build_query() on a raw string.
        if ($body !== null) {
            $options['data_format'] = 'body';
        }

        // WpOrg\Requests\Transport\Curl sets CURLOPT_NOBODY=true for HEAD,
        // which silently discards the request body while the Content-Length
        // header is still sent. The server then waits for body bytes that
        // never arrive → cURL timeout (error 28).
        // Fix: register a curl.before_send hook to override CURLOPT_NOBODY and
        // re-attach the body when HEAD is used with a non-empty body.
        if ($method === 'HEAD' && $body !== null) {
            $hooks    = new \WpOrg\Requests\Hooks();
            $bodyData = $body;
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
            $response = \WpOrg\Requests\Requests::request(
                $url,
                $headers,
                $body,
                $method,
                $options
            );
        } catch (\Throwable $e) {
            return new \WP_Error('http_request_failed', $e->getMessage());
        }

        // Convert WpOrg\Requests\Response headers to a plain array.
        $wpHeaders = [];
        foreach ($response->headers as $name => $value) {
            $wpHeaders[(string) $name] = (string) $value;
        }

        return [
            'headers'       => $wpHeaders,
            'body'          => $response->body,
            'response'      => [
                // Reason phrase is not stored on WpOrg\Requests\Response;
                // Psr18Client::buildResponse() falls back to PSR-17 defaults
                // when message is empty.
                'code'    => $response->status_code,
                'message' => '',
            ],
            'cookies'       => [],
            'http_response' => null,
        ];
    }
}
