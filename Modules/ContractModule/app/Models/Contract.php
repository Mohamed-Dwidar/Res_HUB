<?php

namespace Modules\ContractModule\app\Models;

use App\Helpers\SafeMorphHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\CompanyModule\app\Models\Company;
use Modules\MemberModule\app\Models\Member;
use Modules\ReservationModule\app\Models\Reservation;
use Modules\SpaceModule\app\Models\Space;
use Modules\UnitModule\app\Models\Unit;

/**
 * A contract (ContractModule Phase 2, docs/contracts-plan.md): one per TERM (starts_on → ends_on) of a member's unit, made by hand
 * from a reservation; kind renewal = a renewal annex of renewal_of. A draft is edited; issued = contract_no + the snapshot (title,
 * body_ar / body_en rendered, values), never edited again. All the rules are in ContractService.
 */
class Contract extends Model
{
    use SafeMorphHelper, SoftDeletes;

    const STATUSES = ['draft' => 'Draft', 'issued' => 'Issued', 'signed' => 'Signed', 'ended' => 'Ended', 'terminated' => 'Terminated', 'cancelled' => 'Cancelled'];

    // the badge colours (custom.css badge-status-*)
    const STATUS_COLORS = ['draft' => 'pending', 'issued' => 'primary', 'signed' => 'success', 'ended' => 'slate', 'terminated' => 'slate', 'cancelled' => 'danger'];

    const PARTY_TYPES = ['individual' => 'Individual (the member)', 'company' => 'Company (the member\'s company)'];

    // the Contracts list "Ending" filter
    const ENDING_FILTERS = ['soon' => 'Ending soon', 'ended' => 'Ended, not renewed'];

    protected $fillable = [
        'account_id', 'kind', 'renewal_of', 'contract_template_id', 'template_name', 'reservation_id', 'member_id', 'company_id',
        'party_type', 'space_id', 'unit_id', 'starts_on', 'ends_on', 'term_months', 'amount', 'term_amount', 'deposit_amount',
        'activity', 'title_ar', 'title_en', 'body_ar', 'body_en', 'notes',
        'unit_number', 'contract_date', 'duration_text', 'currency', 'tenant_name', 'tenant_representative', 'tenant_company', 'tenant_title',
        'tenant_national_id', 'tenant_mobile', 'tenant_email', 'unit_address', 'tenant_commercial_register', 'tenant_tax_number',
        'lessor_name', 'lessor_commercial_register', 'lessor_tax_number', 'lessor_representative', 'lessor_representative_title',
        'lessor_representative_national_id',
    ];

    // the lessor as printed on THIS contract (2026-11-25): NULL = never set (Settings › Contracts), '' = emptied here
    const LESSOR_FIELDS = ['lessor_name', 'lessor_commercial_register', 'lessor_tax_number', 'lessor_representative', 'lessor_representative_title', 'lessor_representative_national_id'];

    // the contract's own data (2026-11-01): pre-filled from the reservation / member / company / settings, edited per contract;
    // what the PDF and the placeholders print (never written back to the member / company unless asked)
    const OWN_FIELDS = ['unit_number', 'contract_date', 'duration_text', 'term_amount', 'currency', 'tenant_name', 'tenant_representative',
        'tenant_company', 'tenant_title', 'tenant_national_id', 'tenant_mobile', 'tenant_email', 'unit_address', 'activity', 'deposit_amount', 'notes',
        'tenant_commercial_register', 'tenant_tax_number', 'lessor_name', 'lessor_commercial_register', 'lessor_tax_number',
        'lessor_representative', 'lessor_representative_title', 'lessor_representative_national_id'];

    // required on a draft and to issue it (2026-11-25, user): everything except the tenant's email and title, the deposit and the notes
    // (the English text is optional too); COMPANY_FIELDS only for a company
    const REQUIRED_FIELDS = [
        'unit_number' => 'unit number', 'contract_date' => 'contract date', 'duration_text' => 'contract duration', 'term_amount' => 'contract value',
        'currency' => 'currency', 'lessor_name' => 'lessor name', 'lessor_commercial_register' => 'lessor commercial register',
        'lessor_tax_number' => 'lessor tax registration no.', 'lessor_representative' => 'lessor legal representative',
        'lessor_representative_title' => 'lessor representative\'s title', 'lessor_representative_national_id' => 'lessor representative\'s national ID',
        'tenant_name' => 'tenant name', 'tenant_representative' => 'legal representative', 'tenant_national_id' => 'national ID',
        'tenant_mobile' => 'mobile', 'unit_address' => 'unit address', 'activity' => 'activity',
    ];

