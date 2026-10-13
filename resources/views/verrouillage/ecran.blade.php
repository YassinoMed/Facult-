@extends('layouts.app')

@section('title', 'Session verrouillée')

@php($sansSurveillance = true)

@section('content')
<div class="mx-auto max-w-md px-4 sm:px-6 py-14">
    <div class="ds-card p-6 sm:p-8 text-center">
        <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-900 text-white" aria-hidden="true">
            <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
        </div>
        <h1 class="mt-4 text-xl font-black text-slate-900">Session verrouillée</h1>
        <p class="mt-1 text-sm text-slate-500">Inactivité prolongée — {{ $identite }}. Votre travail est conservé côté serveur.</p>
        <form method="POST" action="{{ route('admin.verrouillage.verifier') }}" class="mt-5 space-y-4 text-left">
            @csrf
            <div>
                <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500" for="verrou-code">Code de sécurité *</label>
                <input id="verrou-code" name="code" required inputmode="numeric" autocomplete="off" autofocus
                       maxlength="8" minlength="6"
                       class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-center text-lg font-mono tracking-[0.3em]" />
                @error('code')<p class="mt-1 text-xs text-rose-600" role="alert">{{ $message }}</p>@enderror
            </div>
            <x-btn type="submit" class="w-full">Déverrouiller</x-btn>
        </form>
        <form method="POST" action="{{ route('admin.verrouillage.deconnecter') }}" class="mt-3">
            @csrf
            <button type="submit" class="text-xs font-bold text-slate-500 underline underline-offset-2">Se déconnecter</button>
        </form>
    </div>
</div>
@endsection
