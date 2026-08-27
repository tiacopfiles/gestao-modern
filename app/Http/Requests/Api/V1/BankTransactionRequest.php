<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BankTransactionRequest extends FormRequest
{
    /** @var list<string> */
    private const ALLOWED_FIELDS = [
        'account_id', 'external_id', 'transaction_date', 'posted_at', 'direction',
        'amount', 'currency', 'description', 'document_number', 'bank_reference',
        'end_to_end_id', 'counterparty', 'balance_after',
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['currency' => $this->input('currency', 'BRL')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'account_id' => ['required', 'integer', 'min:1'],
            'external_id' => ['required', 'string', 'max:128'],
            'transaction_date' => ['required', 'date_format:Y-m-d'],
            'posted_at' => ['nullable', 'string', 'date_format:Y-m-d\TH:i:sP'],
            'direction' => ['required', 'string', Rule::in(['CREDIT', 'DEBIT'])],
            'amount' => ['required', 'string', 'regex:/^(?:0|[1-9]\d{0,12})\.\d{2}$/', 'not_in:0.00'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'description' => ['required', 'string', 'max:10000'],
            'document_number' => ['nullable', 'string', 'max:120'],
            'bank_reference' => ['nullable', 'string', 'max:191'],
            'end_to_end_id' => ['nullable', 'string', 'max:191'],
            'counterparty' => ['nullable', 'array'],
            'counterparty.name' => ['nullable', 'string', 'max:191'],
            'counterparty.document' => ['nullable', 'string', 'max:30'],
            'balance_after' => ['nullable', 'string', 'regex:/^-?(?:0|[1-9]\d{0,12})\.\d{2}$/'],
            'source_system' => ['prohibited'],
            'source_system_id' => ['prohibited'],
            'import_batch_id' => ['prohibited'],
            'payload_hash' => ['prohibited'],
            'raw_hash' => ['prohibited'],
            'id' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), self::ALLOWED_FIELDS) as $field) {
                if (! array_key_exists($field, $this->rules())) {
                    $validator->errors()->add($field, 'O campo não faz parte do contrato bancário da API v1.');
                }
            }

            $counterparty = $this->input('counterparty');
            if (is_array($counterparty)) {
                foreach (array_diff(array_keys($counterparty), ['name', 'document']) as $field) {
                    $validator->errors()->add("counterparty.{$field}", 'O campo não faz parte do contrato bancário da API v1.');
                }
            }
        });
    }
}
