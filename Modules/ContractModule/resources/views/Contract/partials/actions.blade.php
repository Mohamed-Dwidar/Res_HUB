{{--
    The buttons of one contract (its view header; the reservation view's Contract block with $compact): PDF (AR / EN, new tab),
    Edit draft (a full page: its fields + its wording; owner + employees), Issue, Upload signed / Replace scan, the scan, Delete draft — by
    ContractService::can() for the logged in user. $languages (optional) = the PDF languages (default: Arabic only).
--}}
@php
    $area = request()->routeIs('employee.*') ? 'employee' : 'account';
    $contractService = app(\Modules\ContractModule\app\Services\ContractService::class);
    $user = Auth::user();
    $size = !empty($compact) ? 'btn-sm' : '';
@endphp
@foreach ($languages ?? ['ar'] as $lang)
    <a href="{{ route($area . '.contracts.pdf', ['id' => $contract->id, 'lang' => $lang]) }}" target="_blank" class="btn btn-secondary {{ $size }}"><i data-lucide="file-down"></i> PDF {{ strtoupper($lang) }}</a>
@endforeach
@if ($contractService->can($contract, 'edit', $user))
    <a href="{{ route($area . '.contracts.edit', $contract->id) }}" class="btn btn-secondary {{ $size }}" title="Its fields and its wording (a full page)"><i data-lucide="pencil"></i> Edit Draft</a>
@endif
@if ($contractService->can($contract, 'issue', $user))
    <form method="POST" action="{{ route($area . '.contracts.issue', $contract->id) }}" class="inline-form" data-ajax
        data-confirm="It gets its contract number and its text and values are kept as they are now: an issued contract is never edited. Check the PDF first."
        data-confirm-title="Issue this {{ $contract->isRenewal() ? 'renewal annex' : 'contract' }}?"
        data-confirm-button="Issue"
        data-confirm-variant="success">
        @csrf
        <button type="submit" class="btn btn-primary {{ $size }}"><i data-lucide="stamp"></i> Issue</button>
    </form>
@endif
@if ($contractService->can($contract, 'sign', $user))
    <a href="{{ route($area . '.contracts.signed', $contract->id) }}" data-modal class="btn btn-pay {{ $size }}"><i data-lucide="upload"></i> Upload Signed</a>
@elseif ($contractService->can($contract, 'replace_scan', $user))
    <a href="{{ route($area . '.contracts.signed', $contract->id) }}" data-modal class="btn btn-secondary {{ $size }}"><i data-lucide="upload"></i> Replace Scan</a>
@endif
@if ($contract->signed_file)
    <a href="{{ route($area . '.contracts.signed.file', $contract->id) }}" target="_blank" class="btn btn-secondary {{ $size }}"><i data-lucide="paperclip"></i> Signed Scan</a>
@endif
@if ($contractService->can($contract, 'delete', $user))
    <form method="POST" action="{{ route($area . '.contracts.destroy', $contract->id) }}" class="inline-form" data-ajax
        data-confirm="The draft will be deleted (no number was given)."
        data-confirm-title="Delete this draft?"
        data-confirm-button="Delete"
        data-confirm-variant="danger">
        @csrf
        @method('DELETE')
        <button type="submit" class="btn btn-secondary {{ $size }}" title="Delete the draft" aria-label="Delete the draft"><i data-lucide="trash-2"></i></button>
    </form>
@endif
