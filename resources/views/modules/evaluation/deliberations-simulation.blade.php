@extends('layouts.app')

@section('title', 'Simulation — délibération')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Simulation (sans écriture)" description="Mêmes calculs que la préparation, aucune donnée modifiée."
            :fil="['Délibérations' => route('admin.evaluations.deliberations.index'), 'Simulation' => '#']">
            <x-slot:actions>
                <form method="post" action="{{ route('admin.evaluations.deliberations.preparer', $deliberation) }}">
                    @csrf
                    <x-btn type="submit">Appliquer (préparer)</x-btn>
                </form>
            </x-slot:actions>
        </x-page-header>

        @if($simulation['anomalies'] !== [])
            <x-alert type="warning" class="mt-4">
                <ul class="list-disc pl-5">
                    @foreach($simulation['anomalies'] as $anomalie)
                        <li>{{ $anomalie['message'] }}</li>
                    @endforeach
                </ul>
            </x-alert>
        @endif

        <div class="mt-4 ds-card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500">
                    <tr><th class="px-4 py-2">Étudiant</th><th class="px-4 py-2 text-right">Moy.</th><th class="px-4 py-2 text-right">Crédits</th><th class="px-4 py-2 text-right">UE</th><th class="px-4 py-2">Proposition</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($simulation['decisions'] as $decision)
                        <tr>
                            <td class="px-4 py-2 font-bold">{{ $noms[$decision['etudiant_id']] ?? '#'.$decision['etudiant_id'] }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $decision['moyenne'] ?? '—' }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $decision['credits_obtenus'] }}/{{ $decision['credits_total'] }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $decision['ues_validees'] }}/{{ $decision['ues_total'] }}</td>
                            <td class="px-4 py-2"><x-status-badge :statut="$decision['decision']" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
