<?php

namespace App\Application\Banking;

use App\Domain\Banking\Exceptions\BankAccountNotFound;
use App\Models\Conta;

class BankAccountValidator
{
    public function ensureExists(int $accountId): void
    {
        if ($accountId < 1 || ! Conta::query()->whereKey($accountId)->exists()) {
            throw new BankAccountNotFound('A conta bancária informada não existe ou está inativa.');
        }
    }
}
