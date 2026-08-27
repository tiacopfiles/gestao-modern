<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreReconciliationSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reconciliation:manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'account_id' => ['required', 'integer', 'min:1'],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['_token', 'account_id', 'period_start', 'period_end']) as $field) {
                $validator->errors()->add($field, 'O campo não faz parte do formulário de sessão.');
            }
        });
    }
}
