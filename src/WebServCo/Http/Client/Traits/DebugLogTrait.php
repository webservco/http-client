<?php

declare(strict_types=1);

namespace WebServCo\Http\Client\Traits;

use Psr\Log\LoggerInterface;
use WebServCo\Http\Client\DataTransfer\CurlServiceConfiguration;

trait DebugLogTrait
{
    protected CurlServiceConfiguration $configuration;

    abstract public function getLogger(string $channel): LoggerInterface;

    public function logIfDebug(string $channel, string $message): bool
    {
        if (!$this->configuration->enableDebugMode) {
            return false;
        }

        $this->getLogger($channel)->debug($message);

        return true;
    }
}
