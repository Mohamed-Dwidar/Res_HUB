<div class="whitespace-nowrap">{{ $log->ip ?: '-' }}</div>
@if ($log->user_agent)
    <div class="access-log-agent text-xs text-slate-500" title="{{ $log->user_agent }}">{{ $log->user_agent }}</div>
@endif
