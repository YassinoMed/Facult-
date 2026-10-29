@extends('layouts.app')

@section('title', 'Courriels — supervision')

@section('content')
<div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
    <x-page-header titre="Courriels — supervision" description="Journal technique expurgé : statuts, tentatives et erreurs classifiées. Aucun secret, OTP, corps ou adresse en clair."
        :fil="['Courriels' => '#']" />

    @if(session('success'))
        <div class="mt-4"><x-alert type="success">{{ session('success') }}</x-alert></div>
    @endif
    @if(session('error'))
        <div class="mt-4"><x-alert type="error">{{ session('error') }}</x-alert></div>
    @endif

    <div class="mt-4 flex flex-wrap gap-2">
        <a href="{{ route('admin.courriels.catalogue') }}" class="rounded-xl border border-slate-300 px-4 py-2 text-xs font-bold text-slate-700">Catalogue et aperçu</a>
        @can('campagnes.view')
        <a href="{{ route('admin.campagnes-courriel.index') }}" class="rounded-xl border border-primary-300 bg-primary-50 px-4 py-2 text-xs font-bold text-primary-800 hover:bg-primary-100">Campagnes d’envoi</a>
        @endcan
        @can('mail.send')
        <a href="{{ route('admin.courriels.creer') }}" class="rounded-xl bg-primary-600 px-4 py-2 text-xs font-bold text-white">Message manuel</a>
        @endcan
    </div>

    @if($tauxEchec !== null && $tauxEchec >= 20)
        <div class="mt-4 rounded-2xl border border-rose-300 bg-rose-50 p-4 text-sm text-rose-900" role="alert">
            Taux d'échec de {{ $tauxEchec }} % sur les dernières 24 h : vérifiez la configuration SMTP et la file.
        </div>
    @endif

    <dl class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        @foreach($statuts as $statut)
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-600 font-semibold">{{ $statut }}</dt>
                <dd class="mt-1 text-2xl font-black text-slate-900 tabular-nums">{{ $compteurs[$statut] ?? 0 }}</dd>
            </div>
        @endforeach
    </dl>

    <form method="GET" action="{{ route('admin.courriels.index') }}" class="mt-4 flex flex-col sm:flex-row gap-3">
        <label class="sr-only" for="filtre-statut">Statut</label>
        <select id="filtre-statut" name="statut" class="rounded-xl border border-slate-200 px-3 py-2 text-sm">
            <option value="">Tous statuts</option>
            @foreach($statuts as $statut)
                <option value="{{ $statut }}" @selected($filtreStatut === $statut)>{{ $statut }}</option>
            @endforeach
        </select>
        <label class="sr-only" for="filtre-modele">Modèle</label>
        <select id="filtre-modele" name="modele" class="rounded-xl border border-slate-200 px-3 py-2 text-sm">
            <option value="">Tous modèles</option>
            @foreach($modeles as $modele)
                <option value="{{ $modele }}" @selected($filtreModele === $modele)>{{ $modele }}</option>
            @endforeach
        </select>
        <button type="submit" class="rounded-xl border border-slate-300 px-4 py-2 text-xs font-bold text-slate-700">Filtrer</button>
    </form>

    <div class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white">
        <table class="w-full text-sm min-w-[720px]">
            <thead>
                <tr class="border-b border-slate-100 text-left text-[11px] uppercase tracking-wider text-slate-700 font-bold">
                    <th scope="col" class="px-4 py-3">#</th>
                    <th scope="col" class="px-4 py-3">Modèle</th>
                    <th scope="col" class="px-4 py-3">Destinataire</th>
                    <th scope="col" class="px-4 py-3">Statut</th>
                    <th scope="col" class="px-4 py-3">Tentatives</th>
                    <th scope="col" class="px-4 py-3">Erreur</th>
                    <th scope="col" class="px-4 py-3">Créé le</th>
                    <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                @forelse($courriels as $courriel)
                    <tr class="border-b border-slate-100 last:border-0">
                        <td class="px-4 py-3 font-mono tabular-nums">{{ $courriel->id }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $courriel->modele }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $courriel->destinataire_masque }}</td>
                        <td class="px-4 py-3"><span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-bold text-slate-700">{{ $courriel->statut }}</span></td>
                        <td class="px-4 py-3 tabular-nums">{{ $courriel->tentatives }}</td>
                        <td class="px-4 py-3 text-xs text-slate-500">{{ $courriel->categorie_erreur ?? '—' }}</td>
                        <td class="px-4 py-3 text-xs tabular-nums">{{ $courriel->created_at->format('d/m/Y H:i') }}</td>
                        <td class="px-4 py-3"><a href="{{ route('admin.courriels.voir', $courriel) }}" class="font-bold text-primary-800 underline underline-offset-2">Détail</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-8 text-center text-sm text-slate-500">Aucun envoi journalisé.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $courriels->links() }}</div>
</div>
@endsection
