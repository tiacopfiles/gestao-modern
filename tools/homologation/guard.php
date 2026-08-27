<?php

declare(strict_types=1);

require __DIR__.'/HomologationGuard.php';

use Acop\Homologation\HomologationGuard;

try {
    $rejectProtected = in_array('--empty-target', $argv, true);
    echo json_encode(HomologationGuard::assertSafe($rejectProtected), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(2);
}
