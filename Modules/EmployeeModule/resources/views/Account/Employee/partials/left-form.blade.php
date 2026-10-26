{{--
    Mark Left / Undo Left of an employee (2026-10-26): left = leave date today (no more login); undo = no leave date.
    $menu: inside the row menu; $backToView: the employee view (answered with the view again).
--}}
@php($undo = $employee->hasLeft())
<form method="POST" action="{{ route('account.employees.' . ($undo ? 'left.undo' : 'left'), $employee->id) }}" class="{{ !empty($menu) ? '' : 'inline-form' }}" data-ajax data-row="{{ $employee->id }}"
    data-confirm="{{ $undo
        ? '"' . $employee->name . '" will be working again (no leave date): he can log in again.'
        : '"' . $employee->name . '" leaves today: he can not log in any more (logged out at his next click). His payments stay; an open shift can be closed on his behalf from Daily Summary.' }}"
    data-confirm-title="{{ $undo ? 'Undo left?' : 'Mark as left?' }}"
    data-confirm-button="{{ $undo ? 'Undo Left' : 'Mark Left' }}"
    data-confirm-variant="{{ $undo ? 'success' : 'danger' }}">
    @csrf
    @method('PATCH')
    @if (!empty($backToView))
        <input type="hidden" name="back_to_view" value="1">
    @endif
    @if (!empty($menu))
        <button type="submit" data-tw-dismiss="dropdown" class="{{ config('layoutmodule.menu.item') }}">
            <i data-lucide="{{ $undo ? 'undo-2' : 'log-out' }}" class="{{ config('layoutmodule.menu.icon') }}"></i> {{ $undo ? 'Undo Left' : 'Mark Left' }}
        </button>
    @else
        <button type="submit" class="btn btn-secondary"><i data-lucide="{{ $undo ? 'undo-2' : 'log-out' }}"></i> {{ $undo ? 'Undo Left' : 'Mark Left' }}</button>
    @endif
</form>
