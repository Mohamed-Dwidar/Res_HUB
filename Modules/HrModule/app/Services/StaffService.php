<?php

namespace Modules\HrModule\app\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Hash;
use Modules\EmployeeModule\app\Models\Role;
use Modules\EmployeeModule\app\Services\AccessLogService;
use Modules\EmployeeModule\app\Services\RoleService;
use Modules\UserModule\app\Services\UserService;
use Modules\HrModule\app\Models\DocumentType;
use Modules\HrModule\app\Models\Staff;
use Modules\HrModule\app\Models\EmployeeDocument;
use Modules\HrModule\app\Models\EmployeeDocumentFile;
use Modules\HrModule\app\Models\EmployeeEvent;
use Modules\HrModule\app\Repositories\StaffRepository;

/**
 * Staff files + their documents (docs/hr-plan.md §3.1, H1). A staff file = an `employees` row (Staff model), with or without a
 * login (its users row). Every method takes the owner account id (an account reaches only its own files); rule errors =
 * ValidationException (422 under the fields), so the service is safe outside the forms too.
 * Every change writes an employee_events line (who, before / after; SECRET fields as "changed" only).
 */
class StaffService
{
    // alerts() of this request, per account
    private static array $alerts = [];

    public function __construct(private StaffRepository $staffRepository)
    {
    }

    // the list (paged / ordered by DataTables)
    public function listQuery($accountId)
    {
        return $this->staffRepository->forAccount($accountId)->with(['position.department', 'user']);
    }

    // 404 when it belongs to another account
    public function findOne($accountId, $id): Staff
    {
        return $this->staffRepository->forAccount($accountId)->with(['position.department', 'shift', 'user'])->findOrFail($id);
    }

    /**
     * A new file (no login, role 0): number = MAX + 1 of the account (under a lock). $data['has_access'] (+ login_email,
     * role_id, password) = the form's System Access section (users.manage): a login is made for it (its users row).
     * $data['documents'] = Add Staff › Documents rows.
     */
    public function create($accountId, array $data, Model $user): Staff
    {
        return DB::transaction(function () use ($accountId, $data, $user) {
            $number = (int) Staff::withTrashed()->where('account_id', $accountId)->lockForUpdate()->max('employee_no') + 1;
            $staff = new Staff($this->fileData($data) + ['status' => 'active']);
            $staff->account_id = $accountId;
            $staff->employee_no = $number;
            $staff->role_id = 0;
            $staff->creatable()->associate($user);
            $staff->save();

            $this->event($staff, 'created', null, null, $user);
            if (array_key_exists('has_access', $data)) {
                $this->syncAccess($staff, $data, $user);
            }
            // Add Staff › Documents: each row with a type = a document (its errors shown under the Documents section)
            foreach ((array) ($data['documents'] ?? []) as $row) {
                if (!empty($row['document_type_id'])) {
                    try {
                        $this->createDocument($staff, $row, $user);
                    } catch (ValidationException $e) {
                        // the whole save is rolled back: the files already written for this new file go too
                        Storage::disk('local')->deleteDirectory('hr/' . $accountId . '/' . $staff->id);
                        throw ValidationException::withMessages(['documents' => collect($e->errors())->flatten()->first()]);
                    }
                }
            }
            return $staff;
        });
    }

    // $data = the validated fields only (no hr.sensitive → its fields stay; no users.manage → the access stays as it is)
    public function update($accountId, $id, array $data, Model $user): Staff
    {
        $staff = $this->findOne($accountId, $id);

        return DB::transaction(function () use ($staff, $data, $user) {
            $staff->fill($this->fileData($data, $staff));
            $changes = $this->changes($staff);
            $staff->save();
            if ($changes) {
                $this->event($staff, 'updated', $changes, null, $user);
            }
            if (array_key_exists('has_access', $data)) {
                $this->syncAccess($staff, $data, $user);
            }
            return $staff;
        });
    }

