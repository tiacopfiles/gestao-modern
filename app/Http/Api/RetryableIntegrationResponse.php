<?php

namespace App\Http\Api;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class RetryableIntegrationResponse extends RuntimeException
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct('A integração retornou uma falha transitória.');
    }
}
