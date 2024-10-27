<?php

declare(strict_types=1);

namespace WebServCo\Http\Client\Service\cURL;

use CurlHandle;
use Fig\Http\Message\RequestMethodInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use WebServCo\Http\Client\Contract\Service\cURL\CurlServiceInterface;
use WebServCo\Http\Client\DataTransfer\CurlServiceConfiguration;
use WebServCo\Http\Client\Exception\ClientException;
use WebServCo\Http\Client\Traits\CurlExceptionTrait;
use WebServCo\Http\Client\Traits\DebugLogTrait;
use WebServCo\Log\Contract\LoggerFactoryInterface;

use function array_key_exists;
use function array_key_last;
use function curl_errno;
use function curl_getinfo;
use function curl_setopt;
use function curl_setopt_array;
use function fopen;
use function implode;
use function is_resource;
use function is_string;
use function md5;
use function microtime;
use function sprintf;
use function str_shuffle;
use function strlen;

use const CURLINFO_EFFECTIVE_URL;
use const CURLINFO_REDIRECT_COUNT;
use const CURLINFO_RESPONSE_CODE;
use const CURLOPT_ACCEPT_ENCODING;
use const CURLOPT_CONNECTTIMEOUT;
use const CURLOPT_CUSTOMREQUEST;
use const CURLOPT_FOLLOWLOCATION;
use const CURLOPT_HEADER;
use const CURLOPT_HEADERFUNCTION;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_NOBODY;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_PRIVATE;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_STDERR;
use const CURLOPT_TIMEOUT;
use const CURLOPT_URL;
use const CURLOPT_VERBOSE;

abstract class AbstractCurlService extends AbstractCurlLoggerService implements CurlServiceInterface
{
    use CurlExceptionTrait;
    use DebugLogTrait;

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
        $this->logIfDebug($this->getHandleIdentifier($curlHandle), __FUNCTION__);

        $responseCode = (int) curl_getinfo($curlHandle, CURLINFO_RESPONSE_CODE);
        if ($responseCode === 0) {
            throw new ClientException('Empty response status code (session not executed?).');
        }