    /**
     * Status (active / on leave / resigned / terminated) + its date + reason (required to end the work). Any status but Active
     * switches the login OFF (user 2026-11-04: leave date = the status date, today at the latest → EnsureUserIsActive logs
     * the session out at its next request). Back to Active does NOT switch it on again: System Access in the form.
     */
    public function changeStatus($accountId, $id, array $data, Model $user): Staff
    {
        $staff = $this->findOne($accountId, $id);
        $ends = in_array($data['status'], config('hrmodule.ended_statuses'), true);
        if ($ends && blank($data['status_reason'] ?? null)) {
            throw ValidationException::withMessages(['status_reason' => 'Write why the work ended.']);
        }

        return DB::transaction(function () use ($staff, $data, $user) {
            $staff->fill([
                'status' => $data['status'],
                'status_date' => $data['status_date'],
                'status_reason' => $data['status_reason'] ?? null,
            ]);
            $changes = $this->changes($staff);
            $staff->save();
            $this->event($staff, 'status', $changes ?: null, $data['status_reason'] ?? null, $user);

            if ($staff->status !== 'active' && $staff->user && !$staff->hasLeft()) {
                $date = Carbon::parse($data['status_date'])->min(today());
                $this->switchOff($staff, $date, 'Status: ' . $staff->status_label, $user);
            }
            return $staff;
        });
    }

    /**
     * The System Access of a file (profile + form): its login — email, role, last login, active / inactive — or nothing.
     * ['login' => Staff|null (the file itself when it has a users row), 'email' => ?string, 'active' => bool,
     *  'roleName' => ?string, 'lastLogin' => AccessLog|null]
     */
    public function accessInfo(?Staff $staff): array
    {
        $login = $staff?->user ? $staff : null;
        return [
            'login' => $login,
            'email' => $login?->user->email,
            'active' => $login && !$login->hasLeft(),
            'roleName' => $login ? app(RoleService::class)->roleOf($login)?->name : null,
            'lastLogin' => $login ? app(AccessLogService::class)->latestOf($login->user->id, 1, 'login')->first() : null,
        ];
    }

    /**
     * The form's System Access section (users.manage; never on one's own file — StaffRequest drops it):
     * ON  + no login → a users row for this file (the email / password typed) + its role;
     * ON  + a login  → switched on again when off, its email / role / password (only when "Change password") changed;
     * OFF + a login  → switched off (leave date today; the users row stays, so the Access Log history stays).
     * ON is refused while the status is not Active (a status change switches it off; it comes back only from here).
     */
    private function syncAccess(Staff $staff, array $data, Model $user): void
    {
        $staff->load('user');
        $roles = app(RoleService::class);

        if (empty($data['has_access'])) {
            if ($staff->user && !$staff->hasLeft()) {
                $this->switchOff($staff, today(), 'System access turned off', $user);
            }
            return;
        }
        if ($staff->status !== 'active') {
            throw ValidationException::withMessages(['has_access' => $staff->name . ' is ' . $staff->status_label . ': set the status to Active first.']);
        }

        if (!$staff->user) {
            $staff->role_id = (int) $data['role_id'];
            $staff->leave_date = null;
            $staff->save();
            app(UserService::class)->createFor($staff, ['email' => $data['login_email'], 'password' => $data['password']]);
            $staff->load('user');
            $this->event($staff, 'access_on', ['email' => [null, $data['login_email']], 'role' => [null, $roles->roleOf($staff)?->name]], null, $user);
            return;
        }

        $changes = [];
        if ($staff->hasLeft()) {
            $staff->leave_date = null;
            $changes['access'] = ['Off', 'On'];
        }
        if ((int) $staff->role_id !== (int) $data['role_id']) {
            $old = $roles->roleOf($staff)?->name;
            $staff->role_id = (int) $data['role_id'];
            $staff->unsetRelation('role');
            $changes['role'] = [$old, Role::find($data['role_id'])?->name];
        }
        $staff->save();
        $login = $staff->user;
        if ($login->email !== $data['login_email']) {
            $changes['email'] = [$login->email, $data['login_email']];
            $login->email = $data['login_email'];
        }
        if (!empty($data['change_password']) && filled($data['password'] ?? null)) {
            $login->password = Hash::make($data['password']);
            $changes['password'] = 'changed';
        }
        $login->save();
        if ($changes) {
            $this->event($staff, isset($changes['access']) ? 'access_on' : 'access_changed', $changes, null, $user);
        }
    }

    // the login can't log in from $date (its session ends at the next request: EnsureUserIsActive); rows + history stay
    private function switchOff(Staff $staff, Carbon $date, string $reason, Model $user): void
    {
        $staff->leave_date = $date->toDateString();
        $staff->save();
        $this->event($staff, 'access_off', ['access' => ['On', 'Off']], $reason, $user);
    }

