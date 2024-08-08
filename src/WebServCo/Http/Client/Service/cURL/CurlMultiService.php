<?php

declare(strict_types=1);

namespace WebServCo\Http\Client\Service\cURL;

use CurlHandle;
use CurlMultiHandle;
use Generator;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use WebServCo\Http\Client\Contract\Service\cURL\CurlMultiServiceInterface;
use WebServCo\Http\Client\Contract\Service\cURL\CurlServiceInterface;
use WebServCo\Http\Client\Exception\ClientException;

use function array_key_exists;
use function array_keys;
use function curl_multi_add_handle;
use function curl_multi_errno;
use function curl_multi_exec;
use function curl_multi_getcontent;
use function curl_multi_info_read;
use function curl_multi_init;
use function curl_multi_remove_handle;
use function curl_multi_select;
use function curl_multi_strerror;
use function is_int;

use const CURLE_OK;
use const CURLM_OK;

final class CurlMultiService extends AbstractCurlExceptionService implements CurlMultiServiceInterface
{
    /**
     * Array of cURL handles exceptions.
     * Key: handleIdentifier
     *
     * @var array<string,\Throwable>
     */
    private array $curlHandleExceptions = [];

    /**
     * Array of cURL handles.
     * Key: handleIdentifier
     *
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
        $this->curlHandleExceptions = [];
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
             *
             * "This only returns errors regarding the whole multi stack.
             * There might still have occurred problems on individual transfers
             * even when this function returns CURLM_OK."
             */
            $statusCode = curl_multi_exec($this->curlMultiHandle, $stillRunning);
            $this->handleSessionsExecutionStatusCode($statusCode);

            /**
             * "Wait for activity on any curl_multi connection".
             * "Blocks until there is activity on any of the curl_multi connections."
             *
             * Note: there are examples (incl. official manual) with "if ($stillRunning)" here.
             * However, removed because based on manual comments, it is more performant.
             * References:
             * https://www.php.net/manual/en/function.curl-multi-exec.php#113002
             * https://chatgpt.com/c/69c38126-70f8-4e08-946c-fee3403c08b7
             */
            curl_multi_select($this->curlMultiHandle);

            /**
             * Handle individual errors.
             * Inside the loop because:
             * - handles can complete at different times, so we check continuously.
             * - handle errors promptly as they happen.
             */
            $this->handleSessionsExecutionIndividualErrors();
        } while ($stillRunning > 0);

        /**
         * Handle general errors.
         *
         * Outside the loop because:
         * - check after all handles are completed, as a final validation.
         */
        $this->handleSessionsExecutionGeneralError();

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
         * Check for error during multi execution, for this specific handle.
         */
        if (array_key_exists($handleIdentifier, $this->curlHandleExceptions)) {
            throw $this->curlHandleExceptions[$handleIdentifier];
        }

        /**
         * "Return the content of a cURL handle if CURLOPT_RETURNTRANSFER is set or null if not set. "
         */
        $responseContent = curl_multi_getcontent($this->curlHandles[$handleIdentifier]);

        // Get response. Also performs individual error handling.
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

    /**
     * Create exception object based on status code.
     *
     * Empty value (0, no errro) should be handled by consumer.
     */
    private function createExceptionFromMultiErrorCode(int $code): Throwable
    {
        $errorMessage = curl_multi_strerror($code);

        return new ClientException($errorMessage ?? (string) $code, $code);
    }

    /**
     * @phpcs:ignore SlevomatCodingStandard.TypeHints.DisallowMixedTypeHint.DisallowedMixedTypeHint
     * @param array<mixed> $multiInfo
     */
    private function handleSessionsExecutionIndividualError(array $multiInfo): bool
    {
        if (!array_key_exists('result', $multiInfo)) {
            throw new ClientException('Error parsing multi info.');
        }
        if (!is_int($multiInfo['result'])) {
            throw new ClientException('Error parsing multi info.');
        }
        if (!array_key_exists('handle', $multiInfo)) {
            throw new ClientException('Error parsing multi info.');
        }
        if (!$multiInfo['handle'] instanceof CurlHandle) {
            throw new ClientException('Error parsing multi info.');
        }

        // Reminder: `result` is a `CURLE_` code, depite coming from `curl_multi_info_read`.
        if ($multiInfo['result'] === CURLE_OK) {
            // No error, nothing to do.
            return false;
        }

        $handleIdentifier = $this->curlService->getHandleIdentifier($multiInfo['handle']);
        // Reminder: `result` is a `CURLE_` code, depite coming from `curl_multi_info_read`.
        $this->curlHandleExceptions[$handleIdentifier] = $this->createExceptionFromErrorCode($multiInfo['result']);

        return true;
    }

    private function handleSessionsExecutionIndividualErrors(): bool
    {
        // Validate
        if ($this->curlMultiHandle === null) {
            throw new ClientException('Multi handle not initialized.');
        }

        /**
         * Check for individual handles errors.
         *
         * For this, we do not throw here (were are in execution context).
         * Instead, the exception will be thrown on individual handle retrieval.
         */
        while (($multiInfo = curl_multi_info_read($this->curlMultiHandle)) !== false) {
            $this->handleSessionsExecutionIndividualError($multiInfo);
        }

        return true;
    }

    private function handleSessionsExecutionGeneralError(): bool
    {
        // Validate
        if ($this->curlMultiHandle === null) {
            throw new ClientException('Multi handle not initialized.');
        }

        /**
         * Check for error when executing multi handle.
         */
        $errorNumber = curl_multi_errno($this->curlMultiHandle);
        if ($errorNumber !== 0) {
            throw $this->createExceptionFromMultiErrorCode($errorNumber);
        }

        return true;
    }

    private function handleSessionsExecutionStatusCode(int $statusCode): bool
    {
        if ($statusCode !== CURLM_OK) {
            // General (multi) error, not related to individual handle, so ok to throw.
            throw $this->createExceptionFromMultiErrorCode($statusCode);
        }

        return true;
    }
}
