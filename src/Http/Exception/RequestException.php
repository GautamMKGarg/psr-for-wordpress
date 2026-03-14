<?php

declare(strict_types=1);

namespace GautamMKGarg\PsrForWordPress\Http\Exception;

use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * Thrown when the request itself is invalid or cannot be processed.
 *
 * This is thrown for issues with the request object itself, such as:
 * - an empty URI
 * - an invalid URI scheme
 *
 * Unlike NetworkException, this is NOT thrown for network-level errors.
 * Use NetworkException for transport failures.
 */
final class RequestException extends RuntimeException implements RequestExceptionInterface
{
    /**
     * @param string           $message  The error message describing the invalid request
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
     * Returns the request that caused this exception.
     */
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
