<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Document Compliance Checker' }} — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-50 text-slate-900 antialiased">
<div class="min-h-full">

    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3 sm:px-6 lg:px-8">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
                <span class="rounded bg-slate-900 px-2 py-1 text-xs font-semibold tracking-wide text-white">DCC</span>
                <span class="text-sm font-semibold">Document Compliance Checker</span>
            </a>

            <nav class="hidden items-center gap-1 md:flex" aria-label="Main">
                @php
                    $navItems = [
                        ['route' => 'dashboard', 'label' => 'Dashboard', 'can' => null],
                        ['route' => 'documents.index', 'label' => 'Documents', 'can' => null],
                        ['route' => 'documents.upload', 'label' => 'Upload', 'can' => 'upload-document'],
                        ['route' => 'sources.index', 'label' => 'Sources', 'can' => 'manage-sources'],
                        ['route' => 'settings.index', 'label' => 'Settings', 'can' => 'manage-sources'],
                        ['route' => 'audit.index', 'label' => 'Audit Log', 'can' => 'view-audit-log'],
                    ];
                @endphp

                @foreach ($navItems as $item)
                    @if ($item['can'] === null || Gate::allows($item['can']))
                        @php $active = request()->routeIs($item['route']); @endphp
                        <a href="{{ route($item['route']) }}"
                           @if ($active) aria-current="page" @endif
                           class="rounded px-3 py-2 text-sm font-medium {{ $active ? 'bg-slate-100 text-slate-900' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}">
                            {{ $item['label'] }}
                        </a>
                    @endif
                @endforeach
            </nav>

            <div class="flex items-center gap-3">
                <div class="hidden text-right sm:block">
                    <div class="text-sm font-medium leading-tight">{{ auth()->user()->name }}</div>
                    <div class="text-xs text-slate-500">{{ auth()->user()->role->label() }}</div>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                            class="rounded border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">
                        Sign out
                    </button>
                </form>
            </div>
        </div>

        {{-- Same links again for narrow screens; a Document Controller on a
             tablet on the shop floor is a real user here. --}}
        <nav class="flex gap-1 overflow-x-auto border-t border-slate-200 px-4 py-2 md:hidden" aria-label="Main (compact)">
            @foreach ($navItems as $item)
                @if ($item['can'] === null || Gate::allows($item['can']))
                    <a href="{{ route($item['route']) }}"
                       class="whitespace-nowrap rounded px-3 py-1.5 text-sm {{ request()->routeIs($item['route']) ? 'bg-slate-100 font-medium text-slate-900' : 'text-slate-600' }}">
                        {{ $item['label'] }}
                    </a>
                @endif
            @endforeach
        </nav>
    </header>

    <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        @if (session('status'))
            <div role="status"
                 class="mb-4 rounded border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('status') }}
            </div>
        @endif

        @if (session('error'))
            <div role="alert"
                 class="mb-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                {{ session('error') }}
            </div>
        @endif

        {{ $slot }}
    </main>

    <footer class="mx-auto max-w-7xl px-4 py-6 text-xs text-slate-400 sm:px-6 lg:px-8">
        {{ config('app.name') }} — internal use only.
    </footer>
</div>
</body>
</html>
