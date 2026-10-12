@extends('layouts.app')

@section('title', 'Prévalidation ' . $programme->code . ' — Référentiel LMD')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header :titre="'Prévalidation — ' . $programme->code" :description="$programme->nom . ' — rapport de simulation en lecture seule, sans aucune écriture.'"
            :fil="['Référentiel' => route('admin.referentiel.tableau-bord'), 'Pilotage' => route('admin.referentiel.pilotage'), 'Prévalidation' => route('admin.referentiel.prevalidation.index'), $programme->code => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('admin.referentiel.entites.show', ['programmes', $programme->id]) }}">Voir le programme</x-btn>
                @if(($rapport['maquettes'][0] ?? null) !== null)
                    <x-btn variant="ghost" href="{{ route('admin.referentiel.maquettes.index', ['programme_id' => $programme->id]) }}">Maquettes</x-btn>
                @endif
            </x-slot:actions>
        </x-page-header>

        <div class="mt-4 ds-card p-5 flex flex-wrap items-center gap-3">
            <p class="text-sm font-black text-slate-900">Recommandation :
                @if($rapport['recommandation'] === 'ACTIVE_DEJA')
                    <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-[11px] uppercase text-emerald-800">Active déjà</span>
                @elseif($rapport['recommandation'] === 'ACTIVABLE')
                    <span class="rounded-full bg-amber-100 px-2.5 py-1 text-[11px] uppercase text-amber-800">Activable (décision explicite requise)</span>
                @else
                    <span class="rounded-full bg-red-100 px-2.5 py-1 text-[11px] uppercase text-red-800">En attente — activation interdite</span>
                @endif
            </p>
            <p class="text-xs text-slate-500">{{ count($rapport['blocages']) }} bloquant(s) · {{ count($rapport['avertissements']) }} avertissement(s) · {{ count($rapport['validations']) }} contrôle(s) réussi(s)</p>
        </div>

        @if($rapport['blocages'] !== [])
            <section aria-label="Bloquants" class="mt-4 ds-card p-5">
                <h2 class="text-base font-black text-slate-900">🔴 Bloquants (activation interdite)</h2>
                <ul class="mt-3 space-y-2 text-sm">
                    @foreach($rapport['blocages'] as $blocage)
                        <li class="rounded-xl border border-red-200 bg-red-50/60 p-3">
                            <p class="font-mono text-[11px] font-bold text-red-700">[{{ $blocage['regle'] }} / {{ $blocage['code'] }}]</p>
                            <p class="mt-0.5 text-slate-800">{{ $blocage['detail'] }}</p>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if($rapport['avertissements'] !== [])
            <section aria-label="Avertissements" class="mt-4 ds-card p-5">
                <h2 class="text-base font-black text-slate-900">🟡 Avertissements (vérification humaine)</h2>
                <ul class="mt-3 space-y-2 text-sm">
                    @foreach($rapport['avertissements'] as $avertissement)
                        <li class="rounded-xl border border-amber-200 bg-amber-50/60 p-3">
                            <p class="font-mono text-[11px] font-bold text-amber-700">[{{ $avertissement['regle'] }} / {{ $avertissement['code'] }}]</p>
                            <p class="mt-0.5 text-slate-800">{{ $avertissement['detail'] }}</p>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section aria-label="Contrôles réussis" class="mt-4 ds-card p-5">
            <h2 class="text-base font-black text-slate-900">🟢 Contrôles réussis ({{ count($rapport['validations']) }})</h2>
            <ul class="mt-3 space-y-1.5 text-sm text-slate-700">
                @foreach($rapport['validations'] as $validation)
                    <li><span class="font-mono text-[11px] font-bold text-emerald-700">[{{ $validation['regle'] }}]</span> {{ $validation['detail'] }}</li>
                @endforeach
            </ul>
        </section>

        @if($rapport['corrections'] !== [])
            <section aria-label="Corrections nécessaires" class="mt-4 ds-card p-5">
                <h2 class="text-base font-black text-slate-900">Corrections nécessaires</h2>
                <ul class="mt-3 list-disc space-y-1.5 pl-5 text-sm text-slate-700">
                    @foreach($rapport['corrections'] as $correction)
                        <li>{{ $correction }}</li>
                    @endforeach
                </ul>
                @if(collect($rapport['blocages'])->contains(fn ($b) => $b['code'] === 'MAQUETTE_ABSENTE'))
                    @can('referentiel.manage')
                        <a href="{{ route('admin.referentiel.maquettes.create', array_filter(['programme_id' => $programme->id, 'annee_universitaire_id' => $anneeActive?->id])) }}" class="mt-3 inline-flex items-center rounded-xl bg-primary-800 px-5 py-2.5 text-sm font-bold text-white min-h-[44px]">Créer la maquette v1 (brouillon vide — contenu à saisir)</a>
                    @endcan
                @endif
            </section>
        @endif

        <section aria-label="Consommateurs" class="mt-4 ds-card p-5">
            <h2 class="text-base font-black text-slate-900">Modules consommateurs</h2>
            <ul class="mt-3 space-y-1.5 text-sm text-slate-700">
                @foreach($rapport['consommateurs'] as $consommateur)
                    <li>· {{ $consommateur }}</li>
                @endforeach
            </ul>
        </section>

        @if($rapport['maquettes'] !== [])
            <section aria-label="Maquettes" class="mt-4 ds-card p-5">
                <h2 class="text-base font-black text-slate-900">Maquettes ({{ count($rapport['maquettes']) }})</h2>
                <ul class="mt-3 space-y-1.5 text-sm tabular-nums text-slate-700">
                    @foreach($rapport['maquettes'] as $maquette)
                        <li>v{{ $maquette['version'] }} · <span class="font-bold uppercase">{{ $maquette['statut'] }}</span> · année {{ $maquette['annee'] ?? '—' }}{{ $maquette['mise_a_jour'] ? ' · MAJ '.$maquette['mise_a_jour'] : '' }}{{ $maquette['date_validation'] ? ' · validée le '.$maquette['date_validation'] : '' }}</li>
                    @endforeach
                </ul>
                <p class="mt-2 text-xs text-slate-500">Les transitions (review, approbation, activation) s'effectuent sur la fiche maquette, avec les permissions requises — jamais depuis ce rapport.</p>
            </section>
        @endif
    </div>
@endsection
