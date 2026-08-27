<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReconciliationClosureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reconciliation:close') === true;
    }

    public function rules(): array
    {
        return ['confirm' => ['required', 'accepted']];
    }
}
