<?php

namespace App\Http\Requests\Api\V1;

use App\Domain\Financial\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Throwable;

class FinancialTitleRequest extends FormRequest
{
    /** @var list<string> */
    private const ALLOWED_FIELDS = [
        'external_id', 'document_number', 'issue_date', 'due_date', 'original_amount',
        'discount_amount', 'addition_amount', 'currency', 'party', 'account_id',
        'category_id', 'cost_center_id', 'installment_count', 'notes',
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'discount_amount' => $this->input('discount_amount', '0.00'),
            'addition_amount' => $this->input('addition_amount', '0.00'),
            'currency' => $this->input('currency', 'BRL'),
            'installment_count' => $this->input('installment_count', 1),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $externalIdComesFromRoute = $this->route('external_id') !== null;
        $money = ['string', 'regex:/^(?:0|[1-9]\d{0,12})\.\d{2}$/'];

        return [
            'external_id' => [
                Rule::requiredIf(! $externalIdComesFromRoute),
                Rule::prohibitedIf($externalIdComesFromRoute),
                'string',
                'max:128',
            ],
            'document_number' => ['nullable', 'string', 'max:120'],
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:issue_date'],
            'original_amount' => ['required', ...$money],
            'discount_amount' => ['required', ...$money],
            'addition_amount' => ['required', ...$money],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'party' => ['nullable', 'array'],
            'party.id' => ['nullable', 'integer', 'min:1'],
            'party.type' => ['nullable', 'string', 'max:30'],
            'party.name' => ['nullable', 'string', 'max:191'],
            'account_id' => ['nullable', 'integer', 'min:1'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'cost_center_id' => ['nullable', 'integer', 'min:1'],
            'installment_count' => ['required', 'integer', 'between:1,999'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'type' => ['prohibited'],
            'source_system' => ['prohibited'],
            'source_system_id' => ['prohibited'],
            'status' => ['prohibited'],
            'total_amount' => ['prohibited'],
            'payload_hash' => ['prohibited'],
            'idempotency_key' => ['prohibited'],
            'legacy_type' => ['prohibited'],
            'legacy_id' => ['prohibited'],
            'id' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
            'deleted_at' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $unknown = array_diff(array_keys($this->all()), self::ALLOWED_FIELDS);
            foreach ($unknown as $field) {
                if (! array_key_exists($field, $this->rules())) {
                    $validator->errors()->add($field, 'O campo não faz parte do contrato da API v1.');
                }
            }

            $party = $this->input('party');
            if (is_array($party)) {
                foreach (array_diff(array_keys($party), ['id', 'type', 'name']) as $field) {
                    $validator->errors()->add("party.{$field}", 'O campo não faz parte do contrato da API v1.');
                }
            }

            if ($validator->errors()->hasAny(['original_amount', 'discount_amount', 'addition_amount'])) {
                return;
            }

            try {
                $original = Money::toCents((string) $this->input('original_amount'));
                $discount = Money::toCents((string) $this->input('discount_amount'));
                $addition = Money::toCents((string) $this->input('addition_amount'));

                if ($original <= 0) {
                    $validator->errors()->add('original_amount', 'O valor original deve ser maior que zero.');
                }
                if ($original - $discount + $addition <= 0) {
                    $validator->errors()->add('original_amount', 'O valor total calculado deve ser maior que zero.');
                }
            } catch (Throwable) {
                $validator->errors()->add('original_amount', 'Os valores monetários são inválidos.');
            }
        });
    }
}
