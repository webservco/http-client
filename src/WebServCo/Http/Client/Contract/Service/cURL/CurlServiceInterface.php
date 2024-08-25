<?php

declare(strict_types=1);

namespace WebServCo\Http\Client\Contract\Service\cURL;

use CurlHandle;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use WebServCo\Http\Client\DataTransfer\CurlServiceConfiguration;

interface CurlServiceInterface
{
    /**
     * Create a cURL handle, ready to be executed (options and headers already set).
     */
    public function createHandle(RequestInterface $request): CurlHandle;

    /**
     * Execute cURL session.
     *
     * Separate minimal method because it is optional:
     * In the case of cURL Multi session, this is not used.
     */
    public function executeCurlSession(CurlHandle $curlHandle): ?string;

    public function getConfiguration(): CurlServiceConfiguration;

    /**
     * Get identifier for cURL handle.
     * Use case: key for headers array.
     */
    public function getHandleIdentifier(CurlHandle $curlHandle): string;

    /**
     * Get logger for specific cURL handle.
     */
    public function getLogger(?CurlHandle $curlHandle): LoggerInterface;

    /**
     * Get response object.
     *
     * Should be called after cURL session execution.
     * $responseContent is used as a parameter because it can be obtained via different externally called methods:
     * - curl_exec (executeCurlSession)
     * - curl_multi_getcontent
     */
    public function getResponse(CurlHandle $curlHandle, ?string $responseContent): ResponseInterface;

    /**
     * Callback for processing the response headers.
     * CURLOPT_HEADERFUNCTION.
     *
     * Method must be public because it can be accessed also from outside of the service (curl multi session).
     */
    public function headerCallback(CurlHandle $curlHandle, string $headerData): int;

    /**
     * Clear any stored data to free resources.
     */
    public function reset(): bool;
}
