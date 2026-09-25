<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name')) · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-900 antialiased">
    <div class="min-h-screen flex flex-col">

        @if (\App\Support\UpstreamSession::authenticated())
        <header class="bg-slate-900 text-slate-100 shadow">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3">
                <div class="flex items-center gap-8">
                    <a href="{{ route('dashboard') }}" class="text-lg font-semibold tracking-tight">
                        {{ config('app.name') }}
                    </a>
                    <nav class="flex items-center gap-6 text-sm">
                        <a href="{{ route('dashboard') }}" class="font-medium text-slate-200 hover:text-white">
                            Dashboard
                        </a>
                        <a href="{{ route('documents.index') }}" class="font-medium text-slate-200 hover:text-white">
                            Documents
                        </a>
                        <a href="{{ route('classification.index') }}" class="font-medium text-slate-200 hover:text-white">
                            Inquiry classification
                        </a>
                        <a href="{{ route('sectors.index') }}" class="font-medium text-slate-200 hover:text-white">
                            Industry sectors
                        </a>
                    </nav>
                </div>
                <div class="flex items-center gap-4 text-sm">
                    <span class="text-slate-300">
                        {{ \App\Support\UpstreamSession::username() ?? '' }}
                    </span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="rounded-md bg-slate-700 px-3 py-1.5 text-sm font-medium hover:bg-slate-600">
                            Sign out
                        </button>
                    </form>
                </div>
            </div>
        </header>
        @endif

        <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-8">
            @yield('content')
        </main>
    </div>
    @livewireScripts
</body>
</html>