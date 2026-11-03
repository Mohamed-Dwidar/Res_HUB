{{-- what was done: an export = the page, format, rows and filters; a sensitive action = the page + its record; a failed login = the email typed --}}
@if ($log->event === 'login_failed' && $log->email)
    <span class="text-slate-500">Email typed: {{ $log->email }}</span>
@elseif ($log->page || $log->subject_type)
    <div>
        {{ $log->page }}
        @if ($log->subject_type) · {{ \Illuminate\Support\Str::headline($log->subject_type) }} #{{ $log->subject_id }} @endif
        @if ($log->format) · {{ strtoupper($log->format) }} @endif
        @if ($log->rows !== null) · {{ number_format($log->rows) }} rows @endif
    </div>
    @if (!empty($log->filters))
        <div class="text-xs text-slate-500">
            @foreach ($log->filters as $key => $value)
                {{ \Illuminate\Support\Str::humanize($key) }}: {{ is_array($value) ? implode(', ', $value) : $value }}@if (!$loop->last) · @endif
            @endforeach
        </div>
    @endif
@else
    <span class="text-slate-500">-</span>
@endif
