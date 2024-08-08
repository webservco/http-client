<?php

declare(strict_types=1);

namespace WebServCo\Http\Client\DataTransfer;

final class CurlServiceConfiguration
{
    public function __construct(
        /**
         * Debug mode (log request / response, and all possible data).
         */
        public bool $enableDebugMode,
        /**
         * Timeout, in seconds.
         *
         * Used both for connection (`CURLOPT_CONNECTTIMEOUT`) and curl functions (`CURLOPT_TIMEOUT`) timeout.
         */
        public int $timeout,
    ) {
    }
}
