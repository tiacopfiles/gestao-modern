<?php

namespace App\Domain\Financial;

use InvalidArgumentException;

final class Money
{
    public static function toCents(int|string $amount): int
    {
        if (is_int($amount)) {
            return $amount * 100;
        }

        $normalized = str_replace(',', '.', trim($amount));
        if (! preg_match('/^-?\d+(?:\.\d{1,2})?$/', $normalized)) {
            throw new InvalidArgumentException("Valor monetário inválido: {$amount}");
        }

        $negative = str_starts_with($normalized, '-');
        $unsigned = ltrim($normalized, '-');
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $cents = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');

        return $negative ? -$cents : $cents;
    }

    public static function fromCents(int $cents): string
    {
        $negative = $cents < 0;
        $absolute = abs($cents);
        $formatted = intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);

        return $negative ? '-'.$formatted : $formatted;
    }
}
