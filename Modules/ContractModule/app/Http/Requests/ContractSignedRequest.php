<?php

namespace Modules\ContractModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// Upload signed: the signing date + the scan (PDF or image, optional; max 10 MB)
class ContractSignedRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'signed_on' => 'required|date_format:Y-m-d|before_or_equal:today',
            'signed_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ];
    }

    public function messages(): array
    {
        return [
            'signed_file.mimes' => 'The scan must be a PDF or an image (JPG, PNG).',
            'signed_file.max' => 'The scan must not be greater than 10 MB.',
            'signed_on.before_or_equal' => 'The signing date can\'t be in the future.',
        ];
    }

    public function attributes(): array
    {
        return ['signed_on' => 'signing date', 'signed_file' => 'signed scan'];
    }

    public function authorize(): bool
    {
        return true;
    }
}
