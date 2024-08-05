<?php

declare(strict_types=1);

namespace WebServCo\Http\Client\Service\cURL;

use CurlHandle;
use DateTimeImmutable;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use WebServCo\Http\Client\Contract\Service\cURL\CurlServiceInterface;
use WebServCo\Http\Client\DataTransfer\CurlServiceConfiguration;
use WebServCo\Http\Client\Exception\ClientException;
use WebServCo\Log\Contract\LoggerFactoryInterface;

use function array_key_exists;
use function curl_errno;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt;
use function curl_setopt_array;
use function explode;
use function implode;
use function is_string;
use function md5;
use function spl_object_hash;
use function sprintf;
use function strlen;
use function strtolower;
use function trim;

use const CURLINFO_RESPONSE_CODE;
use const CURLOPT_ACCEPT_ENCODING;
use const CURLOPT_CONNECTTIMEOUT;
use const CURLOPT_FOLLOWLOCATION;
use const CURLOPT_HEADER;
use const CURLOPT_HEADERFUNCTION;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT;
use const CURLOPT_URL;
use const DIRECTORY_SEPARATOR;

/**
 * cURL service.
 *
 * Provides functionality for working with native cURL functions.
 * Can be used both by PSR-18 clients, and cURl Multi implementations.
 * The same instance can be used for multiple sessions.
 */
final class CurlService implements CurlServiceInterface
{
    /**
     * List of loggers, by cURL handle.
     *
     * @var array<string,\Psr\Log\LoggerInterface> $loggers
     */
    private array $loggers = [];

    /**
     * Response headers, by cURL handle.
     *
     * Populated by the CURLOPT_HEADERFUNCTION callback.
     * Format:
     * key: cURL handle identifier.
     * value: array of headers for that cURL handle:
     * - key: header name
     * - value: array of header values;
     *
     * @var array<string,array<string,array<string>>> $responseHeaders
     */
    private array $responseHeaders = [];

