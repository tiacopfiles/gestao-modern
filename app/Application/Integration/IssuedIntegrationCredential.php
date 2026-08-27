<?php

namespace App\Application\Integration;

use App\Models\IntegrationClient;

final readonly class IssuedIntegrationCredential
{
    public function __construct(
        public IntegrationClient $client,
        public string $plainTextToken,
    ) {}
}
