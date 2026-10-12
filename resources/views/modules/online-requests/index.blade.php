@extends('layouts.app')

@section('title', 'Demandes en ligne — Scolarité')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8 sm:py-10">
        <div>
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Module demandes en ligne</p>
            <h1 class="mt-1 text-2xl sm:text-3xl font-black tracking-tight text-slate-900">Demandes en ligne</h1>
        </div>

        <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3" aria-label="Statistiques des demandes">
            <x-stat-card libelle="Total" :valeur="$stats['total']" detail="Toutes demandes" couleur="slate" />
            <x-stat-card libelle="Nouvelles" :valeur="$stats['nouvelles']" detail="Soumises / reçues" couleur="primary" />
            <x-stat-card libelle="En cours" :valeur="$stats['en_cours']" detail="Traitement + pièces" couleur="amber" />
            <x-stat-card libelle="Approuvées" :valeur="$stats['approuvees']" detail="Validées" couleur="emerald" />
            <x-stat-card libelle="Rejetées" :valeur="$stats['rejetees']" detail="Refusées" couleur="rose" />
            <x-stat-card libelle="En retard" :valeur="$stats['en_retard']" detail="Hors SLA imputable" couleur="rose" />
            <x-stat-card libelle="Délai moyen" :valeur="$stats['delai_moyen_jours'] !== null ? $stats['delai_moyen_jours'].' j' : '—'" detail="Soumission → clôture" couleur="slate" />
        </div>

        <form method="GET" action="{{ route('admin.demandes.index') }}" class="mt-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label for="recherche" class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Recherche</label>
                    <input type="search" id="recherche" name="search" value="{{ request('search') }}" placeholder="Référence, CIN, nom, objet…"
                           class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm focus:border-primary-700 focus:outline-none">
                </div>
                <div>
                    <label for="filtre-statut" class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Statut</label>
                    <select id="filtre-statut" name="status" class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm focus:border-primary-700 focus:outline-none">
                        <option value="">Tous</option>
                        @foreach(\App\Modules\OnlineRequests\Services\RequestWorkflowService::libelles() as $code => $libelle)
                            <option value="{{ $code }}" @selected(request('status') === $code)>{{ $libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end">
                    <button type="submit" class="w-full rounded-xl bg-primary-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-primary-700 min-h-[44px]">Filtrer</button>
                </div>
            </div>
        </form>

        <section aria-label="Registre des demandes" class="mt-6 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            @if($demandes->isNotEmpty())
                <table class="w-full min-w-[760px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-5 py-3 font-bold">Référence</th>
                            <th scope="col" class="px-5 py-3 font-bold">Étudiant</th>
                            <th scope="col" class="px-5 py-3 font-bold">Type</th>
                            <th scope="col" class="px-5 py-3 font-bold">Statut</th>
                            <th scope="col" class="px-5 py-3 font-bold">Échéance</th>
                            <th scope="col" class="px-5 py-3 font-bold"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($demandes as $demande)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-5 py-3 font-mono font-bold text-slate-800">{{ $demande->reference }}</td>
                                <td class="px-5 py-3">
                                    <span class="block font-bold text-slate-900">{{ $demande->etudiant->nom_complet }}</span>
                                    <span class="block font-mono text-[11px] text-slate-500">{{ $demande->etudiant->cin }}</span>
                                </td>
                                <td class="px-5 py-3 text-slate-600">{{ $demande->type->name }}</td>
                                <td class="px-5 py-3"><x-status-badge :statut="$demande->status" /></td>
                                <td class="px-5 py-3 text-slate-500 tabular-nums">
                                    {{ $demande->echeance()?->format('d/m/Y') ?? '—' }}
                                    @if($demande->enRetard())
                                        <span class="font-bold text-rose-700">· dépassée</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-right">
                                    <a href="{{ route('admin.demandes.show', $demande) }}" class="font-bold text-primary-800 underline underline-offset-2 hover:text-primary-600">Voir</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-5 py-3 border-t border-slate-100">{{ $demandes->links() }}</div>
            @else
                <div class="p-5">
                    <x-empty-state message="Aucune demande dans ce périmètre." />
                </div>
            @endif
        </section>
    </div>
@endsection
