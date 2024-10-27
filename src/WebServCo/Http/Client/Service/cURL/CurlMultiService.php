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
use WebServCo\Http\Client\Traits\CurlExceptionTrait;

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
use function sprintf;

use const CURLE_OK;
use const CURLM_OK;

final class CurlMultiService implements CurlMultiServiceInterface
{
    use CurlExceptionTrait;

    private const string ERROR_MULTI_HANDLE_NULL = 'Multi handle not initialized.';

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
        $this->logDebug(__FUNCTION__);
    }

    public function createHandle(RequestInterface $request): string
    {
        $this->logDebug(__FUNCTION__);

        if ($this->curlMultiHandle === null) {
            /**
             * Create the multiple cURL handle.
             * "Allows the processing of multiple cURL handles asynchronously."
             */
            $this->curlMultiHandle = curl_multi_init();
            $this->logDebug('No pre-existing curlMultiHandle, created now.');
        }

        $handle = $this->curlService->createHandle($request);
        $handleIdentifier = $this->curlService->getHandleIdentifier($handle);
        $this->logDebug(sprintf('Created curlHandle with id "%s"', $handleIdentifier));

        $this->curlHandles[$handleIdentifier] = $handle;

        // "Add a normal cURL handle to a cURL multi handle".
        curl_multi_add_handle($this->curlMultiHandle, $handle);

        return $handleIdentifier;
    }

    public function executeSessions(): bool
    {
        $this->logDebug(__FUNCTION__);

        // Validate
        if ($this->curlMultiHandle === null) {
            $this->logError(self::ERROR_MULTI_HANDLE_NULL);

            throw new ClientException(self::ERROR_MULTI_HANDLE_NULL);
        }

        // Execute the multi handle
        $stillRunning = 0;
        do {
            $this->logDebug(sprintf('stillRunning: "%d"', $stillRunning));
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

            $this->logDebug(sprintf('stillRunning: "%d"', $stillRunning));
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
        $this->logDebug(sprintf('%s: %s', __FUNCTION__, $handleIdentifier));

        // Validate
        if ($this->curlMultiHandle === null) {
            $this->logError(self::ERROR_MULTI_HANDLE_NULL);

            throw new ClientException(self::ERROR_MULTI_HANDLE_NULL);
        }
        if (!array_key_exists($handleIdentifier, $this->curlHandles)) {
            $this->logError(sprintf('Handle not found: "%s"', $handleIdentifier));

            throw new ClientException('Handle not found.');
        }

        /**
         * Check for error during multi execution, for this specific handle.
         */
        if (array_key_exists($handleIdentifier, $this->curlHandleExceptions)) {
            $this->logError('Exception happened during execution of handle, throwing.');

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
        $this->logDebug('Removed handle from curlMultiHandle and local curlHandles');

        return $response;
    }

    /**
     * @return \Generator<\Psr\Http\Message\ResponseInterface>
     */
    public function iterateResponse(): Generator
    {
        $this->logDebug(__FUNCTION__);

        foreach (array_keys($this->curlHandles) as $handleIdentifier) {
            $this->logDebug('Yield response.');

            yield $this->getResponse($handleIdentifier);
        }
    }

    public function reset(): bool
    {
        $this->logDebug(__FUNCTION__);

        // Reset (local).

        $this->curlHandleExceptions = [];

        $this->curlHandles = [];

        $this->curlMultiHandle = null;

        // Reset (service)

        $this->curlService->reset();

        return true;
    }

    private function addCurlHandleException(int $code, CurlHandle $curlHandle): bool
    {
        $this->logDebug(__FUNCTION__);

        $handleIdentifier = $this->curlService->getHandleIdentifier($curlHandle);

        // Reminder: `result` is a `CURLE_` code, depite coming from `curl_multi_info_read`.
        $this->curlHandleExceptions[$handleIdentifier] = $this->createExceptionFromErrorCode($code);

        $this->logDebug('Individual error happened, added to list.');

        return true;
    }

    /**
     * Create exception object based on status code.
     *
     * Empty value (0, no errro) should be handled by consumer.
     */
    private function createExceptionFromMultiErrorCode(int $code): Throwable
    {
        $this->logDebug(__FUNCTION__);

        $errorMessage = curl_multi_strerror($code);

        return new ClientException($errorMessage ?? (string) $code, $code);
    }

    /**
     * @phpcs:ignore SlevomatCodingStandard.TypeHints.DisallowMixedTypeHint.DisallowedMixedTypeHint
     * @param array<mixed> $multiInfo
     */
    private function handleSessionsExecutionIndividualError(array $multiInfo): bool
    {
        $this->logDebug(__FUNCTION__);

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

        // Reminder: `result` is a `CURLE_` code, despite coming from `curl_multi_info_read`.
        if ($multiInfo['result'] === CURLE_OK) {
            $this->logDebug('No individual error.');

            return false;
        }

        return $this->addCurlHandleException($multiInfo['result'], $multiInfo['handle']);
    }

    private function handleSessionsExecutionIndividualErrors(): bool
    {
        $this->logDebug(__FUNCTION__);

        // Validate
        if ($this->curlMultiHandle === null) {
            $this->logError(self::ERROR_MULTI_HANDLE_NULL);

            throw new ClientException(self::ERROR_MULTI_HANDLE_NULL);
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
        $this->logDebug(__FUNCTION__);

        // Validate
        if ($this->curlMultiHandle === null) {
            throw new ClientException(self::ERROR_MULTI_HANDLE_NULL);
        }

        /**
         * Check for error when executing multi handle.
         */
        $errorNumber = curl_multi_errno($this->curlMultiHandle);
        if ($errorNumber !== 0) {
            $this->logError(sprintf('errorNumber: %d', $errorNumber));

            throw $this->createExceptionFromMultiErrorCode($errorNumber);
        }

        return true;
    }

    private function handleSessionsExecutionStatusCode(int $statusCode): bool
    {
        $this->logDebug(sprintf('%s: %d', __FUNCTION__, $statusCode));

        if ($statusCode !== CURLM_OK) {
            // General (multi) error, not related to individual handle, so ok to throw.
            throw $this->createExceptionFromMultiErrorCode($statusCode);
        }

        return true;
    }

    private function logDebug(string $message): bool
    {
        $this->curlService->logIfDebug(CurlServiceInterface::LOG_CHANNEL, $message);

        return true;
    }

    private function logError(string $message): bool
    {
        $this->curlService->getLogger(CurlServiceInterface::LOG_CHANNEL)->error($message);

        return true;
    }
}
