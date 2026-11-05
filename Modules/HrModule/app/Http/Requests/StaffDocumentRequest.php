<?php

namespace Modules\HrModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// Add / edit a staff document (the type is checked in StaffService: active, an "Other" needs its name); files[] = one or more
// files (e.g. National ID front + back), remove_files[] = ids of its files to delete (edit)
class StaffDocumentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'document_type_id' => 'required|integer',
            'name' => 'nullable|string|max:255',
            'number' => 'nullable|string|max:100',
            'issue_date' => 'nullable|date',
            'expiry_date' => 'nullable|date|after_or_equal:issue_date',
            'files' => 'nullable|array|max:10',
            'files.*' => 'file|mimes:pdf,jpg,jpeg,png|max:' . (int) config('hrmodule.document_max_kb'),
            'remove_files' => 'nullable|array',
            'remove_files.*' => 'integer',
            'notes' => 'nullable|string|max:2000',
        ];
    }

    public function messages(): array
    {
        return [
            'files.*.mimes' => 'Each file must be a PDF or an image (JPG, PNG).',
            'files.*.max' => 'Each file must be 10 MB at most.',
            'files.max' => 'Up to 10 files per document.',
            'expiry_date.after_or_equal' => 'The expiry date must be after the issue date.',
        ];
    }
}
