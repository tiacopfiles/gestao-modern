<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreManualReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reconciliation:manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title_installment_ids' => ['required', 'array', 'min:1'],
            'title_installment_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'title_allocations' => ['required', 'array'],
            'title_allocations.*' => ['nullable', 'string', 'regex:/^(?:0|[1-9]\d{0,12})\.\d{2}$/', 'not_in:0.00'],
            'bank_transaction_ids' => ['required', 'array', 'min:1'],
            'bank_transaction_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'transaction_allocations' => ['required', 'array'],
            'transaction_allocations.*' => ['nullable', 'string', 'regex:/^(?:0|[1-9]\d{0,12})\.\d{2}$/', 'not_in:0.00'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), [
                '_token', 'title_installment_ids', 'title_allocations',
                'bank_transaction_ids', 'transaction_allocations',
            ]) as $field) {
                $validator->errors()->add($field, 'O campo não faz parte da confirmação manual.');
            }

            $titleAmounts = $this->input('title_allocations', []);
            foreach ($this->input('title_installment_ids', []) as $id) {
                if (! is_array($titleAmounts) || ! isset($titleAmounts[(string) $id])) {
                    $validator->errors()->add("title_allocations.{$id}", 'Informe o valor alocado para a parcela selecionada.');
                }
            }
            $transactionAmounts = $this->input('transaction_allocations', []);
            foreach ($this->input('bank_transaction_ids', []) as $id) {
                if (! is_array($transactionAmounts) || ! isset($transactionAmounts[(string) $id])) {
                    $validator->errors()->add("transaction_allocations.{$id}", 'Informe o valor alocado para a transação selecionada.');
                }
            }
        });
    }
}
