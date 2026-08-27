<?php

return [
    'ofx_max_bytes' => (int) env('BANK_IMPORT_OFX_MAX_BYTES', 5 * 1024 * 1024),
];