    // a file made by mistake only: no documents and no login (a login has an Access Log / records it made); the owner only
    public function deleteOne($accountId, $id, Model $user): void
    {
        $staff = $this->findOne($accountId, $id);
        if ($staff->user) {
            throw ValidationException::withMessages(['staff' => 'This person has a system login: change the status (or switch the access off) instead.']);
        }
        if ($staff->documents()->exists()) {
            throw ValidationException::withMessages(['staff' => 'This file has documents: delete them first, or change its status instead.']);
        }
        $staff->delete();
    }

    // the history of the Profile tab, newest first
    public function events(Staff $staff, int $limit = 30)
    {
        return $staff->events()->with('creatable.userable')->orderByDesc('id')->limit($limit)->get();
    }

    // ------------------------------------------------------------------ documents

    public function documents(Staff $staff)
    {
        return $staff->documents()->with('type', 'files', 'creatable.userable')->get()
            ->sortBy(fn ($document) => [$document->type?->sort_order ?? 999, $document->type?->id ?? 0, -$document->id])->values();
    }

    public function findDocument(Staff $staff, $documentId): EmployeeDocument
    {
        return $staff->documents()->with('type', 'files')->findOrFail($documentId);
    }

    // [id => name] of the active types (+ the document's current one)
    public function typeOptions($accountId, $currentId = 0): array
    {
        return DocumentType::where('account_id', $accountId)
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', (int) $currentId))
            ->ordered()->get()->mapWithKeys(fn ($type) => [$type->id => $type->name])->all();
    }

    // the type ids the document form shows its conditional fields for: expiry (has_expiry), name (Other), number (not National ID)
    public function formTypeIds($accountId): array
    {
        $types = DocumentType::where('account_id', $accountId)->get();
        return [
            'expiry' => $types->where('has_expiry', true)->pluck('id')->all(),
            'other' => $types->filter->isOther()->pluck('id')->all(),
            'number' => $types->reject->isNationalId()->pluck('id')->all(),
        ];
    }

    /**
     * $data: document_type_id, name (required for "Other"), number (ignored for the National ID), issue_date, expiry_date
     * (only for a type with an expiry), notes, files[] (UploadedFile, one or more).
     */
    public function createDocument(Staff $staff, array $data, Model $user): EmployeeDocument
    {
        $type = $this->assertType($staff->account_id, $data['document_type_id'], $data);
        return DB::transaction(function () use ($staff, $data, $user, $type) {
            $document = new EmployeeDocument($this->documentData($data, $type));
            $document->account_id = $staff->account_id;
            $document->employee_id = $staff->id;
            $document->creatable()->associate($user);
            $document->save();
            $added = $this->storeFiles($staff, $document, $data['files'] ?? [], $user);
            $this->event($staff, 'document_added', ['document' => [null, $this->documentLabel($document)]] + ($added ? ['files' => [null, implode(', ', $added)]] : []), null, $user);
            return $document;
        });
    }

    // $data['files'] = files to add, $data['remove_files'] = ids of its files to delete
    public function updateDocument(Staff $staff, $documentId, array $data, Model $user): EmployeeDocument
    {
        $document = $this->findDocument($staff, $documentId);
        $type = $this->assertType($staff->account_id, $data['document_type_id'], $data, (int) $document->document_type_id);
        return DB::transaction(function () use ($staff, $document, $data, $user, $type) {
            $document->fill($this->documentData($data, $type));
            $changes = $this->changes($document);
            $document->save();
            $removed = [];
            foreach ($document->files()->whereIn('id', (array) ($data['remove_files'] ?? []))->get() as $file) {
                Storage::disk('local')->delete($file->path($staff->id));
                $removed[] = $file->original_name;
                $file->delete();
            }
            $added = $this->storeFiles($staff, $document, $data['files'] ?? [], $user);
            if ($removed || $added) {
                $changes['files'] = [$removed ? 'removed: ' . implode(', ', $removed) : null, $added ? 'added: ' . implode(', ', $added) : null];
            }
            if ($changes) {
                $this->event($staff, 'document_updated', ['document' => $this->documentLabel($document)] + $changes, null, $user);
            }
            return $document;
        });
    }

