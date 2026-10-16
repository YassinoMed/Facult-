@extends('layouts.app')

@section('title', 'Code de verrouillage')

@section('content')
<div class="mx-auto max-w-xl px-4 sm:px-6 py-10">
    <x-page-header titre="Code de verrouillage" description="Protège votre session après inactivité. Le code n'est jamais affiché après enregistrement."
        :fil="['Code' => '#']" />

    @if(session('success'))
        <div class="mt-4"><x-alert type="success">{{ session('success') }}</x-alert></div>
    @endif

    @if($pinDefini ?? false)
        <div class="mt-4"><x-alert type="success" :message="'Un code est enregistré sur ce compte : la session se verrouillera après '.$delaiMinutes.' minutes d\u2019inactivité.'" /></div>
    @else
        <div class="mt-4"><x-alert type="warning" message="Aucun code enregistré : le verrouillage après inactivité est inactif tant que le code n'est pas enregistré." /></div>
    @endif

    <form method="POST" action="{{ route('admin.verrouillage.definir') }}" class="mt-4 ds-card space-y-4 p-5">
        @csrf
        <div>
            <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500" for="verrou-mdp">Mot de passe actuel *</label>
            <input id="verrou-mdp" name="password" type="password" required autocomplete="current-password"
                   class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm" />
            @error('password')<p class="mt-1 text-xs text-rose-600" role="alert">{{ $message }}</p>@enderror
        </div>
        @if($mfaActif ?? false)
            <div>
                <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500" for="verrou-totp">Double facteur (6 chiffres) *</label>
                <input id="verrou-totp" name="totp" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code"
                       class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm font-mono" />
                @error('totp')<p class="mt-1 text-xs text-rose-600" role="alert">{{ $message }}</p>@enderror
            </div>
        @endif
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500" for="verrou-code">Nouveau code ({{ $longueur ?? 6 }} chiffres) *</label>
                <input id="verrou-code" name="code" required inputmode="numeric" pattern="[0-9]*" autocomplete="off"
                       minlength="{{ $longueur ?? 6 }}" maxlength="{{ $longueur ?? 6 }}"
                       class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm font-mono" />
                @error('code')<p class="mt-1 text-xs text-rose-600" role="alert">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500" for="verrou-code-conf">Confirmation *</label>
                <input id="verrou-code-conf" name="code_confirmation" required inputmode="numeric" pattern="[0-9]*" autocomplete="off"
                       minlength="{{ $longueur ?? 6 }}" maxlength="{{ $longueur ?? 6 }}"
                       class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm font-mono" />
            </div>
        </div>
        <x-btn type="submit">Enregistrer le code</x-btn>
    </form>
</div>
@endsection
