<?php

namespace Modules\HrModule\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// a leave (Leaves tab): type, dates, days (empty = calendar days), notes, one optional file
class LeaveRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'leave_type_id' => 'required|integer',
            'from_date' => 'required|date',
            'to_date' => 'required|date',
            'days' => 'nullable|numeric|min:0.5|max:366',
            'notes' => 'nullable|string|max:2000',
            'attachment' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:' . (int) config('hrmodule.document_max_kb'),
        ];
    }

    public function attributes(): array
    {
        return ['leave_type_id' => 'leave type', 'from_date' => 'start', 'to_date' => 'end', 'attachment' => 'file'];
    }

    public function messages(): array
    {
        return ['attachment.mimes' => 'The file must be a PDF or an image (JPG, PNG).'];
    }
}
