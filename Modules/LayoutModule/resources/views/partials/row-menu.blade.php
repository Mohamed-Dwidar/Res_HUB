{{--
    Row actions as a "more" drop menu (template viper-users.html).
    @include('layoutmodule::partials.row-menu', [
        'links' => [['label' => 'View', 'icon' => 'eye', 'url' => route(...), 'modal' => true], ...],   (modal: open it in the popup;
                    blank: open it in a new tab, e.g. a PDF — 2026-10-31)
        'actions' => [['view' => 'module::...delete-form', 'data' => ['model' => $model], 'can' => 'x.delete'], ...],   (rendered with
                    'menu' => true; can: shown only with that permission)
    ])
    Roles (docs/roles-plan.md, R0): a link an employee's role can't open (its route's can: permission) is left out
    (RoleService::allowsUrl(); the owner sees every link).
--}}
@php
    $roleService = app(\Modules\EmployeeModule\app\Services\RoleService::class);
    $links = array_values(array_filter($links ?? [], fn ($link) => $roleService->allowsUrl($link['url'])));
    $actions = array_values(array_filter($actions ?? [], fn ($action) => empty($action['can']) || Gate::allows($action['can'])));
@endphp
<div class="flex items-center justify-end">
    <div data-tw-merge="" data-tw-placement="bottom-end" class="dropdown relative h-5">
        <button type="button" data-tw-toggle="dropdown" aria-expanded="false" class="cursor-pointer h-5 w-5 text-slate-500" title="Actions">
            <i data-tw-merge="" data-lucide="more-vertical" class="stroke-[1] w-5 h-5 fill-slate-400/70 stroke-slate-400/70"></i>
        </button>
        {{-- no "hidden" / data-transition: the rows are added by DataTables after transition.js runs, custom.css hides it when closed --}}
        <div class="dropdown-menu js-menu absolute z-[9999]">
            <div data-tw-merge="" class="dropdown-content rounded-md border-transparent bg-white p-2 shadow-[0px_3px_10px_#00000017] dark:border-transparent dark:bg-darkmode-600 w-44">
                @foreach ($links ?? [] as $link)
                    <a href="{{ $link['url'] }}" @if (!empty($link['modal'])) data-modal @endif @if (!empty($link['blank'])) target="_blank" rel="noopener" @endif data-tw-dismiss="dropdown" class="{{ config('layoutmodule.menu.item') }}">
                        <i data-tw-merge="" data-lucide="{{ $link['icon'] }}" class="{{ config('layoutmodule.menu.icon') }}"></i>
                        {{ $link['label'] }}
                    </a>
                @endforeach
                @if (!empty($links) && !empty($actions))
                    <div class="h-px my-2 -mx-2 bg-slate-200/60"></div>
                @endif
                @foreach ($actions ?? [] as $action)
                    @include($action['view'], ($action['data'] ?? []) + ['menu' => true])
                @endforeach
            </div>
        </div>
    </div>
</div>