    public function __construct(
        private CurlServiceConfiguration $configuration,
        private LoggerFactoryInterface $loggerFactory,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {
    }

    /**
     * @see interface method DockBlock
     */
    public function createHandle(RequestInterface $request): CurlHandle
    {
        try {
            $curlHandle = curl_init();
            if (!$curlHandle instanceof CurlHandle) {
                throw new ClientException('Error initializing cURL session.');
            }

            $curlHandle = $this->setRequestOptions($curlHandle, $request);
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
        try {
            if ($this->configuration->enableDebugMode) {
                $this->getLogger($curlHandle)->debug('Executing session.');
            }

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
            $this->loggers[$handleIdentifier] = $this->loggerFactory->createLogger(
                /**
                 * Unorthodox: use a path (http-client/time/handleIdentifier) as channel.
                 */
                sprintf(
                    '%s%s%s%s%s',
                    'http-client',
                    DIRECTORY_SEPARATOR,
                    // Use only up to minutes, as requests may spread across seconds
                    (new DateTimeImmutable())->format('Ymd.Hi'),
                    DIRECTORY_SEPARATOR,
                    $handleIdentifier,
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
            $this->getLogger($curlHandle)->debug('Get response.');
        }

        try {
            // Check for errors.
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
        }
    }

    /**
     * @see interface method DockBlock
     */
    public function headerCallback(CurlHandle $curlHandle, string $headerData): int
    {
        $headerDataLength = strlen($headerData);

        /**
         * "It is important to note that the callback is invoked
         * for the headers of all responses received after initiating a request and not just the final response."
         */
        /** @todo handle this situation. */

        $parts = explode(':', $headerData, 2);

        if (array_key_exists(1, $parts)) {
            $this->responseHeaders[$this->getHandleIdentifier($curlHandle)][strtolower(trim($parts[0]))][] =
                trim($parts[1]);
        }

        return $headerDataLength;
    }

    /**
     * Create request headers array in cURL format, from Request object.
     * Since all headers must be set at once in cURL, we need this extra step.
     *
     * @return array<int,string>
     */
    private function createRequestHeadersArray(RequestInterface $request): array
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            if ($name === 'Accept-Encoding') {
                // Some special headers are processed separately.
                continue;
            }
            $headers[] = sprintf(
                '%s: %s',
                $name,
                implode(', ', $values),
            );
        }

        return $headers;
    }

    /**
     * Get response code.
     * If session is not executed, returns 0 (should be handled by consumer).
     */
    private function getResponseCode(CurlHandle $curlHandle): int
    {
        $responseCode = (int) curl_getinfo($curlHandle, CURLINFO_RESPONSE_CODE);
        if ($responseCode === 0) {
            throw new ClientException('Empty response status code (session not executed?).');
        }

        return $responseCode;
    }

    /**
     * Process response error.
     *
     * If session is executed and contains errors,
     * this creates an Exception object that can be thrown when response is accessed.
     */
    private function handleResponseError(CurlHandle $curlHandle): null
    {
        $errorNumber = curl_errno($curlHandle);
        if ($errorNumber === 0) {
            return null;
        }

        throw new ClientException(
            curl_error($curlHandle),
            $errorNumber,
        );
    }

    private function logThrowable(?CurlHandle $curlHandle, Throwable $throwable): bool
    {
        $this->getLogger($curlHandle)->error(
            sprintf('Error: "%s"', $throwable->getMessage()),
            [$throwable],
        );

        return true;
    }

    /**
     * Set the request headers to the cURL handle.
     */
    private function setRequestHeaders(CurlHandle $curlHandle, RequestInterface $request): CurlHandle
    {
        if ($request->hasHeader('Accept-Encoding')) {
            /**
             * Set "Accept-Encoding" header.
             * Do in this way instead of manual header, so that the response is automatically decoded.
             * Leave empty string, cURL will list all supported.
             * "https://php.watch/articles/curl-php-accept-encoding-compression"
             */
            curl_setopt($curlHandle, CURLOPT_ACCEPT_ENCODING, $request->getHeaderLine('Accept-Encoding'));
        }

        /**
         * Set headers.
         * Consider:
         * - it is not possible to add headers individually;
         * - does not work to call this multiple times (overwrite);
         * - all headers must be collected and then added all at once;
         * - despite the name `CURLOPT_HTTPHEADER`, it needs and array of all headers;
         */
        curl_setopt(
            $curlHandle,
            /**
             * "An array of HTTP header fields to set, in the format
             * array('Content-type: text/plain', 'Content-length: 100')"
             */
            CURLOPT_HTTPHEADER,
            $this->createRequestHeadersArray($request),
        );

        return $curlHandle;
    }

    /**
     * Set the request options to the cURL handle.
     */
    private function setRequestOptions(CurlHandle $curlHandle, RequestInterface $request): CurlHandle
    {
        curl_setopt_array(
            $curlHandle,
            [
                /**
                 * "true to return the transfer as a string of the return value of curl_exec()
                 * instead of outputting it directly."
                 */
                CURLOPT_RETURNTRANSFER => true,
                /**
                 * Callback for processing the response headers.
                 *
                 * https://curl.se/libcurl/c/CURLOPT_HEADERFUNCTION.html
                 * https://www.php.net/manual/en/curl.constants.php#constant.curlopt-headerfunction
                 * "A callback accepting two parameters.
                 * The first is the cURL resource, the second is a string with the header data to be written.
                 * The header data must be written by this callback. Return the number of bytes written."
                 */
                CURLOPT_HEADERFUNCTION => [$this, 'headerCallback'],
                // "The number of seconds to wait while trying to connect. Use 0 to wait indefinitely."
                CURLOPT_CONNECTTIMEOUT => 3,
                // "true to follow any "Location: " header that the server sends as part of the HTTP header."
                CURLOPT_FOLLOWLOCATION => true,
                // "true to include the header in the output."
                CURLOPT_HEADER => false,
                // "The maximum number of seconds to allow cURL functions to execute."
                CURLOPT_TIMEOUT => 3,
                // "The URL to fetch. This can also be set when initializing a session with curl_init()."
                CURLOPT_URL => $request->getUri()->__toString(),
            ],
        );

        // ? consider: v13 "skipSslVerification"

        return $curlHandle;
    }

    private function setResponseBody(ResponseInterface $response, ?string $responseContent): ResponseInterface
    {
        /**
         * Response content must be a string.
         * Even in the event of a 204 response, and empty string is used.
         * Parameter is nullable in order to avoid extra checks in the consumers.
         * For example curl_multi_getcontent return is nullable.
         */
        if (!is_string($responseContent)) {
            throw new ClientException(
                'Response content not set. Session not executed, or CURLOPT_RETURNTRANSFER not set.',
            );
        }

        if ($responseContent !== '') {
            $response = $response->withBody($this->streamFactory->createStream($responseContent));
        }

        return $response;
    }

    private function setResponseHeaders(CurlHandle $curlHandle, ResponseInterface $response): ResponseInterface
    {
        $handleIdentifier = $this->getHandleIdentifier($curlHandle);
        foreach ($this->responseHeaders[$handleIdentifier] as $name => $values) {
            $response = $response->withHeader($name, implode(', ', $values));
        }

        return $response;
    }
}
