<?php

declare(strict_types=1);

namespace WebServCo\Http\Client\Service\cURL;

use CurlHandle;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use WebServCo\Http\Client\Contract\Service\cURL\CurlServiceInterface;
use WebServCo\Http\Client\Exception\ClientException;

use function array_key_exists;
use function curl_getinfo;
use function fclose;
use function rewind;
use function stream_get_contents;

abstract class AbstractCurlLoggerService extends AbstractCurlExceptionService implements CurlServiceInterface
{
    /**
     * List of debug streams, by cURL handle.
     * Used with CURLOPT_STDERR.
     *
     * @var array<string,resource>
     */
    protected array $debugStderr = [];

    /**
     * List of redirects, by cURL handle.
     *
     * Populated by the CURLOPT_HEADERFUNCTION callback.
     * Format:
     *  key: cURL handle identifier.
     * value: array of redirects for that cURL handle:
     * - key: CURLINFO_REDIRECT_COUNT
     * - value: CURLINFO_EFFECTIVE_URL
     *
     * @var array<string,array<int,string>> $responseRedirects
     */
    protected array $responseRedirects = [];

    protected function logInfo(CurlHandle $curlHandle): bool
    {
        /**
         * Note: this is the short version (no parameters).
         * Seems more parameters are available, but would have to be called one by one:
         * https://www.php.net/manual/en/function.curl-getinfo.php
         */
        $this->getLogger($curlHandle)->debug('curl_getinfo', ['curl_getinfo' => curl_getinfo($curlHandle)]);

        return true;
    }

    protected function logRedirects(CurlHandle $curlHandle): bool
    {
        $handleIdentifier = $this->getHandleIdentifier($curlHandle);

        if (!array_key_exists($handleIdentifier, $this->responseRedirects)) {
            throw new ClientException('Error retrieving redirect data.');
        }
        $this->getLogger($curlHandle)->debug(
            'redirects',
            ['redirects' => $this->responseRedirects[$handleIdentifier]],
        );

        return true;
    }

    protected function logRequest(CurlHandle $curlHandle, RequestInterface $request): bool
    {
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

    protected function logResponse(CurlHandle $curlHandle, ?ResponseInterface $response): bool
    {
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
        $this->getLogger($curlHandle)->debug('stderr', ['stderr' => $stderr]);

        return true;
    }
}
