{{-- the member's name opening the member's view in the popup (a Cancel there goes back: the popup back history);
     $member (null = "-"), optional $class (text classes of the name) --}}
@if ($member)
    <a href="{{ route((request()->routeIs('employee.*') ? 'employee' : 'account') . '.members.show', $member->id) }}" data-modal
        class="member-link {{ $class ?? '' }}" title="Member details">{{ $member->name }}</a>
@else
    <span class="{{ $class ?? '' }}">-</span>
@endif
