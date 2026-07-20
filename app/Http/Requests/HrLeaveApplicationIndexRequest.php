<?php

namespace App\Http\Requests;

use App\Models\LeaveType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HrLeaveApplicationIndexRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', Rule::in([10, 25, 50])],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'employment_type' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(array_keys(LeaveType::EMPLOYMENT_STATUS_LABELS)),
            ],
            'pending_receive' => ['sometimes', 'nullable', 'boolean'],
            'pending_release' => ['sometimes', 'nullable', 'boolean'],
        ];
    }
}
