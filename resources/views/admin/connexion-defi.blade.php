@extends('layouts.app')

@section('title', 'Vérification en deux étapes')

@section('content')
<div class="mx-auto max-w-md px-4 sm:px-6 py-14">
    <div class="ds-card p-6 sm:p-8">
        <h1 class="text-xl font-black text-slate-900">Vérification en deux étapes</h1>
        <p class="mt-1 text-sm text-slate-500">Saisissez le code à 6 chiffres de votre application d'authentification.</p>
        <form method="POST" action="{{ route('admin.connexion.defi.verifier') }}" class="mt-5 space-y-4">
            @csrf
            <div>
                <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500" for="defi-code">Code *</label>
                <input id="defi-code" name="code" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" autofocus
                       class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm font-mono" />
                @error('code')<p class="mt-1 text-xs text-rose-600" role="alert">{{ $message }}</p>@enderror
            </div>
            <x-btn type="submit" class="w-full">Vérifier</x-btn>
        </form>
    </div>
</div>
@endsection
