@extends('layouts.app')

@section('title', 'Épreuves')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Épreuves" description="Unité opérationnelle : ECUE, groupe, salle, surveillants, horaire."
            :fil="['Évaluations' => route('admin.evaluations.index'), 'Épreuves' => '#']">
            <x-slot:actions>
                <x-btn href="{{ route('admin.evaluations.planning') }}">Planning calendrier</x-btn>
                <x-btn variant="secondary" href="{{ route('admin.evaluations.surveillances.index') }}">Surveillances</x-btn>
            </x-slot:actions>
        </x-page-header>

        @if(session('conflits'))
            <x-alert type="warning" class="mt-4">
                <ul class="list-disc pl-5">
                    @foreach(session('conflits') as $conflit)
                        <li>{{ $conflit['message'] }}</li>
                    @endforeach
                </ul>
            </x-alert>
        @endif

        <div class="mt-4 ds-card overflow-hidden">
            @if($epreuves->isEmpty())
                <x-empty-state :message="'Aucune épreuve — Planifiez une épreuve depuis un examen.'" />
            @else
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500">
                        <tr><th class="px-4 py-2">Date</th><th class="px-4 py-2">Horaire</th><th class="px-4 py-2">ECUE</th><th class="px-4 py-2">Salle</th><th class="px-4 py-2">Groupe</th><th class="px-4 py-2">Statut</th><th class="px-4 py-2"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($epreuves as $epreuve)
                            <tr>
                                <td class="px-4 py-2 tabular-nums">{{ $epreuve->date->format('d/m/Y') }}</td>
                                <td class="px-4 py-2 tabular-nums">{{ substr($epreuve->heure_debut, 0, 5) }} → {{ substr($epreuve->heure_fin, 0, 5) }}</td>
                                <td class="px-4 py-2 font-bold">{{ $epreuve->matiere->code }}</td>
                                <td class="px-4 py-2">{{ $epreuve->salle?->code ?? '—' }}</td>
                                <td class="px-4 py-2">{{ $epreuve->groupe?->code ?? '—' }}</td>
                                <td class="px-4 py-2"><x-status-badge :statut="$epreuve->statut" /></td>
                                <td class="px-4 py-2 text-right whitespace-nowrap space-x-2">
                                    @can('notes_eval.view')
                                        <a href="{{ route('admin.evaluations.notes.saisie', $epreuve) }}"
                                           class="inline-flex items-center gap-1 rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-bold text-indigo-700 hover:bg-indigo-100 transition-colors">
                                            <span>Saisie anonyme</span>
                                        </a>
                                    @endcan
                                    <a href="{{ route('admin.evaluations.epreuves.show', $epreuve) }}" class="text-xs font-bold text-primary-800 underline underline-offset-2">Détail</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-4 py-3">{{ $epreuves->links() }}</div>
            @endif
        </div>
    </div>
@endsection
