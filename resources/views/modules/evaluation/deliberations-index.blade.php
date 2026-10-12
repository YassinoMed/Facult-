@extends('layouts.app')

@section('title', 'Délibérations')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Délibérations / jurys" description="Préparation automatique, anomalies bloquantes, décisions, publication."
            :fil="['Évaluations' => route('admin.evaluations.index'), 'Délibérations' => '#']" />

        <div class="mt-4 ds-card overflow-hidden">
            @if($deliberations->isEmpty())
                <x-empty-state :message="'Aucune délibération — Créez une délibération par année / session / programme.'" />
            @else
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500">
                        <tr><th class="px-4 py-2">Année</th><th class="px-4 py-2">Session</th><th class="px-4 py-2">Programme</th><th class="px-4 py-2">Statut</th><th class="px-4 py-2"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($deliberations as $deliberation)
                            <tr>
                                <td class="px-4 py-2 tabular-nums">{{ $deliberation->anneeUniversitaire->libelle }}</td>
                                <td class="px-4 py-2">{{ $deliberation->session?->nom ?? '—' }}</td>
                                <td class="px-4 py-2">{{ $deliberation->programme?->nom ?? '—' }}</td>
                                <td class="px-4 py-2"><x-status-badge :statut="$deliberation->statut" /></td>
                                <td class="px-4 py-2 text-right">
                                    <a href="{{ route('admin.evaluations.deliberations.show', $deliberation) }}" class="text-xs font-bold text-primary-800 underline underline-offset-2">Tableau</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-4 py-3">{{ $deliberations->links() }}</div>
            @endif
        </div>
    </div>
@endsection
