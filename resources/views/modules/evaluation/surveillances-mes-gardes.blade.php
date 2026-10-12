@extends('layouts.app')

@section('title', 'Mes Gardes de Surveillance')

@section('content')
    <div class="mx-auto max-w-5xl px-4 sm:px-6 py-8">
        <x-page-header titre="Mes gardes de surveillance" description="Consultez votre calendrier d'affectation pour les examens et devoirs surveillés (DS)."
            :fil="['Évaluations' => route('admin.evaluations.index'), 'Mes surveillances' => '#']" />

        <div class="mt-6 ds-card overflow-hidden bg-white rounded-xl border border-slate-200">
            @if($gardes->isEmpty())
                <div class="p-8">
                    <x-empty-state message="Vous n'avez aucune garde de surveillance programmée pour le moment." />
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500 border-b border-slate-200">
                            <tr>
                                <th class="px-4 py-3">Date</th>
                                <th class="px-4 py-3">Horaire</th>
                                <th class="px-4 py-3">Type</th>
                                <th class="px-4 py-3">Épreuve & Matière</th>
                                <th class="px-4 py-3">Salle assignée</th>
                                <th class="px-4 py-3">Rôle</th>
                                <th class="px-4 py-3">Statut</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($gardes as $garde)
                                <tr class="hover:bg-slate-50/50">
                                    <td class="px-4 py-3 font-semibold text-slate-900">
                                        {{ $garde->epreuve->date->format('d/m/Y') }}
                                    </td>
                                    <td class="px-4 py-3 text-xs text-slate-600 tabular-nums">
                                        {{ substr((string) $garde->epreuve->heure_debut, 0, 5) }} → {{ substr((string) $garde->epreuve->heure_fin, 0, 5) }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold uppercase tracking-wider {{ $garde->epreuve->type_evaluation === 'ds' ? 'bg-amber-100 text-amber-800' : 'bg-indigo-100 text-indigo-800' }}">
                                            {{ $garde->epreuve->type_evaluation ?? 'Examen' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 font-medium text-slate-800">
                                        {{ $garde->epreuve->code }} — {{ $garde->epreuve->matiere->nom }}
                                    </td>
                                    <td class="px-4 py-3 font-bold text-slate-800">
                                        {{ $garde->salle?->code ?? $garde->epreuve->salle?->code ?? '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-xs capitalize text-slate-600">
                                        {{ $garde->role }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <x-status-badge :statut="$garde->statut" />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="px-4 py-3 border-t border-slate-100">{{ $gardes->links() }}</div>
            @endif
        </div>
    </div>
@endsection
