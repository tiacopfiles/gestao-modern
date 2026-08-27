<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Contrato de liquidação (realização) de um título.
 *
 * É por aqui que o sistema de origem — Contas a Pagar / Contas a Receber —
 * informa ao Gestão que o título foi efetivamente pago ou recebido. Registrar a
 * realização **não** concilia nada: conciliar é encontrar a transação bancária
 * que comprova essa realização, e continua sendo um passo separado (ADR-010).
 */
class SettleFinancialTitleRequest extends FormRequest
{
    /** @var list<string> */
    private const ALLOWED_FIELDS = ['settlement_date', 'amount', 'installment_number', 'external_id'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'settlement_date' => ['required', 'date_format:Y-m-d'],
            // Opcional: ausente significa liquidar o saldo restante por inteiro,
            // que é o caso esmagadoramente mais comum vindo da origem.
            'amount' => ['nullable', 'string', 'regex:/^(?:0|[1-9]\d{0,12})\.\d{2}$/'],
            // Número da parcela como o usuário a enxerga (1, 2, 3...), nunca o id
            // interno — a origem não conhece as chaves do Gestão.
            'installment_number' => ['nullable', 'integer', 'min:1'],
            // Identificador da baixa no sistema de origem. Torna o reenvio do
            // mesmo pagamento idempotente.
            'external_id' => ['nullable', 'string', 'max:128'],

            'status' => ['prohibited'],
            'type' => ['prohibited'],
            'source_system_id' => ['prohibited'],
            'title_id' => ['prohibited'],
            'installment_id' => ['prohibited'],
            'id' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), self::ALLOWED_FIELDS) as $field) {
                if (! array_key_exists($field, $this->rules())) {
                    $validator->errors()->add($field, 'O campo não faz parte do contrato da API v1.');
                }
            }
        });
    }
}
