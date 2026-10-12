@extends('layouts.app')

@section('title', 'Prospective — Parcours')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8 sm:py-10">
        <x-page-header :titre="'Prospective — ' . $projection['programme']" :description="'Source : ' . $projection['annee_source'] . ' → cible : ' . ($projection['annee_cible'] ?? '—') . ' (dry-run, sans écriture)'" :fil="['Parcours' => route('admin.parcours.index'), 'Prospective' => url()->current()]" />
        <x-alert type="info" class="mt-4">Projection calculée depuis les décisions officielles (AcademicProgressionService::evaluer). Aucune inscription créée.</x-alert>
        <form method="GET" action="{{ route('admin.parcours.prospective') }}" class="mt-4 flex flex-col sm:flex-row gap-2">
            <select name="programme_id" aria-label="Programme" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                @foreach($programmes as $p)
                    <option value="{{ $p->id }}" @selected((int) $programmeId === (int) $p->id)>{{ $p->nom }}</option>
                @endforeach
            </select>
            <select name="annee_universitaire_id" aria-label="Année source" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                @foreach($annees as $a)
                    <option value="{{ $a->id }}" @selected((int) $anneeId === (int) $a->id)>{{ $a->libelle }}</option>
                @endforeach
            </select>
            <x-btn type="submit" variant="secondary">Projeter</x-btn>
        </form>
        <div class="mt-6 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full min-w-[720px] text-left text-xs sm:text-sm">
                <thead><tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                    <th class="px-5 py-3 font-bold">Niveau</th><th class="px-5 py-3 font-bold">Inscrits</th><th class="px-5 py-3 font-bold">Passage</th><th class="px-5 py-3 font-bold">Redouble</th><th class="px-5 py-3 font-bold">Projetés N+1</th><th class="px-5 py-3 font-bold">Places groupes</th><th class="px-5 py-3 font-bold">Manquantes</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($projection['niveaux'] as $ligne)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-5 py-3 font-bold">{{ $ligne['niveau'] }}</td>
                            <td class="px-5 py-3 tabular-nums">{{ $ligne['inscrits'] }}</td>
                            <td class="px-5 py-3 tabular-nums">{{ $ligne['progresser'] }}</td>
                            <td class="px-5 py-3 tabular-nums">{{ $ligne['redoubler'] }}</td>
                            <td class="px-5 py-3 tabular-nums font-bold">{{ $ligne['projetes'] }}</td>
                            <td class="px-5 py-3 tabular-nums">{{ $ligne['places_groupes'] }}</td>
                            <td class="px-5 py-3 tabular-nums">{{ $ligne['places_manquantes'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
