@extends('layouts.app')

@section('title', 'Parcours LMD de ' . $etudiant->nom_complet)

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header :titre="'Parcours LMD — ' . $etudiant->nom_complet" description="Lecture seule : programme et niveau réellement associés, version de maquette applicable démontrée. Ni notes officielles (voir Mes notes), ni modification."
            :fil="['Dossier' => route('admin.etudiants.show', $etudiant), 'Parcours' => '#']">
        </x-page-header>

        <div class="mt-4 ds-card p-5">
            <dl class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
                <div><dt class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Année</dt><dd class="font-black">{{ $parcours['annee'] ?? '—' }}</dd></div>
                <div><dt class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Inscription</dt><dd class="font-black">{{ $parcours['statut_inscription'] ?? '—' }}</dd></div>
                <div><dt class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Programme</dt><dd class="font-black font-mono">{{ $parcours['programme'] ?? '—' }}</dd></div>
                <div><dt class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Niveau</dt><dd class="font-black">{{ $parcours['niveau'] ?? '—' }}</dd></div>
            </dl>
            <p class="mt-3 text-xs text-slate-500">{{ $parcours['programme_nom'] ?? '' }}</p>
        </div>

        @if($parcours['etat'] === 'OK')
            <div class="mt-4 ds-card p-5">
                <p class="text-sm">Maquette applicable : <strong>v{{ $parcours['maquette']['version'] }} ({{ $parcours['maquette']['statut'] }}, {{ $parcours['maquette']['annee'] }})</strong> — plan actif du programme pour cette année.</p>
            </div>
        @else
            <div class="mt-4"><x-alert type="warning" :titre="$parcours['etat'] === 'SANS_PROGRAMME' ? 'Programme non attribué.' : 'Plan d’études indisponible.'">
                {{ $parcours['message'] }}
                @if($parcours['etat'] === 'SANS_MAQUETTE_APPLICABLE')
                    <br>Contenu affiché : référentiel canonique hors plan actif (la maquette active courante ne s'applique pas implicitement aux autres années).
                @endif
            </x-alert></div>
        @endif

        @foreach($parcours['semestres'] as $semestre)
            <section aria-label="Semestre {{ $semestre['code'] }}" class="mt-4 ds-card p-5">
                <h2 class="text-base font-black text-slate-900">{{ $semestre['code'] }} ({{ count($semestre['ecues']) }} ECUE)</h2>
                @if($semestre['ecues'] !== [])
                    <ul class="mt-2 space-y-1.5 text-sm">
                        @foreach($semestre['ecues'] as $ecue)
                            <li class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-100 px-3.5 py-2">
                                <span><strong class="font-mono">{{ $ecue['code'] }}</strong> — {{ $ecue['nom'] }}
                                    <span class="text-slate-500 tabular-nums">· {{ $ecue['ue'] ?? '?' }} · {{ $ecue['credits'] }} crédits · {{ $ecue['volumes'] }}</span></span>
                                @if($ecue['regime'] !== null)
                                    <span class="text-[11px] font-bold uppercase text-slate-500">{{ $ecue['regime'] }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="mt-2 text-sm text-slate-500">Aucune ECUE.</p>
                @endif
            </section>
        @endforeach

        @if($parcours['inscriptions_pedagogiques'] !== [])
            <section aria-label="Inscriptions pédagogiques" class="mt-4 ds-card p-5">
                <h2 class="text-base font-black text-slate-900">Matières suivies ({{ count($parcours['inscriptions_pedagogiques']) }})</h2>
                <ul class="mt-2 space-y-1.5 text-sm">
                    @foreach($parcours['inscriptions_pedagogiques'] as $suivie)
                        <li class="flex flex-wrap justify-between gap-2 rounded-xl border border-slate-100 px-3.5 py-2">
                            <span><strong class="font-mono">{{ $suivie['matiere'] }}</strong> — {{ $suivie['nom'] }}</span>
                            <span class="text-slate-500">{{ $suivie['semestre'] ?? '?' }} · {{ $suivie['statut'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
@endsection
