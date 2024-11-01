<?php

declare(strict_types=1);

namespace WebServCo\Http\Client\Traits;

use Throwable;
use WebServCo\Http\Client\Exception\ClientException;

use function curl_strerror;

trait CurlExceptionTrait
{
    /**
     * Create exception object based on status code.
     *
     * Empty value (0, no error) should be handled by consumer.
     */
    protected function createExceptionFromErrorCode(int $code): Throwable
    {
        $errorMessage = curl_strerror($code);

        return new ClientException($errorMessage ?? (string) $code, $code);
    }
}
