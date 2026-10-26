{{--
    Mark a not renewed subscription period "left" / undo it ($reservation; $undo = the undo form).
    $backToView: answered with the view again (popup); $class: the button classes (default a small secondary button).
--}}
@php($area = request()->routeIs('employee.*') ? 'employee' : 'account')
<form method="POST" action="{{ route($area . '.reservations.' . (!empty($undo) ? 'left.undo' : 'left'), $reservation->id) }}" class="inline-form" data-ajax data-row="{{ $reservation->id }}"
    data-confirm="{{ !empty($undo) ? 'The period of' : 'The member' }} &quot;{{ $reservation->member?->name }}&quot; ({{ $reservation->unit?->name ?? $reservation->space?->name }}) {{ !empty($undo) ? 'will be back on the Not renewed list.' : 'did not renew: the period leaves the renewals lists (it can be undone from the reservation).' }}"
    data-confirm-title="{{ !empty($undo) ? 'Undo left?' : 'Mark as left?' }}"
    data-confirm-button="{{ !empty($undo) ? 'Undo' : 'Mark Left' }}"
    data-confirm-variant="{{ !empty($undo) ? 'success' : 'danger' }}">
    @csrf
    @method('PATCH')
    @if (!empty($backToView))
        <input type="hidden" name="back_to_view" value="1">
    @endif
    <button type="submit" class="{{ $class ?? 'btn btn-secondary btn-sm' }}">
        <i data-lucide="{{ !empty($undo) ? 'undo-2' : 'log-out' }}"></i> {{ !empty($undo) ? 'Undo Left' : 'Mark Left' }}
    </button>
</form>
