@extends('layouts.app')

@section('title', 'Cockpit & Charge des Surveillances')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 py-8">
        <x-page-header titre="Cockpit & Charge de Surveillance" description="Supervision centralisée des gardes, détection des épreuves non couvertes et équité des charges d'enseignement."
            :fil="['Évaluations' => route('admin.evaluations.index'), 'Surveillances' => route('admin.evaluations.surveillances.index'), 'Cockpit' => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('admin.evaluations.surveillances.index') }}">Toutes les surveillances</x-btn>
                <x-btn variant="secondary" href="{{ route('admin.evaluations.planning') }}">Planning Examens</x-btn>
            </x-slot:actions>
        </x-page-header>

        <!-- Filtre Session & Type -->
        <div class="mt-6 ds-card p-4 bg-white rounded-xl border border-slate-200">
            <form method="get" action="{{ route('admin.evaluations.surveillances.cockpit') }}" class="flex flex-wrap items-center gap-4 text-sm">
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Session</label>
                    <select name="session_id" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white focus:border-indigo-500">
                        <option value="">Toutes les sessions</option>
                        @foreach($sessions as $session)
                            <option value="{{ $session->id }}" @selected(($filtres['session_id'] ?? null) == $session->id)>
                                {{ $session->nom }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Type d'épreuve</label>
                    <select name="type_evaluation" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white focus:border-indigo-500">
                        <option value="">Tous types</option>
                        <option value="examen" @selected(($filtres['type_evaluation'] ?? null) === 'examen')>Examens</option>
                        <option value="ds" @selected(($filtres['type_evaluation'] ?? null) === 'ds')>Devoirs Surveillés (DS)</option>
                    </select>
                </div>

                <div class="self-end">
                    <x-btn type="submit">Actualiser les indicateurs</x-btn>
                </div>
            </form>
        </div>

        <!-- KPI Cockpit & Équilibre de Charge (§14) -->
        <div class="mt-6 grid grid-cols-2 sm:grid-cols-4 gap-4">
            <x-stat-card libelle="Total enseignants" :valeur="$cockpit['total_enseignants'] ?? 0" detail="Effectif mobilisable" couleur="primary" />
            <x-stat-card libelle="Charge moyenne" :valeur="$cockpit['charge_moyenne'] ?? 0" detail="Gardes / enseignant" couleur="slate" />
            <x-stat-card libelle="Écart de charge" :valeur="$cockpit['ecart_charge'] ?? 0" detail="Min: {{ $cockpit['charge_min'] ?? 0 }} · Max: {{ $cockpit['charge_max'] ?? 0 }}" couleur="{{ ($cockpit['ecart_charge'] ?? 0) <= 2 ? 'emerald' : 'amber' }}" />
            <x-stat-card libelle="Total des heures" :valeur="($cockpit['total_heures'] ?? 0) . ' h'" detail="Volume horaire global" couleur="primary" />
        </div>

        <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-4">
            <x-stat-card libelle="Épreuves conformes" :valeur="($cockpit['epreuves_correctes'] ?? 0) . ' / ' . ($cockpit['total_epreuves'] ?? 0)" detail="Couverture requise (config)" couleur="emerald" />
            <x-stat-card libelle="Épreuves incomplètes" :valeur="$cockpit['epreuves_incompletes'] ?? 0" detail="Sous les besoins configurés" couleur="{{ ($cockpit['epreuves_incompletes'] ?? 0) > 0 ? 'rose' : 'slate' }}" />
            <x-stat-card libelle="Épreuves sans garde" :valeur="$cockpit['epreuves_sans_garde'] ?? 0" detail="Aucun remplaçant prévu" couleur="{{ ($cockpit['epreuves_sans_garde'] ?? 0) > 0 ? 'amber' : 'slate' }}" />
            <x-stat-card libelle="Déficit capacité" :valeur="$cockpit['epreuves_probleme_capacite'] ?? 0" detail="Candidats > Capacité salle" couleur="{{ ($cockpit['epreuves_probleme_capacite'] ?? 0) > 0 ? 'rose' : 'emerald' }}" />
        </div>

        <div class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-4">
            <x-stat-card libelle="Gardes confirmées" :valeur="$cockpit['gardes_confirmees']" detail="Présences validées" couleur="emerald" />
            <x-stat-card libelle="Surveillants absents" :valeur="$cockpit['surveillants_absents']" detail="Défauts de garde" couleur="rose" />
            <x-stat-card libelle="Remplacements effectués" :valeur="$cockpit['remplacements']" detail="Gardes réassignées" couleur="primary" />
        </div>

        <!-- Charge d'enseignement & Répartition équitable (§10) -->
        <div class="mt-8 ds-card bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="p-4 border-b border-slate-100 flex items-center justify-between">
                <div>
                    <h3 class="font-bold text-slate-900">Répartition des charges de surveillance par enseignant</h3>
                    <p class="text-xs text-slate-500">Permet d'identifier les surcharges et de prioriser les enseignants les moins sollicités.</p>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500 border-b border-slate-200">
                        <tr>
                            <th class="px-4 py-3">Matricule</th>
                            <th class="px-4 py-3">Enseignant</th>
                            <th class="px-4 py-3 text-center">Gardes DS</th>
                            <th class="px-4 py-3 text-center">Gardes Examens</th>
                            <th class="px-4 py-3 text-center">Total Gardes</th>
                            <th class="px-4 py-3 text-right">Volume Horaire</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($charges as $charge)
                            <tr class="hover:bg-slate-50/50">
                                <td class="px-4 py-3 font-mono text-xs text-slate-500">
                                    {{ $charge['matricule'] ?? '—' }}
                                </td>
                                <td class="px-4 py-3 font-semibold text-slate-800">
                                    {{ $charge['nom'] }}
                                </td>
                                <td class="px-4 py-3 text-center tabular-nums">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-50 text-amber-800">
                                        {{ $charge['gardes_ds'] }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center tabular-nums">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-50 text-indigo-800">
                                        {{ $charge['gardes_examen'] }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center tabular-nums font-bold text-slate-900">
                                    {{ $charge['total_gardes'] }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums font-bold text-slate-700">
                                    {{ $charge['heures_totales'] }} h
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="p-8 text-center text-slate-400">
                                    Aucune donnée de charge disponible pour ce périmètre.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
