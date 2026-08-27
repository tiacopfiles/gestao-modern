<?php

namespace App\Application\Reconciliation;

use Illuminate\Support\Str;

class ReconciliationTextNormalizer
{
    public function text(?string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', Str::ascii(mb_strtoupper(trim((string) $value)))));
    }

    public function identifier(?string $value): string
    {
        return preg_replace('/[^A-Z0-9]/', '', $this->text($value)) ?? '';
    }

    public function document(?string $value): string
    {
        $digits = preg_replace('/\D/', '', (string) $value) ?? '';

        return in_array(strlen($digits), [11, 14], true) ? $digits : '';
    }

    /** @return list<string> */
    public function tokens(?string $value): array
    {
        $ignored = ['DA', 'DE', 'DO', 'DAS', 'DOS', 'E', 'LTDA', 'SA', 'ME', 'EIRELI'];
        $tokens = array_filter(explode(' ', $this->text($value)), fn (string $token): bool => mb_strlen($token) >= 3 && ! in_array($token, $ignored, true));
        $tokens = array_values(array_unique($tokens));
        sort($tokens);

        return $tokens;
    }
}
