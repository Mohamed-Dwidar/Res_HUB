<?php

namespace Modules\MemberModule\app\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\MemberModule\app\Models\Member;
use Modules\MemberModule\app\Repositories\MemberRepository;

/**
 * Every method takes the owner account id, so an account can only reach its own members.
 */
class MemberService
{
    private $memberRepository;

    public function __construct(MemberRepository $memberRepository)
    {
        $this->memberRepository = $memberRepository;
    }

    // members list query (paged / ordered by DataTables)
    public function listQuery($accountId, array $filters = [])
    {
        return $this->memberRepository->forAccount($accountId)->filter($filters);
    }

    /**
     * The Member drop menus' server search (2026-10-18, {area}.members.search): name contains $q, or the phone by phone_key —
     * an Egyptian mobile typed from its start (01…, 201…, +201…, 00201…) = a phone_key prefix (index), other digits = anywhere.
     * [{id, text: "name phone"}], at most $limit, by name.
     */
    public function search($accountId, string $q, int $limit = 20): array
    {
        $q = trim($q);
        $digits = preg_replace('/\D/', '', $q);
        $query = Member::where('account_id', $accountId)->where(function ($query) use ($q, $digits) {
            $query->where('name', 'like', '%' . $q . '%');
            if (strlen($digits) >= 3) {
                // a number typed from its start: 01 / 201 / 00201 then the mobile's digits = the start of its phone_key
                if (preg_match('/^(?:0020|20|0)(1\d*)$/', $digits, $match)) {
                    $query->orWhere('phone_key', 'like', $match[1] . '%');
                } else {
                    $query->orWhere('phone_key', 'like', '%' . $digits . '%');
                }
            }
        });

        return $query->orderBy('name')->limit($limit)->get(['id', 'name', 'phone'])
            ->map(fn ($member) => ['id' => $member->id, 'text' => $member->name . ' ' . $member->phone])->all();
    }

    // the member with this phone (by phone_key) in the account, or null: the quick add duplicate check
    public function findByPhone($accountId, ?string $phone): ?Member
    {
        $key = Member::phoneKey($phone);
        return $key === '' ? null : Member::where('account_id', $accountId)->where('phone_key', $key)->first();
    }

    // 404 when the member belongs to another account
    public function findOne($accountId, $id)
    {
        return $this->memberRepository->forAccount($accountId)->findOrFail($id);
    }

    // $creator: the logged in user who adds the member
    public function create($accountId, array $data, Model $creator)
    {
        $member = $this->memberRepository->makeModel()->newInstance($this->memberData($data) + [
            'account_id' => $accountId,
            'created_by' => $creator->getKey(),
        ]);
        $member->creatable()->associate($creator);
        $member->save();

        return $member;
    }

    public function update($accountId, $id, array $data)
    {
        $member = $this->findOne($accountId, $id);
        $member->update($this->memberData($data));
        return $member;
    }

    // a real delete as before (soft deletes are for merged members only), so the phone can be added again
    public function deleteOne($accountId, $id)
    {
        return $this->findOne($accountId, $id)->forceDelete();
    }

    private function memberData(array $data): array
    {
        return [
            // saved humanized: "ahmed_ali" / "ahmed ali" => "Ahmed Ali"
            'name' => Str::humanize($data['name']),
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'national_number' => $data['national_number'] ?? null,
            // the columns are not nullable: 0 = none
            'company_id' => $data['company_id'] ?? 0,
            'job_id' => $data['job_id'] ?? 0,
            'from_where' => $data['from_where'] ?? null,
            'notes' => $data['notes'] ?? null,
            // WhatsApp language (MessagingModule): empty = the account's default; the quick add has no such field
            'message_language' => ($data['message_language'] ?? null) ?: null,
        ];
    }
}
