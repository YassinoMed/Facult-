@extends('layouts.app')

@section('title', 'Journal d’audit — Scolarité')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 py-8">
        <x-page-header titre="Journal d’audit" description="Traçabilité centrale en lecture seule — sans secrets."
            :fil="['Tableau de bord' => route('admin.dashboard'), 'Audit' => '#']" />

        <form method="GET" action="{{ route('admin.audit.index') }}" class="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-2 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <input type="search" name="search" value="{{ request('search') }}" placeholder="Nom, prénom, CIN…"
                   aria-label="Rechercher un étudiant" class="rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm focus:border-primary-700 focus:outline-none min-h-[44px]">
            <select name="action" aria-label="Filtrer par action" class="rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm focus:border-primary-700 focus:outline-none">
                <option value="">Toutes actions</option>
                @foreach($actions as $action)
                    <option value="{{ $action }}" @selected(request('action') === $action)>{{ $action }}</option>
                @endforeach
            </select>
            <input type="date" name="depuis" value="{{ request('depuis') }}" aria-label="Depuis le"
                   class="rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm focus:border-primary-700 focus:outline-none">
            <input type="date" name="jusquau" value="{{ request('jusquau') }}" aria-label="Jusqu’au"
                   class="rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm focus:border-primary-700 focus:outline-none">
            <x-btn type="submit" variant="secondary">Filtrer</x-btn>
        </form>

        <section aria-label="Entrées d’audit" class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            @if($entrees->isNotEmpty())
                <table class="w-full min-w-[720px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-700 font-bold">
                            <th scope="col" class="px-5 py-3 font-bold">Date</th>
                            <th scope="col" class="px-5 py-3 font-bold">Action</th>
                            <th scope="col" class="px-5 py-3 font-bold">Entité</th>
                            <th scope="col" class="px-5 py-3 font-bold">Étudiant</th>
                            <th scope="col" class="px-5 py-3 font-bold">Acteur</th>
                            <th scope="col" class="px-5 py-3 font-bold">IP</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($entrees as $entree)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-5 py-3 tabular-nums text-slate-600">{{ $entree->created_at->format('d/m/Y H:i') }}</td>
                                <td class="px-5 py-3 font-mono text-[11px] font-bold text-slate-800">{{ $entree->action }}</td>
                                <td class="px-5 py-3 font-mono text-[11px] text-slate-600">{{ $entree->entite }}{{ $entree->entite_id !== null ? ' #'.$entree->entite_id : '' }}</td>
                                <td class="px-5 py-3 text-slate-700">{{ $entree->etudiant?->nom_complet ?? '—' }}</td>
                                <td class="px-5 py-3 text-slate-600">{{ $entree->utilisateur?->name ?? 'Système' }}</td>
                                <td class="px-5 py-3 font-mono text-[11px] text-slate-500">{{ $entree->adresse_ip ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-5 py-3 border-t border-slate-100">{{ $entrees->links() }}</div>
            @else
                <div class="p-5"><x-empty-state message="Aucune entrée pour ces critères." /></div>
            @endif
        </section>
    </div>
@endsection
