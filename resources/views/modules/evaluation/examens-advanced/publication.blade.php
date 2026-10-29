@extends('layouts.app')

@section('title', 'Examens — Validation & Publication')

@section('content')
<div class="space-y-6">
    <x-page-header
        titre="Validation et publication officielle"
        description="Contrôle final avant publication officielle, traçabilité des versions publiées immuables et prévention des doubles publications."
        :fil="['Évaluation' => route('admin.evaluations.index'), 'Examens' => route('admin.evaluation.examens.tableau-de-bord'), 'Publication' => '']"
    />

    @include('modules.evaluation.examens-advanced._nav-tabs')

    {{-- §2.15 — les écarts de pointage restent bloquants : accès direct au
         dossier persistant, instruit puis validé à quatre yeux. --}}
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3 shadow-xs">
        <p class="text-xs text-slate-600">
            Contrôlez les divergences entre la feuille papier et la base avant de publier : chaque écart est un dossier tracé.
        </p>
        <a href="{{ route('admin.evaluations.reconciliation.index') }}"
           class="inline-flex items-center gap-1 text-xs font-bold text-primary-800 underline underline-offset-2 hover:text-primary-600">
            Ouvrir la réconciliation tri-sources &rarr;
        </a>
    </div>

    {{-- Bannière Réglementaire Règle D-05 & D-06 --}}
    <div class="relative overflow-hidden rounded-2xl border border-primary-200/80 bg-gradient-to-r from-primary-50/90 via-primary-50/40 to-slate-50/60 p-5 shadow-xs">
        <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
            <div class="flex items-start gap-3.5">
                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary-800 text-white shadow-xs">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                    </svg>
                </span>
                <div>
                    <h3 class="text-sm font-bold text-primary-950">Règle D-05 — publication officielle immuable (R-27 à R-30) :</h3>
                    <p class="mt-1 text-xs text-primary-900/90 leading-relaxed max-w-4xl">
                        La publication crée une version officielle immuable. Les modifications ultérieures créent une nouvelle version révisée sans écraser silencieusement l'historique. Toute publication requiert un audit de conformité 100 % validé (absence de collision de salle, surveillance complète, espacement physique 50% D-04 respecté).
                    </p>
                </div>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <span class="inline-flex items-center gap-1.5 rounded-full bg-primary-100/80 px-3 py-1 text-xs font-semibold text-primary-900">
                    <svg class="h-3.5 w-3.5 text-primary-700" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" /></svg>
                    Scellement SHA-256
                </span>
            </div>
        </div>
    </div>

    {{-- KPI publication --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {{-- KPI 1 : En attente de publication --}}
        <div class="examens-card rounded-2xl border border-amber-200/80 bg-gradient-to-br from-amber-50/70 via-white to-white p-5 shadow-xs transition hover:shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-[11px] font-bold uppercase tracking-wider text-amber-700">En attente de publication</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-amber-100 text-amber-800 ring-4 ring-amber-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-3xl font-black tracking-tight text-slate-900">{{ $statsPublication['scheduled'] }}</span>
                <span class="text-xs font-semibold text-amber-700">ordonnancé{{ $statsPublication['scheduled'] > 1 ? 's' : '' }}</span>
            </div>
            <p class="mt-1 text-xs text-slate-500">{{ $statsPublication['epreuves_scheduled'] }} épreuve(s) ordonnancée(s)</p>
        </div>

        {{-- KPI 2 : Officiellement publiés --}}
        <div class="examens-card rounded-2xl border border-emerald-200/80 bg-gradient-to-br from-emerald-50/70 via-white to-white p-5 shadow-xs transition hover:shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-[11px] font-bold uppercase tracking-wider text-emerald-800">Officiellement publiés</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-emerald-100 text-emerald-800 ring-4 ring-emerald-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-3xl font-black tracking-tight text-emerald-950">{{ $statsPublication['published'] }}</span>
                <span class="text-xs font-semibold text-emerald-700">scellé{{ $statsPublication['published'] > 1 ? 's' : '' }}</span>
            </div>
            <p class="mt-1 text-xs text-emerald-700">{{ $statsPublication['epreuves_published'] }} épreuve(s) publiée(s)</p>
        </div>

        {{-- KPI 3 : Taux de couverture de publication --}}
        <div class="examens-card rounded-2xl border border-slate-200/80 bg-gradient-to-br from-slate-50/70 via-white to-white p-5 shadow-xs transition hover:shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-[11px] font-bold uppercase tracking-wider text-slate-600">Périmètre visible</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-slate-100 text-slate-700 ring-4 ring-slate-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" />
                    </svg>
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-3xl font-black tracking-tight text-slate-900">{{ $examensPrets->total() }}</span>
                <span class="text-xs font-semibold text-slate-500">examen(s)</span>
            </div>
            <p class="mt-1 text-xs text-slate-500">{{ $statsPublication['taux_publication'] ?? 0 }}% de taux de publication globale</p>
        </div>

        {{-- KPI 4 : Règle D-06 Verrou Convocations --}}
        <div class="examens-card rounded-2xl border border-indigo-200/80 bg-gradient-to-br from-indigo-50/70 via-white to-white p-5 shadow-xs transition hover:shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-[11px] font-bold uppercase tracking-wider text-indigo-800">Interdiction formelle pré-publication</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-indigo-100 text-indigo-800 ring-4 ring-indigo-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25z" />
                    </svg>
                </span>
            </div>
            <p class="mt-2 text-xs font-semibold text-slate-800 leading-snug">Aucune convocation n'est accessible avant publication officielle.</p>
            <p class="mt-1 text-[11px] text-indigo-700 font-medium">Déverrouillage immédiat et automatique dès scellement.</p>
        </div>
    </div>

    {{-- Filtres serveur (année / statut / recherche) --}}
    <div class="examens-card rounded-2xl border border-slate-200/80 bg-white p-4 shadow-xs">
        <form method="GET" action="{{ route('admin.evaluation.examens.publication') }}" class="flex flex-wrap items-end gap-3">
            <div>
                <label for="filtre-annee" class="block text-xs font-semibold text-slate-600">Année universitaire</label>
                <select id="filtre-annee" name="annee_id" onchange="this.form.submit()"
                        class="mt-1 rounded-xl border border-slate-200 bg-white py-1.5 pl-3 pr-8 text-sm font-medium text-slate-800 transition hover:border-slate-300 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20">
                    <option value="">Toutes les années</option>
                    @foreach($annees as $annee)
                        <option value="{{ $annee->id }}" @selected($anneeId === (int) $annee->id)>{{ $annee->libelle }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="filtre-statut" class="block text-xs font-semibold text-slate-600">Statut</label>
                <select id="filtre-statut" name="statut" onchange="this.form.submit()"
                        class="mt-1 rounded-xl border border-slate-200 bg-white py-1.5 pl-3 pr-8 text-sm font-medium text-slate-800 transition hover:border-slate-300 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20">
                    <option value="">Tous (ordonnancés & publiés)</option>
                    <option value="scheduled" @selected($statutFiltre === 'scheduled')>En attente de publication</option>
                    <option value="published" @selected($statutFiltre === 'published')>Officiellement publiés</option>
                </select>
            </div>
            <div class="min-w-[12rem] grow">
                <label for="filtre-recherche" class="block text-xs font-semibold text-slate-600">Recherche (code ou libellé)</label>
                <input id="filtre-recherche" type="search" name="recherche" value="{{ $recherche }}"
                       placeholder="Ex : EX-2026…"
                       class="mt-1 w-full rounded-xl border border-slate-200 bg-white py-1.5 px-3 text-sm text-slate-800 transition hover:border-slate-300 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20">
            </div>
            <button type="submit" class="rounded-xl bg-primary-800 px-4 py-2 text-sm font-semibold text-white shadow-xs transition hover:bg-primary-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/50 focus-visible:ring-offset-1">
                Filtrer
            </button>
            @if($statutFiltre !== null || $recherche !== null || $anneeId !== null)
                <a href="{{ route('admin.evaluation.examens.publication') }}"
                   class="text-xs font-semibold text-slate-500 underline-offset-2 hover:text-slate-700 hover:underline">Réinitialiser</a>
            @endif
        </form>
    </div>

    {{-- Examens candidats à la publication et statut de conformité --}}
    <div class="examens-card rounded-2xl border border-slate-200/80 bg-white shadow-xs overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4 bg-slate-50/50">
            <div>
                <h3 class="font-bold text-base text-slate-900">Examens en attente de publication ou publiés</h3>
                <p class="text-xs text-slate-500 mt-0.5">Seuls les examens ordonnancés (<code class="rounded bg-slate-100 px-1 font-mono text-[11px]">scheduled</code>) et strictement conformes sont publiables ; les versions publiées restent immuables.</p>
            </div>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
                <span class="font-bold tabular-nums">{{ $examensPrets->total() }}</span> examen(s)
            </span>
        </div>

        <div class="overflow-x-auto px-6 py-2">
            <table class="examens-tableau w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-100 text-[11px] font-bold uppercase tracking-wider text-slate-500">
                        <th scope="col" class="py-3 pr-4">Examen & code</th>
                        <th scope="col" class="py-3 pr-4">Session & Programme</th>
                        <th scope="col" class="py-3 pr-4">Épreuves</th>
                        <th scope="col" class="py-3 pr-4">Statut actuel</th>
                        <th scope="col" class="py-3 pr-4">Conformité Pré-publication</th>
                        <th scope="col" class="py-3 pr-4">Date de publication</th>
                        <th scope="col" class="py-3 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($examensPrets as $ex)
                        @php
                            $diag = $ex->diagnostic_publication ?? ['etat' => 'inconnu', 'conforme' => false, 'score' => 0, 'conflits' => []];
                            $empreinte = $ex->empreinte_sha256 ?? null;
                        @endphp
                        <tr class="hover:bg-slate-50/60 transition">
                            <td class="py-4 pr-4">
                                <span class="font-bold text-slate-900 block">{{ $ex->libelle }}</span>
                                <div class="flex items-center gap-2 mt-0.5">
                                    <span class="font-mono text-xs text-slate-500">#{{ $ex->code }}</span>
                                    @if($ex->statut === 'published' && $empreinte)
                                        <span class="font-mono text-[10px] text-slate-400" title="Empreinte SHA-256 du planning">
                                            SHA: {{ substr($empreinte, 0, 8) }}…
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td class="py-4 pr-4 text-xs text-slate-600">
                                <span class="font-semibold text-slate-800 block">{{ $ex->session?->nom ?? '—' }}</span>
                                <span class="text-slate-500">{{ $ex->programme?->nom ?? ($ex->groupe?->nom ?? 'Tronc commun') }}</span>
                            </td>
                            <td class="py-4 pr-4 font-semibold tabular-nums text-slate-800 text-xs">
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-slate-100 font-mono">
                                    {{ $ex->epreuves_count }} épreuve{{ $ex->epreuves_count > 1 ? 's' : '' }}
                                </span>
                            </td>
                            <td class="py-4 pr-4">
                                @if($ex->statut === 'published')
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-800">
                                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-600"></span>
                                        published
                                    </span>
                                @elseif($ex->statut === 'scheduled')
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-800">
                                        <span class="h-1.5 w-1.5 rounded-full bg-amber-600"></span>
                                        scheduled
                                    </span>
                                @else
                                    <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-700">
                                        {{ $ex->statut }}
                                    </span>
                                @endif
                            </td>
                            <td class="py-4 pr-4">
                                @if($ex->statut === 'published')
                                    <span class="inline-flex items-center gap-1 rounded-md bg-emerald-50 border border-emerald-200/80 px-2 py-1 text-[11px] font-bold text-emerald-800">
                                        <svg class="h-3.5 w-3.5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                        Scellé & Conforme (100%)
                                    </span>
                                @elseif($diag['conforme'])
                                    <span class="inline-flex items-center gap-1 rounded-md bg-emerald-50 border border-emerald-200/80 px-2 py-1 text-[11px] font-bold text-emerald-800">
                                        <svg class="h-3.5 w-3.5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                        Conforme (Prêt à publier)
                                    </span>
                                @else
                                    <div class="space-y-1">
                                        <span class="inline-flex items-center gap-1 rounded-md bg-rose-50 border border-rose-200/80 px-2 py-1 text-[11px] font-bold text-rose-800">
                                            <svg class="h-3.5 w-3.5 text-rose-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                                            Non conforme ({{ count($diag['conflits']) }} conflit{{ count($diag['conflits']) > 1 ? 's' : '' }})
                                        </span>
                                        <p class="text-[10px] font-bold text-rose-700">
                                            Publication bloquée : la correspondance matière/groupes n'est pas validée.
                                        </p>
                                        @if(!empty($diag['conflits']))
                                            <details class="text-[11px] text-rose-700 font-medium">
                                                <summary class="cursor-pointer hover:underline text-[10px] text-slate-500">Voir les anomalies bloquantes</summary>
                                                <ul class="mt-1 list-disc pl-3 space-y-0.5 text-[10px]">
                                                    @foreach(array_slice($diag['conflits'], 0, 3) as $conf)
                                                        <li>{{ $conf }}</li>
                                                    @endforeach
                                                    @if(count($diag['conflits']) > 3)
                                                        <li>… et {{ count($diag['conflits']) - 3 }} autre(s)</li>
                                                    @endif
                                                </ul>
                                            </details>
                                        @endif
                                    </div>
                                @endif
                            </td>
                            <td class="py-4 pr-4 text-xs text-slate-600 tabular-nums">
                                @if($ex->published_at)
                                    <span class="font-semibold text-slate-800 block">{{ $ex->published_at->format('d/m/Y') }}</span>
                                    <span class="text-[11px] text-slate-400 font-mono">{{ $ex->published_at->format('H:i') }}</span>
                                @else
                                    <span class="text-slate-400 italic">Non publié</span>
                                @endif
                            </td>
                            <td class="py-4 text-right">
                                @if(in_array($ex->statut, ['scheduled', 'published'], true))
                                    @if(! $diag['conforme'] && $ex->statut === 'scheduled')
                                        <div class="mb-2 inline-flex items-center gap-1">
                                            <button type="button" disabled class="cursor-not-allowed rounded-xl bg-slate-100 text-slate-400 px-3 py-1.5 text-xs font-semibold" title="Publication bloquée : résolvez les conflits d'abord.">
                                                Publication bloquée
                                            </button>
                                            <a href="{{ route('admin.evaluation.examens.planification') }}" class="text-[11px] font-semibold text-primary-700 hover:underline">
                                                Résoudre &rarr;
                                            </a>
                                        </div>
                                    @endif
                                    @include('modules.evaluation.examens-advanced._governance-actions', ['examen' => $ex])
                                @else
                                    <span class="text-xs text-slate-400">Non prêt</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-12 text-center text-sm text-slate-500">
                                Aucun examen ne correspond aux filtres actifs.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="border-t border-slate-100 px-6 py-4 bg-slate-50/30">
            {{ $examensPrets->withQueryString()->links() }}
        </div>
    </div>

    {{-- Panneau Enterprise — Protocole de Scellement & Intégrité --}}
    <div class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs">
        <h3 class="font-bold text-sm text-slate-900 mb-2">Protocole de Scellement & Intégrité Académique (Enterprise)</h3>
        <p class="text-xs text-slate-600 mb-4 leading-relaxed">
            UniRegistre garantit l'inviolabilité juridique des plannings d'examen grâce à un protocole en quatre étapes successives :
        </p>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-slate-100 bg-slate-50/50 p-4">
                <span class="text-xs font-bold text-slate-900 block">1. Audit des Invariants Durs</span>
                <p class="text-[11px] text-slate-500 mt-1">Zéro chevauchement d'étudiant, de salle ou d'enseignant. Contrôle automatique des capacités d'accueil utiles à 50% (D-04).</p>
            </div>
            <div class="rounded-xl border border-slate-100 bg-slate-50/50 p-4">
                <span class="text-xs font-bold text-slate-900 block">2. Validation des Ratios de Surveillance</span>
                <p class="text-[11px] text-slate-500 mt-1">Vérification de la présence minimale de 2 surveillants par salle et du respect des ratios étudiants réglementaires.</p>
            </div>
            <div class="rounded-xl border border-slate-100 bg-slate-50/50 p-4">
                <span class="text-xs font-bold text-slate-900 block">3. Empreinte Cryptographique SHA-256</span>
                <p class="text-[11px] text-slate-500 mt-1">Génération d'un sceau cryptographique immuable attestant de l'état exact du calendrier au moment précis de la publication.</p>
            </div>
            <div class="rounded-xl border border-slate-100 bg-slate-50/50 p-4">
                <span class="text-xs font-bold text-slate-900 block">4. Déverrouillage des Convocations (D-06)</span>
                <p class="text-[11px] text-slate-500 mt-1">Diffusion instantanée et sécurisée des convocations individuelles avec QR code anti-fraude normé ISO/IEC 18004.</p>
            </div>
        </div>
    </div>

    {{-- Zone dédiée aux documents professionnels (LOT 2) --}}
    @include('modules.evaluation.examens-advanced._zone-documents-professionnels', ['optionCourante' => 'publication'])
</div>
@endsection
