@extends('layouts.app')

@section('title', 'Studio e-mail — thèmes')

@section('content')
<div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
    <x-page-header titre="Thèmes e-mail" description="Identité visuelle réutilisable : couleurs hexadécimales, largeurs bornées, logo https + extensions autorisées."
        :fil="['Studio' => route('admin.studio.index')]" />

    @if(session('success'))
        <div class="mt-4"><x-alert type="success">{{ session('success') }}</x-alert></div>
    @endif
    @if(session('error'))
        <div class="mt-4"><x-alert type="error">{{ session('error') }}</x-alert></div>
    @endif

    <div class="mt-4 grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-black text-slate-900">Nouveau thème</h2>
            <form method="POST" action="{{ route('admin.studio.themes.store') }}" class="mt-3 space-y-3 text-sm">
                @csrf
                <div>
                    <label for="theme-nom" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Nom *</label>
                    <input id="theme-nom" name="nom" required maxlength="100" class="w-full rounded-xl border border-slate-200 px-3 py-2">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    @foreach(['couleur_principale' => '#3730a3', 'couleur_accent' => '#4f46e5', 'fond' => '#f1f5f9', 'fond_contenu' => '#ffffff'] as $cle => $defaut)
                        <div>
                            <label for="theme-{{ $cle }}" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">{{ $cle }} *</label>
                            <input id="theme-{{ $cle }}" name="config[{{ $cle }}]" required pattern="#[0-9a-fA-F]{6}" value="{{ $defaut }}" class="w-full rounded-xl border border-slate-200 px-3 py-2 font-mono">
                        </div>
                    @endforeach
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="theme-largeur" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Largeur (480–800) *</label>
                        <input id="theme-largeur" name="config[largeur]" type="number" required min="480" max="800" value="600" class="w-full rounded-xl border border-slate-200 px-3 py-2">
                    </div>
                    <div>
                        <label for="theme-logo" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Logo (https, jpg/png/gif/webp)</label>
                        <input id="theme-logo" name="config[logo_url]" maxlength="500" placeholder="https://…" class="w-full rounded-xl border border-slate-200 px-3 py-2">
                    </div>
                </div>
                <div>
                    <label for="theme-etab" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Nom établissement</label>
                    <input id="theme-etab" name="config[nom_etablissement]" maxlength="120" class="w-full rounded-xl border border-slate-200 px-3 py-2">
                </div>
                <div>
                    <label for="theme-signature" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Signature</label>
                    <textarea id="theme-signature" name="config[signature]" rows="2" maxlength="500" class="w-full rounded-xl border border-slate-200 px-3 py-2"></textarea>
                </div>
                <div>
                    <label for="theme-mentions" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Mentions</label>
                    <textarea id="theme-mentions" name="config[mentions]" rows="2" maxlength="500" class="w-full rounded-xl border border-slate-200 px-3 py-2"></textarea>
                </div>
                <button type="submit" class="rounded-xl bg-primary-600 px-5 py-2.5 text-xs font-bold text-white">Créer le thème</button>
            </form>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-black text-slate-900">Thèmes existants</h2>
            <ul class="mt-3 space-y-2 text-sm">
                @forelse($themes as $theme)
                    <li class="rounded-xl border border-slate-200 px-3 py-2">
                        <span class="font-bold">{{ $theme->nom }}</span>
                        <span class="ml-2 inline-block h-4 w-4 rounded align-middle" style="background:{{ $theme->config['couleur_principale'] ?? '#888' }}" aria-hidden="true"></span>
                        <span class="text-xs text-slate-500">largeur {{ $theme->config['largeur'] ?? '?' }}</span>
                    </li>
                @empty
                    <li class="text-slate-500">Aucun thème.</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
@endsection
