<?php

declare(strict_types=1);

namespace WebServCo\Http\Client\Service\cURL;

use CurlMultiHandle;
use Generator;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use WebServCo\Http\Client\Contract\Service\cURL\CurlMultiServiceInterface;
use WebServCo\Http\Client\Contract\Service\cURL\CurlServiceInterface;
use WebServCo\Http\Client\Exception\ClientException;

use function array_key_exists;
use function array_keys;
use function curl_multi_add_handle;
use function curl_multi_exec;
use function curl_multi_getcontent;
use function curl_multi_init;
use function curl_multi_remove_handle;
use function curl_multi_select;

final class CurlMultiService implements CurlMultiServiceInterface
{
    /**
     * @var array<string,\CurlHandle>
     */
    private array $curlHandles = [];

    private ?CurlMultiHandle $curlMultiHandle = null;

    public function __construct(private CurlServiceInterface $curlService)
    {
    }

    public function cleanup(): bool
    {
        if ($this->curlService->getConfiguration()->enableDebugMode) {
            $this->curlService->getLogger(null)->debug(__FUNCTION__);
        }

        // Reset.
        $this->curlHandles = [];

        $this->curlMultiHandle = null;

        return true;
    }

    public function createHandle(RequestInterface $request): string
    {
        if ($this->curlService->getConfiguration()->enableDebugMode) {
            $this->curlService->getLogger(null)->debug(__FUNCTION__);
        }

        if ($this->curlMultiHandle === null) {
            /**
             * Create the multiple cURL handle.
             * "Allows the processing of multiple cURL handles asynchronously."
             */
            $this->curlMultiHandle = curl_multi_init();
        }

        $handle = $this->curlService->createHandle($request);
        $handleIdentifier = $this->curlService->getHandleIdentifier($handle);

        $this->curlHandles[$handleIdentifier] = $handle;

        // "Add a normal cURL handle to a cURL multi handle".
        curl_multi_add_handle($this->curlMultiHandle, $handle);

        return $handleIdentifier;
    }

    public function executeSessions(): bool
    {
        if ($this->curlService->getConfiguration()->enableDebugMode) {
            $this->curlService->getLogger(null)->debug(__FUNCTION__);
        }

        // Validate
        if ($this->curlMultiHandle === null) {
            throw new ClientException('Multi handle not initialized.');
        }

        // Execute the multi handle
        $stillRunning = 0;
        do {
            /**
             * "Run the sub-connections of the current cURL handle".
             * "Processes each of the handles in the stack.
             * This method can be called whether or not a handle needs to read or write data."
             */
            curl_multi_exec($this->curlMultiHandle, $stillRunning);
            /**
             * "Wait for activity on any curl_multi connection".
             * "Blocks until there is activity on any of the curl_multi connections."
             */
            curl_multi_select($this->curlMultiHandle);
        } while ($stillRunning > 0);

        return true;
    }

    public function getResponse(string $handleIdentifier): ResponseInterface
    {
        if ($this->curlService->getConfiguration()->enableDebugMode) {
            $this->curlService->getLogger(null)->debug(__FUNCTION__);
        }

        // Validate
        if ($this->curlMultiHandle === null) {
            throw new ClientException('Multi handle not initialized.');
        }

        if (!array_key_exists($handleIdentifier, $this->curlHandles)) {
            throw new ClientException('Handle not found.');
        }

        /**
         * "Return the content of a cURL handle if CURLOPT_RETURNTRANSFER is set or null if not set. "
         */
        $responseContent = curl_multi_getcontent($this->curlHandles[$handleIdentifier]);

        $response = $this->curlService->getResponse($this->curlHandles[$handleIdentifier], $responseContent);

        // "Remove a multi handle from a set of cURL handles"
        curl_multi_remove_handle($this->curlMultiHandle, $this->curlHandles[$handleIdentifier]);
        unset($this->curlHandles[$handleIdentifier]);

        return $response;
    }

    /**
     * @return \Generator<\Psr\Http\Message\ResponseInterface>
     */
    public function iterateResponse(): Generator
    {
        if ($this->curlService->getConfiguration()->enableDebugMode) {
            $this->curlService->getLogger(null)->debug(__FUNCTION__);
        }

        foreach (array_keys($this->curlHandles) as $handleIdentifier) {
            yield $this->getResponse($handleIdentifier);
        }
    }
}
