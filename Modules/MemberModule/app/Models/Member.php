<?php

namespace Modules\MemberModule\app\Models;

use App\Helpers\SafeMorphHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\AccountModule\app\Models\Account;
use Modules\CompanyModule\app\Models\Company;
use Modules\JobModule\app\Models\Job;
use Modules\PackageModule\app\Models\Package;
use Modules\ReservationModule\app\Models\Reservation;

/**
 * phone_key (2026-10-18): the phone compared for duplicates / searched (phoneKey()), kept in step with phone on every save;
 * NULL on a merged member (members:merge-duplicates) so the unique index (account_id, phone_key) ignores it.
 * Soft deletes = merged members only (merged_into = the member kept); the account's Delete stays a real delete (MemberService).
 */
class Member extends Model
{
    use SafeMorphHelper, SoftDeletes;

    // an Egyptian mobile in any form: 01xxxxxxxxx, 201xxxxxxxxx, +201xxxxxxxxx, 00201xxxxxxxxx → its 10 digits 1xxxxxxxxx
    const EGYPT_MOBILE_REGEX = '/^(?:0020|20|0)?(1[0125]\d{8})$/';

    protected $fillable = [
        'account_id', 'name', 'company_id', 'job_id', 'phone', 'email', 'national_number', 'notes', 'from_where', 'created_by',
    ];

    protected static function booted(): void
    {
        static::saving(function (Member $member) {
            if ($member->isDirty('phone') || $member->phone_key === null && !$member->merged_into) {
                $member->phone_key = self::phoneKey($member->phone);
            }
        });
    }

    /**
     * The phone as compared / searched: digits only; an Egyptian mobile → its 10 digits (so 0122…, 20122…, +20122…, 0020122…
     * are one number); any other number keeps all its digits (a foreign number never matches an Egyptian one by chance).
     * A phone with several numbers ("0102… / 0155…", separated by / , ; |) = the first one (2026-10-26: the column is 20 long).
     */
    public static function phoneKey(?string $phone): string
    {
        $digits = preg_replace('/\D/', '', preg_split('/[\/,;|]/', (string) $phone)[0]);
        return preg_match(self::EGYPT_MOBILE_REGEX, $digits, $match) ? $match[1] : $digits;
    }

    protected $casts = [
        'national_number' => 'integer',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    // still shows the name when the company / job was deleted later
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class)->withTrashed();
    }

    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    // who added the member (users / admins)
    public function creatable(): MorphTo
    {
        return $this->morphTo();
    }

    // "0128 898 9336" => "01288989336" (used when saving and when searching)
    public static function cleanPhone(?string $phone): ?string
    {
        return $phone === null ? null : preg_replace('/\s+/u', '', $phone);
    }

    public function scopeFilter($query, array $filters = [])
    {
        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            // phones are saved without spaces, so search them without spaces too
            $phoneSearch = '%' . self::cleanPhone($filters['search']) . '%';
            $query->where(function ($query) use ($search, $phoneSearch) {
                $query->where('name', 'like', $search)
                    ->orWhere('phone', 'like', $phoneSearch)
                    ->orWhere('email', 'like', $search)
                    ->orWhere('national_number', 'like', $search);
            });
        }

        if (!empty($filters['company_id'])) {
            $query->where('company_id', $filters['company_id']);
        }

        if (!empty($filters['job_id'])) {
            $query->where('job_id', $filters['job_id']);
        }

        return $query;
    }
}
