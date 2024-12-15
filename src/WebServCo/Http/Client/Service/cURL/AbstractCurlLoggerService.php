<?php

declare(strict_types=1);

namespace WebServCo\Http\Client\Service\cURL;

use CurlHandle;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use WebServCo\Http\Client\Contract\Service\cURL\CurlServiceInterface;
use WebServCo\Http\Client\DataTransfer\CurlServiceConfiguration;
use WebServCo\Http\Client\Exception\ClientException;

use function array_key_exists;
use function curl_getinfo;
use function curl_setopt;
use function fclose;
use function fopen;
use function is_resource;
use function rewind;
use function sprintf;
use function stream_get_contents;

use const CURLOPT_STDERR;
use const CURLOPT_VERBOSE;

abstract class AbstractCurlLoggerService implements CurlServiceInterface
{
    /**
     * List of debug streams, by cURL handle.
     * Used with CURLOPT_STDERR.
     *
     * @var array<string,resource>
     */
    protected array $debugStderr = [];

    /**
     * List of all locations visited by each cURL handle.
     *
     * At the very least contains only one item: the original URL.
     * If there are any redirects during execution, more items will be added.
     *
     * Populated by the CURLOPT_HEADERFUNCTION callback.
     * Format:
     *  key: cURL handle identifier.
     * value: array of locations for that cURL handle:
     * - key: CURLINFO_REDIRECT_COUNT
     * - value: CURLINFO_EFFECTIVE_URL
     *
     * @var array<string,array<int,string>> $responseLocations
     */
    protected array $responseLocations = [];

    public function __construct(protected CurlServiceConfiguration $configuration)
    {
    }

    protected function handleDebugBeforeExecution(CurlHandle $curlHandle, RequestInterface $request): CurlHandle
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

    protected function logInfo(CurlHandle $curlHandle): bool
    {
        /**
         * Note: this is the short version (no parameters).
         * Seems more parameters are available, but would have to be called one by one:
         * https://www.php.net/manual/en/function.curl-getinfo.php
         */
        $this->getLogger($this->getHandleIdentifier($curlHandle))->debug(
            'curl_getinfo',
            ['curl_getinfo' => curl_getinfo($curlHandle)],
        );

        return true;
    }

    protected function logLocations(CurlHandle $curlHandle): bool
    {
        $handleIdentifier = $this->getHandleIdentifier($curlHandle);

        if (!array_key_exists($handleIdentifier, $this->responseLocations)) {
            // It is possible there are no locations, eg. when a timeout occurs.
            return false;
        }
        $this->getLogger($this->getHandleIdentifier($curlHandle))->debug(
            'locations',
            ['locations' => $this->responseLocations[$handleIdentifier]],
        );

        return true;
    }

    protected function logRequest(CurlHandle $curlHandle, RequestInterface $request): bool
    {
        $this->getLogger($this->getHandleIdentifier($curlHandle))->debug(
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

    protected function logResponse(CurlHandle $curlHandle, ?ResponseInterface $response): bool
    {
        if ($response === null) {
            return false;
        }

        $this->getLogger($this->getHandleIdentifier($curlHandle))->debug(
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

    protected function logStderr(CurlHandle $curlHandle): bool
    {
        $handleIdentifier = $this->getHandleIdentifier($curlHandle);

        if (!array_key_exists($handleIdentifier, $this->debugStderr)) {
            throw new ClientException('Error retrieving debug output location.');
        }
        rewind($this->debugStderr[$handleIdentifier]);
        $stderr = stream_get_contents($this->debugStderr[$handleIdentifier]);
        fclose($this->debugStderr[$handleIdentifier]);
        if ($stderr === false) {
            throw new ClientException('Error retrieving debug output data.');
        }
        $this->getLogger($this->getHandleIdentifier($curlHandle))->debug('stderr', ['stderr' => $stderr]);

        return true;
    }

    protected function logThrowable(?CurlHandle $curlHandle, Throwable $throwable): bool
    {
        $channel = $curlHandle !== null
            ? $this->getHandleIdentifier($curlHandle)
            : self::LOG_CHANNEL;
        $this->getLogger($channel)->error(
            sprintf('Error: "%s"', $throwable->getMessage()),
            ['throwable' => $throwable],
        );

        return true;
    }
}
