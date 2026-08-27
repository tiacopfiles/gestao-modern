<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReopenReconciliationClosureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reconciliation:reopen') === true;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:1000']];
    }
}