    // soft delete of the document; its files are removed from the disk
    public function deleteDocument(Staff $staff, $documentId, Model $user): void
    {
        $document = $this->findDocument($staff, $documentId);
        DB::transaction(function () use ($staff, $document, $user) {
            $names = [];
            foreach ($document->files as $file) {
                Storage::disk('local')->delete($file->path($staff->id));
                $names[] = $file->original_name;
                $file->delete();
            }
            $document->delete();
            $this->event($staff, 'document_deleted', ['document' => [$this->documentLabel($document), null]] + ($names ? ['files' => [implode(', ', $names), null]] : []), null, $user);
        });
    }

    // one file of the document (404 for another document's file / a missing file on the disk): [file, path]
    public function documentFile(Staff $staff, EmployeeDocument $document, $fileId): array
    {
        $file = $document->files()->findOrFail($fileId);
        $path = $file->path($staff->id);
        abort_unless(Storage::disk('local')->exists($path), 404);
        return [$file, $path];
    }

    /**
     * The document warnings of the account's CURRENT staff (not resigned / terminated, not external), once per request:
     * ['staff' => [staff_id => ['expired' => [documents], 'expiring' => [documents], 'missing' => [types]]],
     *  'expired' => n, 'expiring' => n, 'missing' => n (files × types)]. Per staff and type only the document with the latest
     * expiry date counts (a document of that type without an expiry date = no warning); missing = a required, active type with
     * no document. A warning, never a block (docs/hr-plan.md §B).
     */
    public function alerts($accountId): array
    {
        if (isset(self::$alerts[$accountId])) {
            return self::$alerts[$accountId];
        }
        $today = today();
        // external people (not on payroll) are not counted
        $staffIds = Staff::where('account_id', $accountId)->where('is_external', false)->whereNotIn('status', config('hrmodule.ended_statuses'))->pluck('id');
        $types = DocumentType::where('account_id', $accountId)->ordered()->get()->keyBy('id');
        $required = $types->filter(fn ($type) => $type->is_active && $type->is_required && !$type->isOther());
        $documents = EmployeeDocument::where('account_id', $accountId)->whereIn('employee_id', $staffIds)->get()
            ->filter(fn ($document) => $types->has($document->document_type_id))
            ->each(fn ($document) => $document->setRelation('type', $types[$document->document_type_id]))
            ->groupBy('employee_id');

        $result = ['staff' => [], 'expired' => 0, 'expiring' => 0, 'missing' => 0];
        foreach ($staffIds as $staffId) {
            $byType = ($documents[$staffId] ?? collect())->groupBy('document_type_id');
            $alert = ['expired' => [], 'expiring' => [], 'missing' => []];
            foreach ($byType as $group) {
                if ($group->contains(fn ($document) => !$document->expiry_date)) {
                    continue;
                }
                $latest = $group->sortByDesc(fn ($document) => $document->expiry_date->format('Ymd') . str_pad($document->id, 10, '0', STR_PAD_LEFT))->first();
                if (in_array($state = $latest->state($today), ['expired', 'expiring'], true)) {
                    $alert[$state][] = $latest;
                }
            }
            foreach ($required as $type) {
                if (!$byType->has($type->id)) {
                    $alert['missing'][] = $type;
                }
            }
            if ($alert['expired'] || $alert['expiring'] || $alert['missing']) {
                $result['staff'][$staffId] = $alert;
                foreach (['expired', 'expiring', 'missing'] as $key) {
                    $result[$key] += count($alert[$key]);
                }
            }
        }

        return self::$alerts[$accountId] = $result;
    }

    // the sidebar badge: expired + expiring documents (missing ones are on the list, not in the badge)
    public function alertCount($accountId): int
    {
        // every account page asks (the sidebar): 0 until the HR migrations ran (the last one: employee_documents)
        if (!\Illuminate\Support\Facades\Schema::hasTable('employee_documents')) {
            return 0;
        }
        $alerts = $this->alerts($accountId);
        return $alerts['expired'] + $alerts['expiring'];
    }

    // the Staff list "Documents" filter: the ids of the files with that warning
    public function idsWithAlert($accountId, string $kind): array
    {
        return collect($this->alerts($accountId)['staff'])->filter(fn ($alert) => !empty($alert[$kind]))->keys()->all();
    }

    // ------------------------------------------------------------------ helpers

