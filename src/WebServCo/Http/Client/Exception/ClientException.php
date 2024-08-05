<?php

declare(strict_types=1);

namespace WebServCo\Http\Client\Exception;

use Exception;
use Psr\Http\Client\ClientExceptionInterface;

final class ClientException extends Exception implements ClientExceptionInterface
{
}
