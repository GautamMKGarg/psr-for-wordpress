<?php

declare(strict_types=1);

namespace GautamMKGarg\PsrForWordPress\Http\Exception;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * Thrown when a network error occurs while processing a request.
 *
 * A network error is a lower-level error than an HTTP error response:
 * - connection refused
 * - DNS resolution failure
 * - transport timeout before a response is received
 * - SSL certificate error
 *
 * This corresponds to WordPress returning a WP_Error from wp_remote_request()
 * or WpOrg\Requests throwing a connection/transport exception.
 */
final class NetworkException extends RuntimeException implements NetworkExceptionInterface
{
    /**
     * @param string           $message  The error message from WP_Error or Requests
     * @param RequestInterface $request  The PSR-7 request that caused the error
     * @param \Throwable|null  $previous The previous exception for chaining
     */
    public function __construct(
        string $message,
        private readonly RequestInterface $request,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Returns the request that caused this network exception.
     */
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
