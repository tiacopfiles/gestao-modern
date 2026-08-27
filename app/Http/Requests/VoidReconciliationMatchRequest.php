<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class VoidReconciliationMatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reconciliation:manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:1000']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['_token', 'reason']) as $field) {
                $validator->errors()->add($field, 'O campo não faz parte do desfazimento.');
            }
        });
    }
}
