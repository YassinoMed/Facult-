@extends('layouts.app')

@section('title', $programme->code . ' — Programme')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header :titre="$programme->code . ' — ' . $programme->nom" :description="$programme->intitule_officiel ?? $programme->typeDiplome?->nom"
            :fil="['Référentiel' => route('admin.referentiel.tableau-bord'), 'Programmes' => route('admin.referentiel.entites.index', 'programmes'), $programme->code => '#']">
            <x-slot:actions>
                @can('referentiel.manage')
                    <x-btn variant="secondary" href="{{ route('admin.referentiel.entites.edit', ['programmes', $programme]) }}">Modifier</x-btn>
                @endcan
            </x-slot:actions>
        </x-page-header>

        <div x-data="{ onglet: 'structure' }" class="admin-tabs mt-4">
            <div role="tablist" aria-label="Sections du programme" class="flex gap-1 overflow-x-auto border-b border-slate-200">
                @foreach(['structure' => 'Structure LMD', 'maquettes' => 'Maquettes', 'infos' => 'Informations'] as $cle => $libelle)
                    <button type="button" role="tab" :aria-selected="(onglet === '{{ $cle }}').toString()"
                            @click="onglet = '{{ $cle }}'"
                            class="whitespace-nowrap px-4 py-2.5 text-sm font-bold min-h-[44px]"
                            :class="onglet === '{{ $cle }}' ? 'text-primary-800' : 'text-slate-500 hover:text-slate-900'">{{ $libelle }}</button>
                @endforeach
            </div>

            <div x-show="onglet === 'structure'" x-cloak role="tabpanel" class="pt-4 space-y-3">
                @forelse($programme->niveaux as $niveau)
                    <details class="ds-card p-4" open>
                        <summary class="cursor-pointer text-sm font-black text-slate-900 min-h-[36px] flex items-center gap-2">
                            {{ $niveau->code }} — {{ $niveau->nom }}
                            <span class="text-[11px] text-slate-500">{{ $niveau->semestres->count() }} semestre(s)</span>
                        </summary>
                        @foreach($niveau->semestres as $semestre)
                            <div class="mt-2 ml-2 sm:ml-4 rounded-xl border border-slate-200 p-3">
                                <p class="text-xs font-black text-slate-800">{{ $semestre->code }} — {{ $semestre->nom }}
                                    <span class="text-slate-500">({{ $semestre->unitesEnseignement->count() }} UE)</span></p>
                                @foreach($semestre->unitesEnseignement as $ue)
                                    <div class="mt-1.5 ml-2 sm:ml-4 text-xs">
                                        <a href="{{ route('admin.referentiel.entites.show', ['ues', $ue]) }}" class="font-bold text-primary-800 underline underline-offset-2">{{ $ue->code }} — {{ $ue->nom }}</a>
                                        <span class="text-slate-500 tabular-nums">· {{ $ue->credits }} crédits</span>
                                        <p class="text-slate-600">{{ $ue->ecues->pluck('code')->implode(', ') ?: '— aucune ECUE —' }}</p>
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </details>
                @empty
                    <x-empty-state message="Aucun niveau défini pour ce programme." />
                @endforelse
            </div>

            <div x-show="onglet === 'maquettes'" x-cloak role="tabpanel" class="pt-4">
                @if($programme->maquettes->isNotEmpty())
                    <ul class="space-y-2">
                        @foreach($programme->maquettes as $maquette)
                            <li class="ds-card p-4 flex flex-wrap items-center justify-between gap-2">
                                <p class="text-sm font-black text-slate-900">v{{ $maquette->version }} · {{ $maquette->anneeUniversitaire->libelle }}
                                    <span class="ml-1 text-[11px] uppercase">{{ $maquette->statut }}</span></p>
                                <a href="{{ route('admin.referentiel.maquettes.show', $maquette) }}" class="text-xs font-bold text-primary-800 underline underline-offset-2">Ouvrir →</a>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <x-empty-state message="Aucune maquette pour ce programme." />
                @endif
            </div>

            <div x-show="onglet === 'infos'" x-cloak role="tabpanel" class="pt-4">
                <dl class="ds-card p-6 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div><dt class="text-[11px] font-bold uppercase text-slate-400">Établissement</dt><dd class="font-semibold">{{ $programme->faculte->nom }}</dd></div>
                    <div><dt class="text-[11px] font-bold uppercase text-slate-400">Département</dt><dd class="font-semibold">{{ $programme->departement?->nom ?? '—' }}</dd></div>
                    <div><dt class="text-[11px] font-bold uppercase text-slate-400">Spécialité</dt><dd class="font-semibold">{{ $programme->specialite?->nom ?? '—' }}</dd></div>
                    <div><dt class="text-[11px] font-bold uppercase text-slate-400">Diplôme</dt><dd class="font-semibold">{{ $programme->typeDiplome?->nom ?? '—' }}</dd></div>
                    <div><dt class="text-[11px] font-bold uppercase text-slate-400">Durée / Semestres / Crédits</dt><dd class="font-semibold tabular-nums">{{ $programme->duree_annees }} ans · {{ $programme->semestres_nombre }} · {{ $programme->credits_requis }}</dd></div>
                    <div><dt class="text-[11px] font-bold uppercase text-slate-400">Statut</dt><dd class="font-semibold">{{ ucfirst($programme->statut) }}</dd></div>
                    <div><dt class="text-[11px] font-bold uppercase text-slate-400">Capacité indicative</dt><dd class="font-semibold tabular-nums">{{ $programme->capacite ?? '—' }}</dd></div>
                    <div><dt class="text-[11px] font-bold uppercase text-slate-400">Mode</dt><dd class="font-semibold">{{ $programme->mode }}</dd></div>
                </dl>
                @if($programme->description)
                    <p class="mt-3 text-sm text-slate-600">{{ $programme->description }}</p>
                @endif
            </div>
        </div>
    </div>
@endsection
