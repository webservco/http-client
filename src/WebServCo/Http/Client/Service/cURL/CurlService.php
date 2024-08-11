<?php

declare(strict_types=1);

namespace WebServCo\Http\Client\Service\cURL;

use CurlHandle;
use DateTimeImmutable;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use WebServCo\Http\Client\Contract\Service\cURL\CurlServiceInterface;
use WebServCo\Http\Client\DataTransfer\CurlServiceConfiguration;
use WebServCo\Http\Client\Exception\ClientException;

use function array_key_exists;
use function curl_exec;
use function curl_init;
use function explode;
use function is_string;
use function md5;
use function spl_object_hash;
use function sprintf;
use function strlen;
use function strtolower;
use function trim;

use const DIRECTORY_SEPARATOR;

/**
 * cURL service.
 *
 * Provides functionality for working with native cURL functions.
 * Can be used both by PSR-18 clients, and cURl Multi implementations.
 * The same instance can be used for multiple sessions.
 *
 * Non-public methods in abstract class in order to keep the file under 250 lines.
 */
final class CurlService extends AbstractCurlService implements CurlServiceInterface
{
    /**
     * @see interface method DockBlock
     */
    public function createHandle(RequestInterface $request): CurlHandle
    {
        if ($this->configuration->enableDebugMode) {
            $this->getLogger(null)->debug(sprintf('%s: %s', __FUNCTION__, $request->getUri()));
        }
        try {
            $curlHandle = curl_init();
            if (!$curlHandle instanceof CurlHandle) {
                throw new ClientException('Error initializing cURL session.');
            }

            $curlHandle = $this->setRequestOptions($curlHandle, $request);
            $curlHandle = $this->handleDebugBeforeExecution($curlHandle, $request);
            $curlHandle = $this->setRequestHeaders($curlHandle, $request);

            if ($this->configuration->enableDebugMode) {
                $this->getLogger($curlHandle)->debug('Handle created.');
            }

            return $curlHandle;
        } catch (Throwable $throwable) {
            /**
             * Log error.
             * Since most likely there is no handle, we need to use a general log.
             */
            $this->logThrowable(null, $throwable);

            throw $throwable;
        }
    }

    /**
     * @see interface method DockBlock
     */
    public function executeCurlSession(CurlHandle $curlHandle): ?string
    {
        if ($this->configuration->enableDebugMode) {
            $this->getLogger($curlHandle)->debug(__FUNCTION__);
        }

        try {
            /**
             * "Execute the given cURL session."
             * "This function should be called after initializing a cURL session
             * and all the options for the session are set."
             * "Returns true on success or false on failure.
             * However, if the CURLOPT_RETURNTRANSFER option is set,
             * it will return the result on success, false on failure."
             */
            $responseContent = curl_exec($curlHandle);

            if ($this->configuration->enableDebugMode) {
                $this->getLogger($curlHandle)->debug('Session executed.');
            }

            if (!is_string($responseContent)) {
                /**
                 * We only work with CURLOPT_RETURNTRANSFER, so string or error.
                 *
                 * Note: because this is a minimal, separate method that may not be called,
                 * throwing an exception here will not have detailed information about the actual error.
                 * Instead, we return null, and error checking will be performed in the getResponse method.
                 * Alternatively, null value can be checked by consumer.
                 */
                return null;
            }

            return $responseContent;
        } catch (Throwable $throwable) {
            $this->logThrowable($curlHandle, $throwable);

            throw $throwable;
        }
    }

    public function getConfiguration(): CurlServiceConfiguration
    {
        return $this->configuration;
    }

    /**
     * @see interface method DockBlock
     */
    public function getHandleIdentifier(CurlHandle $curlHandle): string
    {
        /**
         * md5 also used based on comments in the manual:
         *
         * "New hashes are much more simple and can be something like
         * "0000000000000e600000000000000000" or "0000000000000e490000000000000000",
         * which PHP will interpret as numeric (exponent).
         * in_array() will compare non type-safe by default and will interpret named hashes as "0"."
         *
         * "to facilitate visual comparisons, and make it more likely that the first few or last few digits are unique"
         */
        return md5(spl_object_hash($curlHandle));
    }

    /**
     * Get logger for specific cURL handle.
     */
    public function getLogger(?CurlHandle $curlHandle): LoggerInterface
    {
        $handleIdentifier = $curlHandle !== null
            ? $this->getHandleIdentifier($curlHandle)
            : 'http-client';
        if (!array_key_exists($handleIdentifier, $this->loggers)) {
            $dateTimeImmutable = new DateTimeImmutable();
            $this->loggers[$handleIdentifier] = $this->loggerFactory->createLogger(
                /**
                 * Unorthodox: use a path (http-client/time/handleIdentifier) as channel.
                 */
                sprintf(
                    '%s%s%s%s%s',
                    'http-client',
                    DIRECTORY_SEPARATOR,
                    // Use only up to minutes, as requests may spread across seconds
                    $dateTimeImmutable->format('Ymd.Hi'),
                    DIRECTORY_SEPARATOR,
                    sprintf('%s.%s', $dateTimeImmutable->format('Ymd.His.u'), $handleIdentifier),
                ),
            );
        }

        return $this->loggers[$handleIdentifier];
    }

    /**
     * @see interface method DockBlock
     */
    public function getResponse(CurlHandle $curlHandle, ?string $responseContent): ResponseInterface
    {
        if ($this->configuration->enableDebugMode) {
            $this->getLogger($curlHandle)->debug(__FUNCTION__);
        }

        $response = null;
        try {
            /**
             * Check for errors.
             *
             * Note: this has no effect in multi handle context.
             * Instead, there are extra checks in the CurlMultiService part,
             * so make sure to use that service if working with multi handles.
             */
            $this->handleResponseError($curlHandle);

            // Get status.
            $responseCode = $this->getResponseCode($curlHandle);

            if ($this->configuration->enableDebugMode) {
                $this->getLogger($curlHandle)->debug(sprintf('Response code: %d.', $responseCode));
            }

            // Create response.
            $response = $this->responseFactory->createResponse($responseCode);

            // Add headers.
            $response = $this->setResponseHeaders($curlHandle, $response);
            // Add optional body.
            $response = $this->setResponseBody($response, $responseContent);

            return $response;
        } catch (Throwable $throwable) {
            $this->logThrowable($curlHandle, $throwable);

            throw $throwable;
        } finally {
            $this->handleDebugAfterExecution($curlHandle, $response);
        }
    }

    /**
     * @see interface method DockBlock
     */
    public function headerCallback(CurlHandle $curlHandle, string $headerData): int
    {
        // Keep track of redirects and reset headers between each redirect
        $this->handleRedirects($curlHandle);

        $headerDataLength = strlen($headerData);

        $handleIdentifier = $this->getHandleIdentifier($curlHandle);

        $parts = explode(':', $headerData, 2);

        if (array_key_exists(1, $parts)) {
            $this->responseHeaders[$handleIdentifier][strtolower(trim($parts[0]))][] = trim($parts[1]);
        }

        return $headerDataLength;
    }
}
