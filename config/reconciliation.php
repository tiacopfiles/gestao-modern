<?php

$ids = static function (?string $value): array {
    if ($value === null || trim($value) === '') {
        return [];
    }

    return array_values(array_unique(array_filter(
        array_map(static fn (string $id): int => (int) trim($id), explode(',', $value)),
        static fn (int $id): bool => $id > 0,
    )));
};

return [
    'v2_enabled' => (bool) env('RECONCILIATION_V2_ENABLED', false),
    'matching_enabled' => (bool) env('RECONCILIATION_MATCHING_ENABLED', false),
    'closing_enabled' => (bool) env('RECONCILIATION_CLOSING_ENABLED', false),
    'view_user_ids' => $ids(env('RECONCILIATION_V2_VIEW_USER_IDS')),
    'manage_user_ids' => $ids(env('RECONCILIATION_V2_MANAGE_USER_IDS')),
    'close_user_ids' => $ids(env('RECONCILIATION_CLOSE_USER_IDS')),
    'reopen_user_ids' => $ids(env('RECONCILIATION_REOPEN_USER_IDS')),
    'export_user_ids' => $ids(env('RECONCILIATION_EXPORT_USER_IDS')),
    'admin_user_ids' => $ids(env('RECONCILIATION_ADMIN_USER_IDS')),
];
