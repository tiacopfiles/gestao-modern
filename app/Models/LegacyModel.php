<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Throwable;

abstract class LegacyModel extends Model
{
    use SoftDeletes;

    // Os controladores validam explicitamente os campos. O array vazio evita que
    // o Laravel consulte metadados não disponíveis no MariaDB 10.1 do legado.
    protected $guarded = [];

    public function dateLabel(string $attribute, string $format = 'd/m/Y'): string
    {
        $value = $this->getRawOriginal($attribute);
        if (! is_string($value) || $value === '' || str_starts_with($value, '0000-00-00')) {
            return '—';
        }

        try {
            return CarbonImmutable::parse($value)->format($format);
        } catch (Throwable) {
            return '—';
        }
    }
}
