<?php

/*
| Pesos técnicos provisórios da rules-v1. Alterações exigem nova versão,
| regressão documentada e validação do negócio; nunca conferem autoridade automática.
*/
return [
    'engine_version' => 'rules-v1',
    'date_window_days' => 10,
    'max_candidate_pool' => 200,
    'max_candidates_per_resource' => 8,
    'max_composition_size' => 3,
    'max_composition_pool' => 12,
    'max_combinations_per_resource' => 100,
    'minimum_display_score' => 25,
    'ambiguity_delta' => 5,
    'weights' => [
        'amount_exact' => 30,
        'business_document_exact' => 30,
        'document_in_reference' => 20,
        'counterparty_document_exact' => 30,
        'counterparty_name_exact' => 15,
        'counterparty_name_overlap' => 8,
        'date_same' => 12,
        'date_near' => 8,
        'date_window' => 3,
    ],
    'confidence' => ['high' => 75, 'medium' => 50],
];
