<?php

namespace Modules\HrModule\app\Models;

use App\Helpers\SafeMorphHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One file of an employee document (a document may have several: National ID front + back). On the PRIVATE disk under a random
 * name (hr/{account}/{employee}/{uuid}.{ext}); served only by StaffActions::documentFile() (permission + account checked);
 * original_name = what the user uploaded (shown in the list, used as the download name).
 */
class EmployeeDocumentFile extends Model
{
    use SafeMorphHelper;

    const UPDATED_AT = null;

    protected $fillable = ['account_id', 'employee_document_id', 'file', 'original_name', 'size'];

    protected $casts = ['size' => 'integer'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'employee_document_id');
    }

    public function creatable(): MorphTo
    {
        return $this->morphTo();
    }

    public function path(int $employeeId): string
    {
        return 'hr/' . $this->account_id . '/' . $employeeId . '/' . $this->file;
    }
}
