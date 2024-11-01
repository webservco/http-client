<?php

declare(strict_types=1);

namespace WebServCo\Http\Client\Service\cURL;

use CurlHandle;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use WebServCo\Http\Client\Contract\Service\cURL\CurlServiceInterface;
use WebServCo\Http\Client\Exception\ClientException;

use function array_key_exists;
use function curl_getinfo;
use function fclose;
use function rewind;
use function sprintf;
use function stream_get_contents;

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
            throw new ClientException('Error retrieving locations data.');
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
