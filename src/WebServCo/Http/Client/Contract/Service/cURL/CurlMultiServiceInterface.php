<?php

declare(strict_types=1);

namespace WebServCo\Http\Client\Contract\Service\cURL;

use Generator;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

interface CurlMultiServiceInterface
{
    /**
     * 1) Setup a cURL handle.
     *
     * Create normal handle and add it to multi handle.
     *
     * Returns handle identifier.
     *
     * Can be run multiple times as required.
     */
    public function createHandle(RequestInterface $request): string;

    /**
     * 2) Execute all cURL sessions in parallel.
     * This does not actually execute the requests.
     */
    public function executeSessions(): bool;

    /**
     * 3) Retrieve the response and close the handle (one).
     *
     * This is the part that actually executes the request.
     *
     * Use case: consumer keeps track of requests added and needs to be able to identify corresponding responses.
     * Eg. implement rate limiting.
     */
    public function getResponse(string $handleIdentifier): ResponseInterface;

    /**
     * 4) Retrieve the response and close the handle (all).
     *
     * This is the part that actually executes the request.
     *
     * Use case: consumer just needs all responses, no need to link them to the requests.
     *
     * @return \Generator<\Psr\Http\Message\ResponseInterface>
     */
    public function iterateResponse(): Generator;

    /**
     * 5) Reset.
     *
     * Clear any stored data to free resources.
     */
    public function reset(): bool;
}
