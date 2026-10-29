@extends('layouts.app')

@section('title', 'Cohérence des compétences')

@section('content')
    <div class="mx-auto max-w-5xl px-4 sm:px-6 py-8">
        <x-page-header titre="Cohérence des associations" description="Contrôle en lecture seule : aucune correction automatique, chaque anomalie est traitée par une personne habilitée."
            :fil="['Référentiel' => route('admin.referentiel.tableau-bord'), 'Compétences' => route('admin.referentiel.competences.index'), 'Cohérence' => '#']">
            <x-slot:actions>
                <x-btn variant="ghost" href="{{ route('admin.referentiel.competences.index') }}">Catalogue</x-btn>
            </x-slot:actions>
        </x-page-header>

        @if($anomalies === [])
            <div class="mt-4"><x-alert type="success">Aucune anomalie détectée : toutes les associations pointent une cible existante et une compétence approuvée.</x-alert></div>
        @else
            <div class="mt-4"><x-alert type="warning" titre="{{ count($anomalies) }} anomalie(s) détectée(s).">
                Ces associations sont conservées telles quelles : aucune suppression ni réécriture automatique.
            </x-alert></div>

            <section aria-label="Anomalies détectées" class="mt-4 ds-card overflow-x-auto">
                <table class="w-full min-w-[520px] text-sm">
                    <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500">
                        <tr>
                            <th scope="col" class="px-4 py-2">Association</th>
                            <th scope="col" class="px-4 py-2">Anomalie</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($anomalies as $anomalie)
                            <tr>
                                <td class="px-4 py-2 font-mono text-xs">#{{ $anomalie['competence_objectif_id'] }}</td>
                                <td class="px-4 py-2 text-slate-700">{{ $anomalie['anomalie'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endif

        <p class="mt-4 text-xs text-slate-400">La même vérification est planifiée quotidiennement via <code>competences:coherence</code> lorsque le module référentiel est actif.</p>
    </div>
@endsection
