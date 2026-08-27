<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Conta bancária: banco + agência + número.
 *
 * Não confundir com `Conta` (tabela `contas`), que é a EMPRESA/centro herdada do
 * sistema antigo. Uma empresa pode ter várias contas bancárias; é a conta que
 * tem extrato e saldo.
 */
class BankAccount extends Model
{
    use SoftDeletes;

    protected $table = 'bank_accounts';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'is_default' => 'boolean'];
    }

    public function company(): ?Conta
    {
        return $this->company_id === null ? null : Conta::query()->find($this->company_id);
    }

    /**
     * A conta bancária padrão da empresa.
     *
     * Continua existindo para o cadastro e para os comandos de importação, mas
     * **não é mais o que liga uma liquidação a um banco** — quem faz isso é
     * `contaUnicaDaEmpresa()`. Ver ADR-018.
     */
    public static function padraoDaEmpresa(int $companyId): ?self
    {
        return static::query()
            ->where('company_id', $companyId)
            ->where('is_default', true)
            ->where('active', true)
            ->first();
    }

    /** @return Collection<int, self> */
    public static function ativasDaEmpresa(int $companyId)
    {
        return static::query()
            ->where('company_id', $companyId)
            ->where('active', true)
            ->orderBy('id')
            ->get();
    }

    /**
     * A conta bancária da empresa, quando ela tem exatamente uma.
     *
     * É a regra de negócio confirmada com a administração em 26/08/2026: cada
     * empresa opera por uma única conta. Com isso, a liquidação que vem das
     * origens sem banco não é mais uma incógnita — `contas`/`contasareceber`
     * não guardam banco, mas se só existe um, não há o que escolher. Não é a
     * convenção que o ADR-017 encerrou (aquela elegia uma entre várias pelo
     * flag `is_default`); é dedução a partir de um cadastro com um item só.
     *
     * Devolve `null` de propósito quando a empresa tem duas ou mais contas
     * ativas: aí a premissa não vale, a informação realmente falta, e o
     * sistema tem de dizer isso em vez de eleger uma. Ver ADR-018.
     */
    public static function contaUnicaDaEmpresa(int $companyId): ?self
    {
        $ativas = static::ativasDaEmpresa($companyId);

        return $ativas->count() === 1 ? $ativas->first() : null;
    }

    /** "Banco Itaú - Agência 6260 - C/C 13377-9" */
    public function fullLabel(): string
    {
        return trim(sprintf('%s - Agência %s - C/C %s', $this->bank_name, $this->agency, $this->number));
    }

    public function shortLabel(): string
    {
        return trim(sprintf('%s %s/%s', $this->bank_name, $this->agency, $this->number));
    }
}
