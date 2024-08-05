<?php

declare(strict_types=1);

namespace WebServCo\Http\Client\DataTransfer;

final class CurlServiceConfiguration
{
    public function __construct(public bool $enableDebugMode)
    {
    }
}
