{{-- a small icon button in the extras block of the package / reservation view --}}
<form method="POST" action="{{ route('account.extras.destroy', $extra->id) }}" class="inline-form" data-ajax
    data-confirm="&quot;{{ $extra->item }}&quot; ({{ number_format($extra->amount, 2) }}) will be removed from the {{ \Modules\PaymentModule\app\Services\ExtraService::kindOf($extra->extrable) }}."
    data-confirm-title="Delete this {{ strtolower($extra->type_label) }}?"
    data-confirm-button="Delete"
    data-confirm-variant="danger">
    @csrf
    @method('DELETE')
    <button type="submit" class="text-danger" title="Delete"><i data-lucide="trash-2" class="h-4 w-4"></i></button>
</form>
