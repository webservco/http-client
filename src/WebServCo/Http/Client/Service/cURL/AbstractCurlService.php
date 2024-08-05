<?php

declare(strict_types=1);

namespace WebServCo\Http\Client\Service\cURL;

use CurlHandle;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;
use WebServCo\Http\Client\Contract\Service\cURL\CurlServiceInterface;
use WebServCo\Http\Client\DataTransfer\CurlServiceConfiguration;
use WebServCo\Http\Client\Exception\ClientException;
use WebServCo\Log\Contract\LoggerFactoryInterface;

use function array_key_exists;
use function curl_errno;
use function curl_error;
use function curl_getinfo;
use function curl_setopt;
use function curl_setopt_array;
use function fopen;
use function implode;
use function is_resource;
use function is_string;
use function sprintf;
use function stream_get_contents;

use const CURLINFO_RESPONSE_CODE;
use const CURLOPT_ACCEPT_ENCODING;
use const CURLOPT_CONNECTTIMEOUT;
use const CURLOPT_FOLLOWLOCATION;
use const CURLOPT_HEADER;
use const CURLOPT_HEADERFUNCTION;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_STDERR;
use const CURLOPT_TIMEOUT;
use const CURLOPT_URL;
use const CURLOPT_VERBOSE;

abstract class AbstractCurlService implements CurlServiceInterface
{
    /**
     * List of loggers, by cURL handle.
     *
     * @var array<string,\Psr\Log\LoggerInterface> $loggers
     */
    protected array $loggers = [];

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
    protected array $responseHeaders = [];

    /**
     * List of debug streams, by cURL handle.
     * Used with CURLOPT_STDERR.
     *
     * @var array<string,resource>
     */
    private array $debugStderr = [];

    public function __construct(
        protected CurlServiceConfiguration $configuration,
        protected LoggerFactoryInterface $loggerFactory,
        protected ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {
    }

    /**
     * Get response code.
     * If session is not executed, returns 0 (should be handled by consumer).
     */
    protected function getResponseCode(CurlHandle $curlHandle): int
    {
        $responseCode = (int) curl_getinfo($curlHandle, CURLINFO_RESPONSE_CODE);
        if ($responseCode === 0) {
            throw new ClientException('Empty response status code (session not executed?).');
        }

        return $responseCode;
    }

    protected function handleDebugAfterExecution(CurlHandle $curlHandle, ?ResponseInterface $response): CurlHandle
    {
        if (!$this->configuration->enableDebugMode) {
            return $curlHandle;
        }

        /**
         * Log cURL information.
         * Note: this is the short version (no parameters).
         * Seems more parameters are available, but would have to be called one by one:
         * https://www.php.net/manual/en/function.curl-getinfo.php
         */
        $this->getLogger($curlHandle)->debug('curl_getinfo', ['curl_getinfo' => curl_getinfo($curlHandle)]);

        // Log stderr.
        $handleIdentifier = $this->getHandleIdentifier($curlHandle);
        if (!array_key_exists($handleIdentifier, $this->debugStderr)) {
            throw new ClientException('Error retrieving debug output location.');
        }
        $stderr = stream_get_contents($this->debugStderr[$handleIdentifier]);
        if ($stderr === false) {
            throw new ClientException('Error retrieving debug output data.');
        }
        $this->getLogger($curlHandle)->debug('stderr', ['stderr' => $stderr]);

        $this->logResponse($curlHandle, $response);

        return $curlHandle;
    }

    protected function handleDebugBeforeExecution(CurlHandle $curlHandle, RequestInterface $request): CurlHandle
    {
        if (!$this->configuration->enableDebugMode) {
            return $curlHandle;
        }

        $handleIdentifier = $this->getHandleIdentifier($curlHandle);
        // Create a stream where cURL should write errors
        // temporary file/memory wrapper; if bigger than 5MB will be written to temp file.
        $resource = fopen('php://temp/maxmemory:' . (5 * 1_024 * 1_024), 'w');
        if (!is_resource($resource)) {
            throw new ClientException('Error creating debug outout location.');
        }
        $this->debugStderr[$handleIdentifier] = $resource;
        // Set cURl debug options
        // "An alternative location to output errors to instead of STDERR."
        curl_setopt($curlHandle, CURLOPT_STDERR, $this->debugStderr[$handleIdentifier]);
        /**
         * "true to output verbose information.
         * Writes output to STDERR, or the file specified using CURLOPT_STDERR."
         * Note: this does not work when CURLINFO_HEADER_OUT is set: https://bugs.php.net/bug.php?id=65348
         */
        curl_setopt($curlHandle, CURLOPT_VERBOSE, true);

        // Log request.
        $this->logRequest($curlHandle, $request);

        return $curlHandle;
    }

    /**
     * Process response error.
     *
     * If session is executed and contains errors,
     * this creates an Exception object that can be thrown when response is accessed.
     */
    protected function handleResponseError(CurlHandle $curlHandle): null
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

    protected function logThrowable(?CurlHandle $curlHandle, Throwable $throwable): bool
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
    protected function setRequestHeaders(CurlHandle $curlHandle, RequestInterface $request): CurlHandle
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
    protected function setRequestOptions(CurlHandle $curlHandle, RequestInterface $request): CurlHandle
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

    protected function setResponseBody(ResponseInterface $response, ?string $responseContent): ResponseInterface
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

    protected function setResponseHeaders(CurlHandle $curlHandle, ResponseInterface $response): ResponseInterface
    {
        $handleIdentifier = $this->getHandleIdentifier($curlHandle);
        foreach ($this->responseHeaders[$handleIdentifier] as $name => $values) {
            $response = $response->withHeader($name, implode(', ', $values));
        }

        return $response;
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

    private function logRequest(CurlHandle $curlHandle, RequestInterface $request): bool
    {
        if (!$this->configuration->enableDebugMode) {
            return false;
        }

        $this->getLogger($curlHandle)->debug(
            'request',
            [
                'request' => [
                    'body' => (string) $request->getBody(),
                    'headers' => $request->getHeaders(),
                    'method' => $request->getMethod(),
                    'url' => $request->getUri()->__toString(),
                ],
            ],
        );

        return true;
    }

    private function logResponse(CurlHandle $curlHandle, ?ResponseInterface $response): bool
    {
        if (!$this->configuration->enableDebugMode) {
            return false;
        }

        if ($response === null) {
            return false;
        }

        $this->getLogger($curlHandle)->debug(
            'response',
            [
                'response' => [
                    'body' => (string) $response->getBody(),
                    'headers' => $response->getHeaders(),
                    'reasonPhrase' => $response->getReasonPhrase(),
                    'statusCode' => $response->getStatusCode(),
                ],
            ],
        );

        return true;
    }
}
