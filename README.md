# webservco/http-client

A minimalist PHP HTTP Client implementation using cURL.

Test project: [webservco/http-client-test](https://github.com/webservco/http-client-test)

---

## Installation

`composer.json`:

```json
{
  "require": {
    "webservco/http-client": "^0"  
  }
}
```

---

## Usage 

### PSR-18 HTTP client implementation

- Regular usage (send a request, receive a response);
- Fully PSR compliant (can be used as a drop-in replacement);

```php
// PSR-18: \Psr\Http\Client\ClientInterface
$httpClient = new HttpClient(
    // \WebServCo\Http\Client\Contract\Service\cURL\CurlServiceInterface
    $curlService,
);
$response = $httpClient->sendRequest(
    // PSR-7: \Psr\Http\Message\RequestInterface
    $request,
);
```

### Custom multi processing HTTP client implementation

- Use to send multiple requests in parallel via cURL multi handle;
- Custom implementation, thought input and output are PSR compliant;

```php
// \WebServCo\Http\Client\Contract\Service\cURL\CurlMultiServiceInterface
$curlMultiService = new CurlMultiService(
    // \WebServCo\Http\Client\Contract\Service\cURL\CurlServiceInterface
    $curlService
);

/** @var array<int,\Psr\Http\Message\RequestInterface> $requests */
$requests = [
    // PSR-7: \Psr\Http\Message\RequestInterface
    1 => $request1,
    // PSR-7: \Psr\Http\Message\RequestInterface
    2 => $request2,
    // etc
];

// Keep a list of handles to be able to link them to each id. key: id, value: handle identifier.
/** @var array<int,string> $curlHandleIdentifiers */
$curlHandleIdentifiers = [];

foreach ($requests as $request) {
    // Create handle and add it's identifier to the list.
    $handleIdentifier = $curlMultiService->createHandle($request);
    $curlHandleIdentifiers[$releaseId] = $handleIdentifier;
}

// Add more requests if needed
// Create handle and add it's identifier to the list.
$handleIdentifier = $curlMultiService->createHandle($request);
$curlHandleIdentifiers[$releaseId] = $handleIdentifier;

// When ready, execute all requests in parallel
$curlMultiService->executeSessions();

// Retrieve responses

// Use case: consumer keeps track of requests added and needs to be able to identify corresponding responses.
foreach ($curlHandleIdentifiers as $releaseId => $handleIdentifier) {
    $response = $curlMultiService->getResponse($handleIdentifier);
    
    // Do something with the response
}

// Use case: consumer just needs all responses, no need to link them to the requests.
foreach ($curlMultiService->iterateResponse() as $response) {
    // Do something with the response
}

// Cleanup. After this the service can be re-used, going through all the steps.
$curlMultiService->cleanup();
```

---

## Initialization

### `CurlServiceInterface`

Main service class, needed for any chosen usage.

Dependencies: any PSR-17 implementation can be used.

```php
// \WebServCo\Http\Client\Contract\Service\cURL\CurlServiceInterface
$curlService = new CurlService(
    // \WebServCo\Http\Client\DataTransfer\CurlServiceConfiguration
    new CurlServiceConfiguration(
        // enableDebugMode
        true
    ),
    // \WebServCo\Log\Contract\LoggerFactoryInterface
    new ContextFileLoggerFactory(sprintf('%svar/log', $projectPath)),
    // PSR-17: \Psr\Http\Message\ResponseFactoryInterface
    $responseFactory,
    // PSR-17: \Psr\Http\Message\StreamFactoryInterface
    $streamFactory,
);
```

### An example factory using `webservco/http` implementation

```php
<?php

declare(strict_types=1);

namespace Project\Http\Client\Factory;

use WebServCo\Http\Contract\Service\cURL\CurlServiceInterface;
use WebServCo\Http\Factory\Message\Response\ResponseFactory;
use WebServCo\Http\Factory\Message\Stream\StreamFactory;
use WebServCo\Http\Service\cURL\CurlService;
use WebServCo\Http\Service\Message\Response\StatusCodeService;

final class CurlServiceFactory
{
    public function createCurlService(): CurlServiceInterface
    {
        $streamFactory = new StreamFactory();

        return new CurlService(
            new ResponseFactory(new StatusCodeService(), $streamFactory),
            $streamFactory,
        );
    }
}
```

---

## Contributing

Pull requests are welcome. For major changes, please open an issue first to discuss what you would like to change.

Please make sure to update tests as appropriate.

---

## License

[MIT](https://choosealicense.com/licenses/mit/)
