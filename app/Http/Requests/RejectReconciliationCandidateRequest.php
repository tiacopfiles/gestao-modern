<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectReconciliationCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reconciliation:manage') === true;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:1000']];
    }
}
