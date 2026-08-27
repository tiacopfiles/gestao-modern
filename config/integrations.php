<?php

return [
    'rate_limit_per_minute' => (int) env('INTEGRATION_API_RATE_LIMIT', 60),

    'scopes' => [
        'payables:read',
        'payables:write',
        'receivables:read',
        'receivables:write',
        'bank-transactions:read',
        'bank-transactions:write',
        'bank-imports:read',
        'bank-imports:write',
    ],
];
