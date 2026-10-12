@extends('layouts.app')

@section('title', 'Préférences d’affichage')

@section('content')
    <div class="mx-auto max-w-2xl px-4 sm:px-6 py-8">
        <x-page-header titre="Préférences" description="Densité des tableaux et lignes par page (registres)."
            :fil="['Préférences' => '#']" />

        <form method="POST" action="{{ route('admin.preferences.update') }}" class="mt-4 ds-card space-y-4 p-5">
            @csrf
            @method('PATCH')
            <div>
                <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="densite">Densité des tableaux</label>
                <select id="densite" name="densite" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" required>
                    <option value="confortable" @selected($preferences['densite'] === 'confortable')>Confortable</option>
                    <option value="compact" @selected($preferences['densite'] === 'compact')>Compacte</option>
                </select>
            </div>
            <div>
                <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="lignes">Lignes par page (5–100)</label>
                <input id="lignes" name="lignes_par_page" type="number" min="5" max="100" value="{{ $preferences['lignes_par_page'] }}" required
                       class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" />
            </div>
            @if($errors->any())
                <x-alert type="error">{{ $errors->first() }}</x-alert>
            @endif
            <x-btn type="submit">Enregistrer</x-btn>
        </form>

        <div class="mt-4 ds-card p-5 text-sm text-slate-500">
            <p class="font-bold text-slate-700">Raccourcis clavier</p>
            <ul class="mt-2 space-y-1 text-xs">
                <li><span class="admin-kbd">Ctrl/⌘ K</span> palette de commandes</li>
                <li><span class="admin-kbd">?</span> cette aide (depuis la palette)</li>
                <li><span class="admin-kbd">↑ ↓ Entrée</span> naviguer dans la palette</li>
                <li><span class="admin-kbd">Échap</span> fermer</li>
                <li><span class="admin-kbd">/</span> recherche globale</li>
            </ul>
        </div>
    </div>
@endsection