        return $responseCode;
    }

    protected function handleDebugAfterExecution(CurlHandle $curlHandle, ?ResponseInterface $response): bool
    {
        $this->logIfDebug($this->getHandleIdentifier($curlHandle), __FUNCTION__);

        if (!$this->configuration->enableDebugMode) {
            return false;
        }

        // Log cURL information.
        $this->logInfo($curlHandle);

        // Log stderr.
        $this->logStderr($curlHandle);

        // Log redirects.
        $this->logLocations($curlHandle);

        // Log response.
        $this->logResponse($curlHandle, $response);

        return true;
    }

    /**
     * Used in `createHandle` to have fewer lines of code.
     */
    protected function handleHandle(CurlHandle $curlHandle, RequestInterface $request): CurlHandle
    {
        $curlHandle = $this->addHandleIdentifier($curlHandle);

        // Log here because handleIdentifier is set above.
        $this->logIfDebug($this->getHandleIdentifier($curlHandle), __FUNCTION__);

        $request = $this->handleRequestBody($request);

        $curlHandle = $this->setRequestOptions($curlHandle, $request);

        $curlHandle = $this->handleDebugBeforeExecution($curlHandle, $request);

        /**
         * Handle request method.
         * Important to be before `setRequestHeaders`, because it can set headers to be added.
         */
        $this->handleRequestMethod($curlHandle, $request);

        $curlHandle = $this->setRequestHeaders($curlHandle, $request);

        return $curlHandle;
    }

    /**
     * Keep track of redirects and reset headers between each redirect
     *
     * "It is important to note that the callback is invoked
     * for the headers of all responses received after initiating a request and not just the final response."
     *
     * Workaround: keep track of redirects and reset headers between each redirect.
     *
     * Note: this is not performant because it does the checks for each individual header.
     * An alternative solution would be to follow redirects manually (more performant, but more difficult to implement).
     */
    protected function handleRedirects(CurlHandle $curlHandle): bool
    {
        $this->logIfDebug($this->getHandleIdentifier($curlHandle), __FUNCTION__);

        $handleIdentifier = $this->getHandleIdentifier($curlHandle);

        // Get current value
        $currentRedirectIndex = (int) curl_getinfo($curlHandle, CURLINFO_REDIRECT_COUNT);
        $currentUrl = (string) curl_getinfo($curlHandle, CURLINFO_EFFECTIVE_URL);

        // Get last index
        $lastRedirectIndex = $this->getLastRedirectIndex($handleIdentifier);

        // Check
        if ($currentRedirectIndex > $lastRedirectIndex) {
            // A new redirect has started, reset headers.
            $this->responseHeaders[$handleIdentifier] = [];
        }

        // Store current redirect info, after checking.
        $this->responseLocations[$handleIdentifier][$currentRedirectIndex] = $currentUrl;

        return true;
    }

    /**
     * Process response error.
     *
     * If session is executed and contains errors,
     * this creates an Exception object that can be thrown when response is accessed.
     *
     * Phan:
     * "PhanCompatibleStandaloneType Cannot use null as a standalone type before php 8.2."
     * However:
     * - composer: 8.3
     * - env where run: 8.3
     *
     * @suppress PhanCompatibleStandaloneType
     */
    protected function handleResponseError(CurlHandle $curlHandle): null
    {
        $this->logIfDebug($this->getHandleIdentifier($curlHandle), __FUNCTION__);

        $errorNumber = curl_errno($curlHandle);
        if ($errorNumber === 0) {
            return null;
        }

        throw $this->createExceptionFromErrorCode($errorNumber);
    }

    protected function setResponseBody(ResponseInterface $response, ?string $responseContent): ResponseInterface
    {
        $this->logIfDebug(self::LOG_CHANNEL, __FUNCTION__);

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

    /**
     *  Phan:
     *  "PhanPartialTypeMismatchArgument Argument 1 ($name) is $name of type int|string
     *  but \Psr\Http\Message\ResponseInterface::withHeader() takes string (int is incompatible)"
     *  This is false, $name is string.
     *
     * @suppress PhanPartialTypeMismatchArgument
     */
    protected function setResponseHeaders(CurlHandle $curlHandle, ResponseInterface $response): ResponseInterface
    {
        $this->logIfDebug($this->getHandleIdentifier($curlHandle), __FUNCTION__);

        $handleIdentifier = $this->getHandleIdentifier($curlHandle);
        foreach ($this->responseHeaders[$handleIdentifier] as $name => $values) {
            // Phan error on next line, see method docblock.
            $response = $response->withHeader($name, implode(', ', $values));
        }

        return $response;
    }

    /**
     * Generate unique handle identifier and add it to handle.
     *
     * Initial functionality: `spl_object_hash`.
     * Removed because hashes are reused by PHP and there can be collisions if service is not reset.
     */
    private function addHandleIdentifier(CurlHandle $curlHandle): CurlHandle
    {
        $this->logIfDebug(self::LOG_CHANNEL, __FUNCTION__);

        curl_setopt($curlHandle, CURLOPT_PRIVATE, str_shuffle(md5(microtime())));

        return $curlHandle;
    }

    /**
     * Create request headers array in cURL format, from Request object.
     * Since all headers must be set at once in cURL, we need this extra step.
     *
     * @return array<int,string>
     */
    private function createRequestHeadersArray(RequestInterface $request): array
    {
        $this->logIfDebug(self::LOG_CHANNEL, __FUNCTION__);

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

    private function getLastRedirectIndex(string $handleIdentifier): ?int
    {
        $this->logIfDebug(self::LOG_CHANNEL, sprintf('%s: %s', __FUNCTION__, $handleIdentifier));

        if (!array_key_exists($handleIdentifier, $this->responseLocations)) {
            return null;
        }

        return array_key_last($this->responseLocations[$handleIdentifier]);
    }

    private function handleDebugBeforeExecution(CurlHandle $curlHandle, RequestInterface $request): CurlHandle
    {
        $this->logIfDebug($this->getHandleIdentifier($curlHandle), __FUNCTION__);

        if (!$this->configuration->enableDebugMode) {
            return $curlHandle;
        }

        $handleIdentifier = $this->getHandleIdentifier($curlHandle);
        // Create a stream where cURL should write errors
        // temporary file/memory wrapper; if bigger than 5MB will be written to temp file.
        $resource = fopen('php://temp/maxmemory:' . (5 * 1_024 * 1_024), 'w');
        if (!is_resource($resource)) {
            throw new ClientException('Error creating debug output location.');
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
     * Make sure request contains all the required data.
     */
    private function handleRequestBody(RequestInterface $request): RequestInterface
    {
        $this->logIfDebug(self::LOG_CHANNEL, __FUNCTION__);

        $body = (string) $request->getBody();
        // Handle "Content-Length" header
        if (!$request->hasHeader('Content-Length')) {
            // Add missing header.
            // Use strlen and not mb_strlen: "The length of the request body in octets (8-bit bytes)."
            $request = $request->withHeader('Content-Length', (string) strlen($body));
        }

        return $request;
    }

    /**
     * Handle request method.
     *
     * Set cURL options relevant to the request metod.
     * This also adds the request body data.
     */
    private function handleRequestMethod(CurlHandle $curlHandle, RequestInterface $request): CurlHandle
    {
        $this->logIfDebug($this->getHandleIdentifier($curlHandle), __FUNCTION__);

        $method = $request->getMethod();
        // ""A custom request method to use instead of "GET" or "HEAD" when doing a HTTP request."
        curl_setopt($curlHandle, CURLOPT_CUSTOMREQUEST, $method);

        switch ($method) {
            case RequestMethodInterface::METHOD_PATCH:
            case RequestMethodInterface::METHOD_POST:
            case RequestMethodInterface::METHOD_PUT:
                // Request can have a body.
                $body = (string) $request->getBody();
                if ($body !== '') {
                    // "The full data to post in a HTTP "POST" operation.
                    // This parameter can either be passed as a urlencoded string like 'para1=val1&para2=val2&...'
                    // or as an array with the field name as key and field data as value.
                    // If value is an array, the Content-Type header will be set to multipart/form-data"
                    curl_setopt($curlHandle, CURLOPT_POSTFIELDS, $body);
                }

                break;
            case RequestMethodInterface::METHOD_HEAD:
                // Request must not have a body.
                curl_setopt($curlHandle, CURLOPT_NOBODY, true);

                break;
            default:
                break;
        }

        return $curlHandle;
    }

    /**
     * Set the request headers to the cURL handle.
     */
    private function setRequestHeaders(CurlHandle $curlHandle, RequestInterface $request): CurlHandle
    {
        $this->logIfDebug($this->getHandleIdentifier($curlHandle), __FUNCTION__);

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
        $this->logIfDebug($this->getHandleIdentifier($curlHandle), __FUNCTION__);

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
                CURLOPT_CONNECTTIMEOUT => $this->configuration->timeout,
                // "true to follow any "Location: " header that the server sends as part of the HTTP header."
                CURLOPT_FOLLOWLOCATION => true,
                // "true to include the header in the output."
                CURLOPT_HEADER => false,
                // "The maximum number of seconds to allow cURL functions to execute."
                CURLOPT_TIMEOUT => $this->configuration->timeout,
                // "The URL to fetch. This can also be set when initializing a session with curl_init()."
                CURLOPT_URL => $request->getUri()->__toString(),
            ],
        );

        // ? consider: v13 "skipSslVerification"

        return $curlHandle;
    }
}
