<?php

namespace App\Models;

class Recebimento extends LegacyModel
{
    protected $table = 'recebimentos';

    protected function casts(): array
    {
        return ['valor' => 'decimal:2', 'acrescimo' => 'decimal:2', 'desconto' => 'decimal:2', 'valor_total' => 'decimal:2'];
    }
}
