@extends('layouts.app')

@section('title', 'Graphe des prérequis — Référentiel LMD')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Graphe des prérequis" description="Dépendances entre ECUE d'un programme : nœuds, arêtes, cycles et prérequis hors programme. Validation serveur unique (PrerequisService) — le graphe ne crée ni ne supprime aucun lien."
            :fil="['Référentiel' => route('admin.referentiel.tableau-bord'), 'Prérequis' => '#']">
        </x-page-header>

        <form method="GET" action="{{ route('admin.referentiel.prerequis') }}" class="mt-4 flex flex-col sm:flex-row gap-2">
            <select name="programme_id" aria-label="Choisir un programme" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px] flex-1" onchange="this.form.submit()">
                <option value="">Choisir un programme…</option>
                @foreach($programmes as $option)
                    <option value="{{ $option->id }}" @selected($programme?->id === $option->id)>{{ $option->code }} — {{ $option->nom }}</option>
                @endforeach
            </select>
        </form>

        @if($graphe !== null)
            <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-3">
                @foreach([
                    ['ECUE (nœuds)', count($graphe['nodes'])],
                    ['Dépendances (arêtes)', count($graphe['edges'])],
                    ['Cycles détectés', $graphe['aretes_en_cycle'] > 0 ? $graphe['aretes_en_cycle'].' arête(s) en cycle' : '0'],
                    ['Hors programme', count(array_filter($graphe['edges'], fn ($e) => $e['hors_programme']))],
                ] as [$libelle, $valeur])
                    <div class="ds-card p-4">
                        <p class="text-2xl font-black tabular-nums text-slate-900">{{ $valeur }}</p>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">{{ $libelle }}</p>
                    </div>
                @endforeach
            </div>

            @if($graphe['nodes'] !== [])
                <section aria-label="Nœuds par UE" class="mt-4 ds-card p-5">
                    <h2 class="text-base font-black text-slate-900">Nœuds par UE</h2>
                    @foreach(collect($graphe['nodes'])->groupBy('ue') as $ue => $noeuds)
                        <h3 class="mt-3 text-sm font-black font-mono text-slate-700">{{ $ue ?? 'Sans UE' }}</h3>
                        <ul class="mt-1.5 space-y-1.5 text-sm">
                            @foreach($noeuds as $noeud)
                                <li class="flex flex-wrap items-center gap-2 rounded-xl border border-slate-100 px-3.5 py-2.5">
                                    <span><a href="{{ route('admin.referentiel.ecues.show', $noeud['id']) }}" class="font-mono font-bold text-primary-800 underline underline-offset-2">{{ $noeud['code'] }}</a> — {{ $noeud['nom'] }}
                                        <span class="text-slate-500">{{ $noeud['niveau'] ?? '?' }}/{{ $noeud['semestre'] ?? '?' }} · {{ $noeud['dependants'] }} dépendant(s)</span></span>
                                    @if($noeud['en_cycle'])
                                        <span class="rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-black text-red-700">En cycle</span>
                                    @endif
                                    @if($noeud['hors_programme'])
                                        <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-black text-amber-700">Prérequis hors programme</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endforeach
                </section>

                @if($graphe['edges'] !== [])
                    <section aria-label="Dépendances" class="mt-4 ds-card p-5">
                        <h2 class="text-base font-black text-slate-900">Dépendances ({{ count($graphe['edges']) }})</h2>
                        <ul class="mt-2 space-y-1.5 font-mono text-sm">
                            @foreach($graphe['edges'] as $arete)
                                <li class="flex flex-wrap items-center gap-2 rounded-xl border border-slate-100 px-3.5 py-2">
                                    <span>{{ $arete['de'] }} <span aria-hidden="true">→</span> {{ $arete['vers'] }}</span>
                                    @if($arete['cycle'])
                                        <span class="rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-black text-red-700">Cycle</span>
                                    @endif
                                    @if($arete['hors_programme'])
                                        <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-black text-amber-700">Hors programme</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        <p class="mt-2 text-[11px] text-slate-500">Références manquantes impossibles par construction (clés étrangères restrictives). Retirer une arête d'un cycle se fait sur la fiche ECUE, jamais ici.</p>
                    </section>
                @else
                    <div class="mt-4"><x-empty-state message="Aucune dépendance dans ce programme." /></div>
                @endif
            @else
                <div class="mt-4"><x-empty-state message="Aucune ECUE rattachée dans ce programme." /></div>
            @endif
        @endif
    </div>
@endsection
