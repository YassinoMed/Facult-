@extends('layouts.app')

@section('title', 'Examens')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Examens" description="Organisation d’évaluation — transitions contrôlées serveur."
            :fil="['Évaluations' => route('admin.evaluations.index'), 'Examens' => '#']" />

        <div class="mt-4 ds-card overflow-hidden">
            @if($examens->isEmpty())
                <x-empty-state :message="'Aucun examen — Créez un examen rattaché à une session.'" />
            @else
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500">
                        <tr><th class="px-4 py-2">Libellé</th><th class="px-4 py-2">Session</th><th class="px-4 py-2">Type</th><th class="px-4 py-2">Statut</th><th class="px-4 py-2"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($examens as $examen)
                            <tr>
                                <td class="px-4 py-2 font-bold">{{ $examen->libelle }}</td>
                                <td class="px-4 py-2">{{ $examen->session->nom }}</td>
                                <td class="px-4 py-2">{{ $examen->type }}</td>
                                <td class="px-4 py-2"><x-status-badge :statut="$examen->statut" /></td>
                                <td class="px-4 py-2 text-right">
                                    @can('evaluations.update')
                                        @foreach(\App\Modules\Evaluation\Models\Examen::TRANSITIONS[$examen->statut] ?? [] as $cible)
                                            <form method="post" action="{{ route('admin.evaluations.examens.transiter', [$examen, $cible]) }}" class="inline">
                                                @csrf
                                                <x-btn size="sm" variant="secondary" type="submit">→ {{ $cible }}</x-btn>
                                            </form>
                                        @endforeach
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-4 py-3">{{ $examens->links() }}</div>
            @endif
        </div>
    </div>
@endsection
