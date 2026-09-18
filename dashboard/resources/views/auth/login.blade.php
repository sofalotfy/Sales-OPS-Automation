@extends('layouts.app')

@section('title', 'Sign in')

@section('content')
    <div class="mx-auto mt-16 w-full max-w-sm">
        <div class="rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
            <div class="mb-6 text-center">
                <h1 class="text-xl font-semibold text-slate-900">{{ config('app.name') }}</h1>
                <p class="mt-1 text-sm text-slate-500">Sign in to manage the RAG document corpus</p>
            </div>

            <form method="POST" action="{{ route('login') }}" class="space-y-4">
                @csrf

                <div>
                    <label for="username" class="block text-sm font-medium text-slate-700">Username</label>
                    <input
                        id="username"
                        type="text"
                        name="username"
                        value="{{ old('username') }}"
                        required
                        autofocus
                        autocomplete="username"
                        class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-sky-500 focus:outline-none focus:ring-sky-500"
                    >
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-slate-700">Password</label>
                    <input
                        id="password"
                        type="password"
                        name="password"
                        required
                        autocomplete="current-password"
                        class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-sky-500 focus:outline-none focus:ring-sky-500"
                    >
                </div>

                <x-upstream-error message="{{ $errors->first('username') }}" />

                <button type="submit" class="w-full rounded-md bg-sky-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-sky-500">
                    Sign in
                </button>
            </form>
        </div>
    </div>
@endsection