@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
<a href="{{ route($area . '.members.show', $member->id) }}" data-modal class="font-medium">{{ $member->name }}</a>
{{-- the mobile under the name (2026-10-27: no Phone column, no email; the search still finds both) --}}
<div class="mt-0.5 whitespace-nowrap text-xs text-slate-500">{{ $member->phone }}</div>
