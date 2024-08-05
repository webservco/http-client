<?php

declare(strict_types=1);

namespace WebServCo\Http\Client\Contract\Service\cURL;

use Generator;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

interface CurlMultiServiceInterface
{
    /**
     * 1) Cleanup.
     *
     * Use to clean up afterward, or reset between multiple runs.
     */
    public function cleanup(): bool;

    /**
     * 2) Setup a cURL handle.
     *
     * Create normal handle and add it to multi handle.
     *
     * Returns handle identifier.
     *
     * Can be run multiple times as required.
     */
    public function createHandle(RequestInterface $request): string;

    /**
     * 3) Execute all cURL sessions in parallel.
     */
    public function executeSessions(): bool;

    /**
     * 4) Retrieve the response and close the handle (one).
     * Use case: consumer keeps track of requests added and needs to be able to identify corresponding responses.
     */
    public function getResponse(string $handleIdentifier): ResponseInterface;

    /**
     * 5) Retrieve the response and close the handle (all).
     * Use case: consumer just needs all responses, no need to link them to the requests.
     *
     * @return \Generator<\Psr\Http\Message\ResponseInterface>
     */
    public function iterateResponse(): Generator;
}
