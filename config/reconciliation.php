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

    // Empresas que o cadastro legado (`contas`/`contasareceber`) mantém como
    // registros separados por banco, mas que na vida real são a MESMA
    // empresa e sempre conciliam juntas. Vale só aqui — a conciliação — e
    // NÃO altera `contas`, nem as telas de contas a pagar/receber, que
    // continuam mostrando cada id separadamente. Ver ADR-018 (que resolve o
    // problema oposto: uma empresa, uma conta bancária ambígua) — este é um
    // caso diferente, de propósito único (não é um mecanismo geral).
    'company_groups' => [
        'agrocolitti' => [
            'canonical_id' => (int) env('RECONCILIATION_AGROCOLITTI_ID', 26),
            'member_ids' => $ids(env('RECONCILIATION_AGROCOLITTI_MEMBER_IDS', '26,31')),
            'display_name' => env('RECONCILIATION_AGROCOLITTI_NAME', 'Agrocolitti'),
        ],
    ],
];
