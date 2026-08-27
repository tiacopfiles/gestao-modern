<?php

namespace App\Application\Reconciliation;

final class ReconciliationClosureHashService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function canonicalize(array $payload): string
    {
        return json_encode(
            $this->normalize($payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function hash(array $payload): string
    {
        return hash('sha256', $this->canonicalize($payload));
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = $this->normalize($item);
        }

        if (! array_is_list($normalized)) {
            ksort($normalized);

            return $normalized;
        }

        // Listas de objetos (matches[]/exceptions[]/metrics[]) não dependem da ordem de
        // inserção do chamador: ordenar pela própria representação canônica elimina essa
        // ambiguidade sem exigir que quem monta o payload conheça a chave primária de cada lista.
        if ($normalized !== [] && array_reduce($normalized, fn (bool $carry, mixed $item): bool => $carry && is_array($item), true)) {
            $encode = fn (array $item): string => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            usort($normalized, fn (array $a, array $b): int => $encode($a) <=> $encode($b));
        }

        return $normalized;
    }
}