    const COMPANY_FIELDS = ['tenant_commercial_register' => 'commercial register', 'tenant_tax_number' => 'tax card no.'];

    protected $casts = [
        'contract_no' => 'integer',
        'renewal_of' => 'integer',
        'reservation_id' => 'integer',
        'member_id' => 'integer',
        'unit_id' => 'integer',
        'starts_on' => 'date',
        'contract_date' => 'date',
        'ends_on' => 'date',
        'term_months' => 'integer',
        'amount' => 'float',
        'term_amount' => 'float',
        'deposit_amount' => 'float',
        'values' => 'array',
        'issued_at' => 'datetime',
        'signed_on' => 'date',
        'ended_on' => 'date',
        'cancelled_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class)->withTrashed();
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class)->withTrashed();
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class)->withTrashed();
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ContractTemplate::class, 'contract_template_id')->withTrashed();
    }

    // the contract this renewal annex renews
    public function previous(): BelongsTo
    {
        return $this->belongsTo(self::class, 'renewal_of');
    }

    // its renewal annexes (not cancelled = "Renewed")
    public function renewals(): HasMany
    {
        return $this->hasMany(self::class, 'renewal_of')->where('status', '!=', 'cancelled');
    }

    public function creatable(): MorphTo
    {
        return $this->morphTo();
    }

    // its history, newest first (Phase 3, 2026-11-25)
    public function events(): HasMany
    {
        return $this->hasMany(ContractEvent::class)->latest('id');
    }

    // the last day it covers: an ended contract stops on its end date
    public function getCoversUntilAttribute()
    {
        return $this->ended_on && $this->ended_on->lt($this->ends_on) ? $this->ended_on : $this->ends_on;
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isRenewal(): bool
    {
        return $this->kind === 'renewal';
    }

    // "Contract" / "Annex"
    public function getKindLabelAttribute(): string
    {
        return $this->isRenewal() ? 'Renewal annex' : 'Contract';
    }

    // "#251" once issued, "Draft" before
    public function getNumberLabelAttribute(): string
    {
        return $this->contract_no ? '#' . $this->contract_no : 'Draft';
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function getStatusColorAttribute(): string
    {
        return self::STATUS_COLORS[$this->status] ?? 'slate';
    }

    // the party as shown in lists / views: the contract's company name (company contract) else its tenant name (the member's
    // name when empty)
    public function getPartyNameAttribute(): string
    {
        if ($this->party_type === 'company' && $this->tenant_company) {
            return (string) $this->tenant_company;
        }
        return (string) ($this->tenant_name ?: ($this->member?->name ?? '-'));
    }

    public function scopeFilter($query, array $filters)
    {
        if (($search = trim((string) ($filters['search'] ?? ''))) !== '') {
            $like = '%' . $search . '%';
            $query->where(function ($query) use ($search, $like) {
                $query->where('contracts.tenant_name', 'like', $like)->orWhere('contracts.tenant_company', 'like', $like)
                    ->orWhere('contracts.tenant_mobile', 'like', $like)->orWhere('contracts.unit_number', 'like', $like)
                    ->orWhereIn('contracts.member_id', Member::select('id')->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('phone', 'like', $like)))
                    ->orWhereIn('contracts.company_id', Company::select('id')->where(fn ($q) => $q->where('name_ar', 'like', $like)->orWhere('name_en', 'like', $like)));
                if (ctype_digit(ltrim($search, '#'))) {
                    $query->orWhere('contracts.contract_no', (int) ltrim($search, '#'));
                }
            });
        }
        foreach (['status', 'space_id', 'unit_id'] as $field) {
            if (!empty($filters[$field])) {
                $query->where('contracts.' . $field, $filters[$field]);
            }
        }
        // the term overlaps the picked days
        if (!empty($filters['date_from'])) {
            $query->where('contracts.ends_on', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('contracts.starts_on', '<=', $filters['date_to']);
        }
        return $query;
    }
}
