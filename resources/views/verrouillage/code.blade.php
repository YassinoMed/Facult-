@extends('layouts.app')

@section('title', 'Code de verrouillage')

@section('content')
<div class="mx-auto max-w-xl px-4 sm:px-6 py-10">
    <x-page-header titre="Code de verrouillage" description="Protège votre session après inactivité. Le code n'est jamais affiché après enregistrement."
        :fil="['Code' => '#']" />

    @if(session('success'))
        <div class="mt-4"><x-alert type="success">{{ session('success') }}</x-alert></div>
    @endif

    <form method="POST" action="{{ route('admin.verrouillage.definir') }}" class="mt-4 ds-card space-y-4 p-5">
        @csrf
        <div>
            <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500" for="verrou-mdp">Mot de passe actuel *</label>
            <input id="verrou-mdp" name="password" type="password" required autocomplete="current-password"
                   class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm" />
            @error('password')<p class="mt-1 text-xs text-rose-600" role="alert">{{ $message }}</p>@enderror
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500" for="verrou-code">Nouveau code (chiffres) *</label>
                <input id="verrou-code" name="code" required inputmode="numeric" autocomplete="off"
                       class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm font-mono" />
                @error('code')<p class="mt-1 text-xs text-rose-600" role="alert">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500" for="verrou-code-conf">Confirmation *</label>
                <input id="verrou-code-conf" name="code_confirmation" required inputmode="numeric" autocomplete="off"
                       class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm font-mono" />
            </div>
        </div>
        <x-btn type="submit">Enregistrer le code</x-btn>
    </form>
</div>
@endsection
