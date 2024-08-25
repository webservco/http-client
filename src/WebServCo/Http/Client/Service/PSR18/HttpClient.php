<?php

declare(strict_types=1);

namespace WebServCo\Http\Client\Service\PSR18;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use WebServCo\Http\Client\Contract\Service\cURL\CurlServiceInterface;

/*
 * A PSR-18 client implementation using cURL.
 */
final class HttpClient implements ClientInterface
{
    public function __construct(private CurlServiceInterface $curlService)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $curlHandle = $this->curlService->createHandle($request);

        $responseContent = $this->curlService->executeCurlSession($curlHandle);

        $response = $this->curlService->getResponse($curlHandle, $responseContent);

        unset($curlHandle);

        $this->curlService->reset();

        return $response;
    }
}
