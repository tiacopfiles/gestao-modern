<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable, SoftDeletes;

    protected $table = 'users';

    protected $fillable = [
        'nome', 'email', 'telefone', 'celular', 'empresa', 'comercial',
        'pagamentos', 'username', 'password', 'senha', 'remember_token',
        'reconciliation_view', 'reconciliation_manage', 'reconciliation_close',
        'reconciliation_reopen', 'reconciliation_export', 'reconciliation_admin',
    ];

    protected $hidden = ['password', 'senha', 'remember_token'];

    protected function casts(): array
    {
        return [
            'comercial' => 'boolean',
            'pagamentos' => 'boolean',
            'reconciliation_view' => 'boolean',
            'reconciliation_manage' => 'boolean',
            'reconciliation_close' => 'boolean',
            'reconciliation_reopen' => 'boolean',
            'reconciliation_export' => 'boolean',
            'reconciliation_admin' => 'boolean',
        ];
    }
}
