<?php

namespace App\Models;

class Movimento extends LegacyModel
{
    protected $table = 'movimentos';

    protected function casts(): array
    {
        return ['valor' => 'decimal:2'];
    }
}
