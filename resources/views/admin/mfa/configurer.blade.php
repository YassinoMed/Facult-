@extends('layouts.app')

@section('title', 'Double facteur')

@section('content')
<div class="mx-auto max-w-2xl px-4 sm:px-6 py-8">
    <x-page-header titre="Double facteur (TOTP)" description="Application d'authentification requise."
        :fil="['Double facteur' => '#']" />

    @if(session('success'))
        <div class="mt-4"><x-alert type="success">{{ session('success') }}</x-alert></div>
    @endif

    @if($actif)
        <div class="mt-4 ds-card p-5">
            <p class="text-sm font-bold text-emerald-700">Protection active sur ce compte.</p>
            <form method="POST" action="{{ route('admin.mfa.desactiver') }}" class="mt-4 space-y-3"
                  onsubmit="return confirm('Désactiver le double facteur ?')">
                @csrf
                <div>
                    <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500" for="mfa-mdp">Mot de passe actuel *</label>
                    <input id="mfa-mdp" name="password" type="password" required autocomplete="current-password"
                           class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm" />
                    @error('password')<p class="mt-1 text-xs text-rose-600" role="alert">{{ $message }}</p>@enderror
                </div>
                <x-btn variant="danger" type="submit">Désactiver</x-btn>
            </form>
        </div>
    @else
        <div class="mt-4 ds-card space-y-4 p-5">
            <ol class="list-decimal space-y-1 pl-5 text-sm text-slate-600">
                <li>Ouvrez votre application d'authentification.</li>
                <li>Saisissez ce secret (espaces ignorés) :</li>
            </ol>
            <p class="rounded-xl bg-slate-900 px-4 py-3 text-center font-mono text-sm font-bold tracking-widest text-white select-all">{{ trim(chunk_split($secret, 4, ' ')) }}</p>
            <p class="text-[11px] text-slate-400">Recopiez les lettres dans l'ordre (espaces ignorés). En cas d'échecs répétés : vérifiez l'heure automatique de votre téléphone, puis supprimez l'entrée et recommencez la saisie.</p>
            <form method="POST" action="{{ route('admin.mfa.confirmer') }}" class="space-y-3">
                @csrf
                <div>
                    <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500" for="mfa-code">Code à 6 chiffres *</label>
                    <input id="mfa-code" name="code" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code"
                           class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm font-mono" />
                    @error('code')<p class="mt-1 text-xs text-rose-600" role="alert">{{ $message }}</p>@enderror
                </div>
                <x-btn type="submit">Activer</x-btn>
            </form>
            <form method="POST" action="{{ route('admin.mfa.nouveau-secret') }}" class="mt-2">
                @csrf
                <button type="submit" class="text-xs font-bold text-slate-500 underline underline-offset-2">Recommencer avec un nouveau secret</button>
            </form>
        </div>
    @endif
</div>
@endsection