    // the fields of a file from the validated data; a field not sent (no hr.sensitive) keeps its value
    private function fileData(array $data, ?Staff $staff = null): array
    {
        $fields = array_intersect_key($data, array_flip((new Staff)->getFillable()));
        unset($fields['status'], $fields['status_date'], $fields['status_reason'], $fields['account_id'], $fields['employee_no']);
        foreach (['position_id', 'shift_id'] as $id) {
            if (array_key_exists($id, $fields)) {
                $fields[$id] = (int) ($fields[$id] ?: 0);
            }
        }
        // the fields of another pay method / a woman's military status are emptied
        $method = $fields['pay_method'] ?? $staff?->pay_method;
        if (array_key_exists('pay_method', $fields)) {
            if ($method !== 'bank') {
                $fields['bank_name'] = $fields['bank_account'] = null;
            }
            if ($method !== 'wallet') {
                $fields['wallet_number'] = null;
            }
        }
        if (array_key_exists('military_status', $fields) && ($fields['gender'] ?? $staff?->gender) !== 'male') {
            $fields['military_status'] = null;
        }
        return $fields;
    }

    // [field => [before, after]] of the dirty attributes (SECRET ones = "changed")
    private function changes(Model $model): array
    {
        $changes = [];
        foreach ($model->getDirty() as $field => $value) {
            if (in_array($field, ['updated_at', 'created_at'], true)) {
                continue;
            }
            $before = $model->getOriginal($field);
            $after = $model->getAttribute($field);
            $plain = fn ($value) => $value instanceof Carbon ? $value->format('d-m-Y') : (is_bool($value) ? (int) $value : $value);
            if ((string) $plain($before) === (string) $plain($after)) {
                continue;
            }
            $changes[$field] = in_array($field, Staff::SECRET, true) ? 'changed' : [$plain($before), $plain($after)];
        }
        return $changes;
    }

    private function event(Staff $staff, string $type, ?array $changes, ?string $reason, Model $user): void
    {
        $event = new EmployeeEvent(['account_id' => $staff->account_id, 'employee_id' => $staff->id, 'type' => $type, 'changes' => $changes, 'reason' => $reason]);
        $event->creatable()->associate($user);
        $event->save();
    }

    // the active type of the account (or the document's current one) + an "Other" document needs its name
    private function assertType($accountId, $typeId, array $data, int $currentId = 0): DocumentType
    {
        $type = DocumentType::where('account_id', $accountId)->whereKey($typeId)
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $currentId))->first();
        if (!$type) {
            throw ValidationException::withMessages(['document_type_id' => 'Choose one of your active document types.']);
        }
        if ($type->isOther() && blank($data['name'] ?? null)) {
            throw ValidationException::withMessages(['name' => 'Write the document name.']);
        }
        return $type;
    }

    private function documentData(array $data, DocumentType $type): array
    {
        return [
            'document_type_id' => $type->id,
            'name' => $type->isOther() ? trim((string) $data['name']) : null,
            // the National ID number lives in the profile (encrypted), never on the document
            'number' => $type->isNationalId() ? null : ($data['number'] ?? null),
            'issue_date' => $data['issue_date'] ?? null,
            'expiry_date' => $type->has_expiry ? ($data['expiry_date'] ?? null) : null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    // each upload → the private disk under a random name (uuid), its original name kept for the list / download; [names]
    private function storeFiles(Staff $staff, EmployeeDocument $document, array $files, Model $user): array
    {
        $names = [];
        foreach ($files as $upload) {
            if (!$upload instanceof UploadedFile) {
                continue;
            }
            $name = \Illuminate\Support\Str::uuid() . '.' . strtolower($upload->extension() ?: $upload->getClientOriginalExtension());
            Storage::disk('local')->putFileAs('hr/' . $staff->account_id . '/' . $staff->id, $upload, $name);
            $file = new EmployeeDocumentFile(['account_id' => $staff->account_id, 'employee_document_id' => $document->id, 'file' => $name,
                'original_name' => mb_substr($upload->getClientOriginalName(), 0, 255), 'size' => (int) $upload->getSize()]);
            $file->creatable()->associate($user);
            $file->save();
            $names[] = $file->original_name;
        }
        return $names;
    }

    private function documentLabel(EmployeeDocument $document): string
    {
        return trim($document->label . ($document->number ? ' ' . $document->number : ''));
    }
}
