@extends('layouts.app')

@section('title', 'Examens — Simulation & Contrôle qualité')

@section('content')

@php
    $sessionsEnCours = $sessionsStats['open'] + $sessionsStats['ongoing'];
    $pointsAttention = $statsPreparation['sans_salle'] + $sallesSansCapacite + $epreuvesSansSurveillant;
@endphp

<div class="space-y-6">
    <x-page-header
        titre="Simulation et contrôle qualité"
        description="Audit sans publication : vérification des capacités, détection des chevauchements, exhaustivité des données et synthèse PASS / FAIL."
        :fil="['Évaluation' => route('admin.evaluations.index'), 'Examens' => route('admin.evaluation.examens.tableau-de-bord'), 'Simulation' => '']"
    />

    @include('modules.evaluation.examens-advanced._nav-tabs')

    {{-- Périmètre de l'audit --}}
    <section class="examens-card rounded-2xl border border-slate-200/80 bg-white p-5 shadow-xs" aria-labelledby="titre-perimetre-audit">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 id="titre-perimetre-audit" class="text-sm font-bold text-slate-900">Périmètre de l'audit</h2>
                <p class="mt-0.5 text-xs text-slate-500">Lecture seule : aucun contrôle ne modifie le planning, les salles ni les affectations.</p>
            </div>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-semibold text-slate-600">
                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                {{ $examens->count() }} examen{{ $examens->count() > 1 ? 's' : '' }} disponible{{ $examens->count() > 1 ? 's' : '' }}
            </span>
        </div>

        <form method="GET"
              action="{{ route('admin.evaluation.examens.simulation') }}"
              class="mt-4 flex flex-wrap items-end gap-4"
              x-data="{ actualisation: false }"
              @submit="actualisation = true">
            <div class="min-w-[280px] flex-1">
                <label for="examen_id" class="mb-1 block text-xs font-semibold text-slate-600">Sélectionner un examen à auditer</label>
                <div class="relative">
                    <select id="examen_id"
                            name="examen_id"
                            onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()"
                            :class="actualisation ? 'opacity-60' : ''"
                            class="w-full appearance-none rounded-xl border border-slate-200 bg-white py-2 pl-3 pr-10 text-sm font-medium text-slate-800 transition-colors hover:border-slate-300 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20">
                        <option value="">— Choisir un examen pour lancer l'audit —</option>
                        @foreach($examens as $ex)
                            <option value="{{ $ex->id }}" @selected($examenId === (int)$ex->id)
                                >{{ $ex->code }} — {{ $ex->libelle }} ({{ $ex->statut }})</option>
                        @endforeach
                    </select>
                    <svg class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                    </svg>
                </div>
            </div>
            <p x-show="actualisation" hidden class="inline-flex items-center gap-2 pb-2 text-xs font-medium text-slate-500">
                <svg class="h-3.5 w-3.5 animate-spin motion-reduce:animate-none" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                </svg>
                Audit en cours…
            </p>
        </form>
    </section>

    @if($audit)
        @php
            $conforme = (bool) ($audit['conforme'] ?? false);
            $score = max(0, min(100, (int) ($audit['score_conformite'] ?? 0)));
            $bloquants = $audit['conflits_bloquants'] ?? [];
            $avertissements = $audit['avertissements'] ?? [];
            $nbEpreuves = (int) ($audit['nb_epreuves'] ?? 0);
            $sansSalle = (int) ($audit['epreuves_sans_salle'] ?? 0);
            $sousSurveillees = (int) ($audit['epreuves_sous_surveillees'] ?? 0);
            $teinte = $conforme ? 'emerald' : 'rose';
        @endphp

        {{-- Synthèse d'audit --}}
        <section class="examens-card examens-verdict rounded-2xl border p-6 shadow-xs {{ $conforme ? 'border-emerald-200 bg-emerald-50/40' : 'border-rose-200 bg-rose-50/40' }}"
                 aria-labelledby="titre-verdict-audit"
                 data-motion-block>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <span class="text-xs font-bold uppercase tracking-wider {{ $conforme ? 'text-emerald-700' : 'text-rose-700' }}">Verdict de l'audit de conformité</span>
                    <h2 id="titre-verdict-audit" class="mt-1 text-xl font-black leading-snug sm:text-2xl {{ $conforme ? 'text-emerald-900' : 'text-rose-900' }}">
                        {{ $conforme ? 'PASS — Tous les contrôles obligatoires sont satisfaits' : 'FAIL — Contraintes bloquantes détectées' }}
                    </h2>
                </div>
                <span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl {{ $conforme ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800' }}" aria-hidden="true">
                    @if($conforme)
                        <svg class="examens-verdict-glyphe h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path pathLength="1" stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                    @else
                        <svg class="examens-verdict-glyphe h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path pathLength="1" stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    @endif
                </span>
            </div>

            {{-- Métriques de l'audit (données déjà calculées côté serveur) --}}
            <dl class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-xl border border-white/70 bg-white/80 p-3">
                    <dt class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Score</dt>
                    <dd class="mt-1 flex items-baseline gap-1">
                        <span class="examens-kpi-valeur text-2xl font-black {{ $conforme ? 'text-emerald-800' : 'text-rose-700' }}" data-motion-count="{{ $score }}" aria-hidden="true">{{ $score }}</span>
                        <span class="text-xs font-semibold text-slate-500" aria-hidden="true">/ 100</span>
                        <span class="sr-only">{{ $score }} sur 100</span>
                    </dd>
                    <div class="examens-jauge mt-2" style="--score: {{ number_format($score / 100, 4, '.', '') }}">
                        <span class="{{ $conforme ? 'bg-emerald-500' : 'bg-rose-500' }}"></span>
                    </div>
                </div>

                <div class="rounded-xl border border-white/70 bg-white/80 p-3">
                    <dt class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Épreuves auditées</dt>
                    <dd class="mt-1 flex items-baseline gap-1">
                        <span class="examens-kpi-valeur text-2xl font-black text-slate-900" data-motion-count="{{ $nbEpreuves }}" aria-hidden="true">{{ $nbEpreuves }}</span>
                        <span class="sr-only">{{ $nbEpreuves }} épreuves</span>
                    </dd>
                    <p class="mt-2 text-[11px] text-slate-500">Périmètre de l'examen sélectionné</p>
                </div>

                <div class="rounded-xl border border-white/70 bg-white/80 p-3">
                    <dt class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Sans salle</dt>
                    <dd class="mt-1 flex items-baseline gap-1">
                        <span class="examens-kpi-valeur text-2xl font-black {{ $sansSalle > 0 ? 'text-rose-600' : 'text-slate-900' }}" data-motion-count="{{ $sansSalle }}" aria-hidden="true">{{ $sansSalle }}</span>
                        <span class="sr-only">{{ $sansSalle }} épreuves sans salle</span>
                    </dd>
                    <p class="mt-2 text-[11px] text-slate-500">{{ $sansSalle > 0 ? 'Bloquant pour la publication' : 'Toutes les épreuves sont affectées' }}</p>
                </div>

                <div class="rounded-xl border border-white/70 bg-white/80 p-3">
                    <dt class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Sous-surveillées</dt>
                    <dd class="mt-1 flex items-baseline gap-1">
                        <span class="examens-kpi-valeur text-2xl font-black {{ $sousSurveillees > 0 ? 'text-amber-600' : 'text-slate-900' }}" data-motion-count="{{ $sousSurveillees }}" aria-hidden="true">{{ $sousSurveillees }}</span>
                        <span class="sr-only">{{ $sousSurveillees }} épreuves sous-surveillées</span>
                    </dd>
                    <p class="mt-2 text-[11px] text-slate-500">{{ $sousSurveillees > 0 ? 'Effectif de surveillants insuffisant' : 'Encadrement conforme au minimum' }}</p>
                </div>
            </dl>

            @if(!empty($bloquants))
                <div class="mt-4 rounded-xl border border-rose-200 bg-white/85 p-4">
                    <h3 class="flex flex-wrap items-center gap-2 text-xs font-bold uppercase tracking-wide text-rose-900">
                        Erreurs bloquantes interdisant la publication
                        <span class="rounded-full bg-rose-100 px-2 py-0.5 text-[10px] font-bold text-rose-700">{{ count($bloquants) }}</span>
                    </h3>
                    <ul class="mt-2 space-y-1.5 text-xs text-rose-800">
                        @foreach($bloquants as $bloquant)
                            <li class="flex gap-2">
                                <span class="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-rose-400" aria-hidden="true"></span>
                                <span>{{ $bloquant }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @else
                <p class="mt-4 rounded-xl border border-emerald-200 bg-white/80 px-4 py-3 text-xs font-medium text-emerald-900">
                    Aucun conflit bloquant détecté sur {{ $nbEpreuves }} épreuve{{ $nbEpreuves > 1 ? 's' : '' }} auditée{{ $nbEpreuves > 1 ? 's' : '' }} : la publication reste possible sous réserve des contrôles humains.
                </p>
            @endif

            @if(!empty($avertissements))
                <div class="mt-3 rounded-xl border border-amber-200 bg-white/85 p-4">
                    <h3 class="text-xs font-bold uppercase tracking-wide text-amber-900">Avertissements non bloquants</h3>
                    <ul class="mt-2 space-y-1.5 text-xs text-amber-800">
                        @foreach($avertissements as $avertissement)
                            <li class="flex gap-2">
                                <span class="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-amber-400" aria-hidden="true"></span>
                                <span>{{ $avertissement }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </section>
    @elseif($examenSelectionne)
        <div class="examens-card rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">
            Audit en attente d'exécution.
        </div>
    @else
        <section class="examens-card rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center sm:p-12" aria-labelledby="titre-aucun-examen">
            <span class="mx-auto inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-50 text-slate-400 ring-1 ring-inset ring-slate-200" aria-hidden="true">
                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 0 1-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 0 1 4.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.942A4.5 4.5 0 0 1 15.9 16.5H8.1a4.5 4.5 0 0 1-2.33-.658L4.2 14.9m15.6.4v4.35a2.25 2.25 0 0 1-2.25 2.25H6.45a2.25 2.25 0 0 1-2.25-2.25V14.9" /></svg>
            </span>
            <h2 id="titre-aucun-examen" class="mt-4 text-base font-bold text-slate-900">Lancez un audit sans rien modifier</h2>
            <p class="mx-auto mt-1 max-w-lg text-sm text-slate-500">Choisissez un examen : le moteur vérifie les salles, les capacités, les surveillants et les chevauchements, puis rend un verdict PASS / FAIL.</p>

            @if($examens->isNotEmpty())
                <div class="mt-6 flex flex-wrap items-center justify-center gap-2">
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Accès rapide</span>
                    @foreach($examens->take(3) as $ex)
                        <a href="{{ route('admin.evaluation.examens.simulation', ['examen_id' => $ex->id]) }}"
                           class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition-colors hover:border-primary-300 hover:bg-primary-50 hover:text-primary-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40">
                            <span class="font-mono text-[11px] text-slate-500">{{ $ex->code }}</span>
                            <span class="max-w-[16rem] truncate">{{ $ex->libelle }}</span>
                        </a>
                    @endforeach
                </div>
            @else
                <p class="mt-4 text-xs text-slate-500">Aucun examen n'est encore configuré pour votre périmètre.</p>
            @endif
        </section>
    @endif
</div>
@endsection