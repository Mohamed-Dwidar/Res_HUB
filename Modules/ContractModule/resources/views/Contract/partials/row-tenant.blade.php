{{-- The Contracts list "Tenant" cell: the company (company contract) or the member, + the member and phone under it --}}
<div class="font-medium" dir="auto">{{ $contract->party_name }}</div>
<div class="text-xs text-slate-500">
    @if ($contract->party_type === 'company' && $contract->member)<span dir="auto">{{ $contract->member->name }}</span> · @endif{{ $contract->member?->phone }}
</div>
