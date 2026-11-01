<!DOCTYPE html>
<html class="theme-6" lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }} - {!! trim(strip_tags($__env->yieldContent('title'))) !!}</title>

    <link rel="icon" href="{{ asset('assets/images/favicon.jpg') }}" type="image/png">
    <link rel="stylesheet" href="{{ asset('assets/css/vendors/simplebar.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/vendors/zoom-vanilla.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/vendors/tom-select.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/themes/dagger.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/app.css') }}">
    {{-- flatpickr (MIT, local): the list date filters, the Schedule date, every date field (dd/mm/yyyy, custom.js dateFields()) --}}
    <link rel="stylesheet" href="{{ asset('assets/vendor/flatpickr/flatpickr.min.css') }}">
    {{-- page vendor styles (e.g. DataTables) before custom.css, so custom.css can override them --}}
    @stack('styles')
    <link rel="stylesheet" href="{{ asset('assets/css/custom.css') }}?v={{ filemtime(public_path('assets/css/custom.css')) }}">
    @yield('head')
</head>

<body>
    <div class="dagger before:content-[''] before:bg-gradient-to-b before:from-slate-100 before:to-slate-50 before:fixed before:inset-0">
        <div class="h-screen relative loading-page loading-page--before-hide [&.loading-page--before-hide]:before:block [&.loading-page--hide]:before:opacity-0 before:content-[''] before:transition-opacity before:duration-300 before:hidden before:inset-0 before:h-screen before:w-screen before:fixed before:bg-gradient-to-b before:from-theme-1 before:to-theme-2 before:z-[60] [&.loading-page--before-hide]:after:block [&.loading-page--hide]:after:opacity-0 after:content-[''] after:transition-opacity after:duration-300 after:hidden after:h-16 after:w-16 after:animate-pulse after:fixed after:opacity-50 after:inset-0 after:m-auto after:bg-loading-puff after:bg-cover after:z-[61]">
            <div class="fixed top-0 left-0 z-50 h-screen side-menu group">
                @yield('header')
                @yield('sidebar')
            </div>

            <div class="content transition-[margin,width] duration-100 px-5 mt-[65px] pt-[31px] pb-16 relative z-10 xl:ml-[275px] [&.content--compact]:xl:ml-[91px]">
                <div class="grid grid-cols-12 gap-x-6 gap-y-10">
                    <div class="col-span-12">
                        <div class="flex flex-col gap-y-3 md:h-10 md:flex-row md:items-center">
                            <div class="text-base font-medium">
                                @yield('title')
                            </div>
                            <div class="flex flex-wrap gap-2 md:ml-auto">
                                @yield('actions')
                            </div>
                        </div>

                        @include('layoutmodule::flash')

                        <div class="box box--stacked mt-3.5 flex flex-col p-5">
                            @yield('content')
                        </div>

                        @include('layoutmodule::footer')
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- the popup for view / add / edit (data-modal links, see custom.js), the content comes from layoutmodule::modal --}}
    <div id="app-modal" data-tw-backdrop="" aria-hidden="true" tabindex="-1" class="modal group bg-gradient-to-b from-theme-1/50 via-theme-2/50 to-black/50 transition-[visibility,opacity] w-screen h-screen fixed left-0 top-0 [&:not(.show)]:duration-[0s,0.2s] [&:not(.show)]:delay-[0.2s,0s] [&:not(.show)]:invisible [&:not(.show)]:opacity-0 [&.show]:visible [&.show]:opacity-100 [&.show]:duration-[0s,0.4s]">
        <div data-tw-merge="" class="app-modal__dialog w-[90%] mx-auto bg-white relative rounded-md shadow-md transition-[margin-top,transform] duration-[0.4s,0.3s] -mt-16 group-[.show]:mt-16 group-[.modal-static]:scale-[1.05] dark:bg-darkmode-600">
            <div class="app-modal__body"></div>
        </div>
    </div>
    {{-- validation message under a field (ajax forms) --}}
    <template id="field-error-template">
        <div class="{{ config('layoutmodule.form.error') }}" data-ajax-error></div>
    </template>

    <script src="{{ asset('assets/js/vendors/dom.js') }}"></script>
    <script src="{{ asset('assets/js/vendors/tailwind-merge.js') }}"></script>
    <script src="{{ asset('assets/js/vendors/lucide.js') }}"></script>
    <script src="{{ asset('assets/js/vendors/popper.js') }}"></script>
    <script src="{{ asset('assets/js/vendors/dropdown.js') }}"></script>
    <script src="{{ asset('assets/js/vendors/transition.js') }}"></script>
    <script src="{{ asset('assets/js/vendors/simplebar.js') }}"></script>
    <script src="{{ asset('assets/js/vendors/image-zoom.js') }}"></script>
    <script src="{{ asset('assets/js/vendors/modal.js') }}"></script>
    <script src="{{ asset('assets/js/vendors/tom-select.js') }}"></script>
    <script src="{{ asset('assets/js/components/base/lucide.js') }}"></script>
    <script src="{{ asset('assets/vendor/flatpickr/flatpickr.min.js') }}"></script>
    <script src="{{ asset('assets/vendor/flatpickr/l10n/ar.min.js') }}"></script>
    {{-- ?v= file time: the browser loads the new file after every change --}}
    <script src="{{ asset('assets/js/custom.js') }}?v={{ filemtime(public_path('assets/js/custom.js')) }}"></script>
    <script src="{{ asset('assets/js/themes/dagger.js') }}"></script>
    {{-- SweetAlert2 11.26.25 (MIT), local copy: no CDN --}}
    <script src="{{ asset('assets/vendor/sweetalert2/sweetalert2.all.min.js') }}"></script>
    @stack('scripts')
</body>

</html>
