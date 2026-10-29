@extends('layouts.app')

@section('title', 'Examens — Convocations')

@section('content')
<!-- GSAP + Motion init -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || !window.gsap) {
            document.documentElement.classList.add('has-motion');
            return;
        }

        try {
            if (window.Lenis) {
                const lenis = new window.Lenis({
                    smoothWheel: true,
                    lerp: 0.08,
                    wheelMultiplier: 0.9,
                    anchors: true,
                });
                function smoothRaf(time) { lenis.raf(time * 1000); }
                requestAnimationFrame(smoothRaf);
                if (window.ScrollTrigger) {
                    lenis.on('scroll', ScrollTrigger.update);
                }
                gsap.ticker.add(smoothRaf);
                gsap.ticker.lagSmoothing(0);
            }

            document.documentElement.classList.add('motion-ready');

            // Cartes KPI en cascade
            gsap.utils.toArray('.convoc-kpi-card').forEach(function(el, i) {
                gsap.fromTo(el, 
                    { y: 18, opacity: 0 }, 
                    { y: 0, opacity: 1, duration: 0.45, ease: 'power2.out', delay: i * 0.08 }
                );
            });

            // Tableau fade-in
            const tableCard = document.querySelector('.convoc-table-card');
            if (tableCard) {
                gsap.fromTo(tableCard,
                    { y: 24, opacity: 0 },
                    { y: 0, opacity: 1, duration: 0.5, ease: 'power2.out', delay: 0.3 }
                );
            }
        } catch (e) {
            console.debug('Motion initialization note:', e);
        }
    });
</script>

@php
    $totalEprPub = $statsConvocations['epreuves_publiees'] ?? 0;
    $totalCandConv = $statsConvocations['candidats_convoques'] ?? 0;
    $totalSalles = $statsConvocations['salles_utilisees'] ?? 0;
    $totalDocs = $statsConvocations['documents_generes'] ?? 0;
@endphp

<div class="space-y-6">
    {{-- En-tête de la page --}}
    <x-page-header
        titre="Convocations aux examens"
        description="Génération, distribution et téléchargement des convocations individuelles et des listes d'émargement officielles."
        :fil="['Évaluation' => route('admin.evaluations.index'), 'Examens' => route('admin.evaluation.examens.tableau-de-bord'), 'Convocations' => '']"
    >
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2.5">
                <a href="{{ route('admin.evaluation.examens.publication') }}" 
                   class="inline-flex items-center gap-2 rounded-xl border border-slate-200/90 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 shadow-2xs hover:bg-slate-50 hover:text-slate-900 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40">
                    <svg class="h-4 w-4 text-primary-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                    </svg>
                    <span>Vérifier la publication (Menu 10)</span>
                </a>
            </div>
        </x-slot:actions>
    </x-page-header>

    {{-- Barre de navigation transversale des 12 menus --}}
    @include('modules.evaluation.examens-advanced._nav-tabs')

    {{-- Cartes KPI de synthèse --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {{-- KPI 1 : Épreuves avec convocations prêtes --}}
        <div class="convoc-kpi-card relative overflow-hidden rounded-2xl border border-emerald-200/80 bg-gradient-to-br from-emerald-50/70 via-white to-white p-5 shadow-xs transition hover:shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-[11px] font-bold uppercase tracking-wider text-emerald-700">Épreuves publiées</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700 ring-4 ring-emerald-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-3xl font-black tracking-tight text-slate-900">{{ $totalEprPub }}</span>
                <span class="text-xs font-semibold text-emerald-700">épreuve{{ $totalEprPub > 1 ? 's' : '' }}</span>
            </div>
            <p class="mt-1 text-xs text-slate-500">Convocations déverrouillées et actives</p>
        </div>

        {{-- KPI 2 : Candidats convoqués --}}
        <div class="convoc-kpi-card relative overflow-hidden rounded-2xl border border-primary-200/80 bg-gradient-to-br from-primary-50/70 via-white to-white p-5 shadow-xs transition hover:shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-[11px] font-bold uppercase tracking-wider text-primary-800">Candidats convoqués</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-primary-100 text-primary-800 ring-4 ring-primary-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                    </svg>
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-3xl font-black tracking-tight text-slate-900">{{ $totalCandConv }}</span>
                <span class="text-xs font-semibold text-primary-800">étudiants</span>
            </div>
            <p class="mt-1 text-xs text-slate-500">Places et numéros d'anonymat attribués</p>
        </div>

        {{-- KPI 3 : Salles d'examens mobilisées --}}
        <div class="convoc-kpi-card relative overflow-hidden rounded-2xl border border-slate-200/80 bg-white p-5 shadow-xs transition hover:shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-[11px] font-bold uppercase tracking-wider text-slate-600">Salles mobilisées</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-slate-100 text-slate-700 ring-4 ring-slate-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.75a1.5 1.5 0 0 1 1.5-1.5h1.5a1.5 1.5 0 0 1 1.5 1.5V21" />
                    </svg>
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-3xl font-black tracking-tight text-slate-900">{{ $totalSalles }}</span>
                <span class="text-xs font-medium text-slate-500">salles actives</span>
            </div>
            <p class="mt-1 text-xs text-slate-500">Amphithéâtres et salles d'épreuve</p>
        </div>

        {{-- KPI 4 : Documents émis --}}
        <div class="convoc-kpi-card relative overflow-hidden rounded-2xl border border-indigo-200/80 bg-gradient-to-br from-indigo-50/70 via-white to-white p-5 shadow-xs transition hover:shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-[11px] font-bold uppercase tracking-wider text-indigo-700">Documents générés</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-indigo-100 text-indigo-700 ring-4 ring-indigo-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                    </svg>
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-3xl font-black tracking-tight text-slate-900">{{ $totalDocs }}</span>
                <span class="text-xs font-semibold text-indigo-700">PDF certifiés</span>
            </div>
            <p class="mt-1 text-xs text-slate-500">Émargements et convocations</p>
        </div>
    </div>

    {{-- Bannière Règle D-06 (Protection pré-publication) --}}
    <div class="rounded-2xl border border-rose-200/80 bg-gradient-to-r from-rose-50/70 via-amber-50/40 to-white p-4.5 shadow-2xs">
        <div class="flex items-start gap-3.5">
            <div class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-rose-600 text-white shadow-xs">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                </svg>
            </div>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-xs font-bold uppercase tracking-wider text-rose-900">Interdiction formelle pré-publication (Décision D-06 & R-31)</h2>
                    <span class="rounded-full bg-rose-100 px-2 py-0.5 text-[10px] font-bold text-rose-800">Sécurité stricte</span>
                </div>
                <p class="mt-1 text-xs leading-relaxed text-slate-700">
                    Aucune convocation individuelle officielle ne peut être diffusée ou imprimée pour un examen en statut brouillon ou non scellé.
                    Seules les épreuves rattachées à un examen en statut <strong>publié</strong> ou ultérieur sont éligibles au téléchargement.
                </p>
            </div>
            <a href="{{ route('admin.evaluation.examens.publication') }}" 
               class="hidden md:inline-flex shrink-0 items-center gap-1.5 rounded-xl border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-800 shadow-2xs hover:bg-rose-50 transition">
                <span>Gérer les publications</span>
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                </svg>
            </a>
        </div>
    </div>

    {{-- Protocole Anti-Fraude & QR Code Cryptographique (Enterprise Standard) --}}
    <div class="rounded-2xl border border-indigo-200/80 bg-gradient-to-r from-indigo-50/60 via-slate-50/40 to-white p-4.5 shadow-2xs">
        <div class="flex items-start gap-3.5">
            <div class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-indigo-600 text-white shadow-xs">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0 1 3.75 9.375v-4.5ZM3.75 14.625c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5a1.125 1.125 0 0 1-1.125-1.125v-4.5ZM13.5 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0 1 13.5 9.375v-4.5Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 6.75h.75v.75h-.75v-.75ZM6.75 16.5h.75v.75h-.75v-.75ZM16.5 6.75h.75v.75h-.75v-.75ZM13.5 13.5h.75v.75h-.75v-.75ZM13.5 19.5h.75v.75h-.75v-.75ZM16.5 16.5h.75v.75h-.75v-.75ZM19.5 13.5h.75v.75h-.75v-.75ZM19.5 19.5h.75v.75h-.75v-.75ZM16.5 13.5h.75v.75h-.75v-.75ZM13.5 16.5h.75v.75h-.75v-.75Z" />
                </svg>
            </div>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-xs font-bold uppercase tracking-wider text-indigo-950">Sécurisation Anti-Fraude & QR Code Cryptographique</h2>
                    <span class="rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-bold text-indigo-800">Protocole Scellé</span>
                </div>
                <p class="mt-1 text-xs leading-relaxed text-slate-700">
                    Chaque convocation PDF émise intègre un QR Code infalsifiable encodé au standard <strong>UniRegistre Secure-QR</strong> contenant le matricule, l'identifiant épreuve, le numéro d'anonymat certifié (<code class="font-mono text-indigo-700 font-semibold">AN-XXXX</code>) et une empreinte cryptographique HMAC SHA-256 permettant le contrôle d'accès hors-ligne le jour de l'épreuve.
                </p>
                <div class="mt-2.5 flex flex-wrap items-center gap-4 text-[11px] text-slate-600 font-medium">
                    <span class="inline-flex items-center gap-1.5 text-emerald-700">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                        Signature HMAC SHA-256 active
                    </span>
                    <span class="inline-flex items-center gap-1.5 text-indigo-700">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                        Conforme anonymat strict
                    </span>
                    <span class="inline-flex items-center gap-1.5 text-slate-600">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                        Vérifiable sans connexion internet
                    </span>
                </div>
            </div>
        </div>
    </div>

    {{-- Barre d'outils et filtres --}}
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200/80 bg-white p-3.5 shadow-xs">
        <form method="GET" action="{{ route('admin.evaluation.examens.convocations') }}" class="flex flex-wrap items-center gap-2.5 flex-1 min-w-[280px]">
            {{-- Filtre Session --}}
            <div class="w-52">
                <select name="session_id" onchange="this.form.submit()" 
                        class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                    <option value="">Toutes les sessions</option>
                    @foreach($sessionsDisponibles as $sess)
                        <option value="{{ $sess->id }}" @selected($sessionFiltre === (int)$sess->id)>{{ $sess->nom }} ({{ $sess->code }})</option>
                    @endforeach
                </select>
            </div>

            {{-- Filtre Date --}}
            <div class="w-40">
                <input type="date" name="date" value="{{ $dateFiltre }}" onchange="this.form.submit()" 
                       class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs text-slate-800 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500" />
            </div>

            {{-- Recherche texte --}}
            <div class="relative flex-1 min-w-[180px] max-w-xs">
                <input type="text" name="recherche" value="{{ $recherche }}" placeholder="Rechercher matière, code, salle…"
                       class="w-full rounded-xl border border-slate-200 bg-white pl-8 pr-3 py-1.5 text-xs text-slate-800 placeholder-slate-400 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500" />
                <svg class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                </svg>
            </div>

            <button type="submit" class="rounded-xl bg-slate-100 hover:bg-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 transition">
                Filtrer
            </button>

            @if($sessionFiltre || $dateFiltre || $recherche)
                <a href="{{ route('admin.evaluation.examens.convocations') }}" class="text-xs font-medium text-rose-600 hover:underline px-1">
                    Réinitialiser
                </a>
            @endif
        </form>

        <span class="text-xs font-medium text-slate-500">
            <span class="font-bold tabular-nums text-slate-800">{{ $epreuvesPubliees->total() }}</span> épreuve{{ $epreuvesPubliees->total() > 1 ? 's' : '' }} publiée{{ $epreuvesPubliees->total() > 1 ? 's' : '' }}
        </span>
    </div>

    {{-- Tableau des épreuves publiées --}}
    <div class="convoc-table-card rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
            <div>
                <h3 class="font-bold text-sm text-slate-900">Épreuves publiées prêtes pour convocations & émargements</h3>
                <p class="text-xs text-slate-500 mt-0.5">Accédez directement aux feuilles d'émargement et aux convocations nominatives.</p>
            </div>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-800 ring-1 ring-emerald-200/60">
                <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                Diffusion autorisée
            </span>
        </div>

        <div class="overflow-x-auto mt-4">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-100 text-[11px] font-bold uppercase tracking-wider text-slate-500">
                        <th class="pb-3 pl-1">Épreuve & Matière</th>
                        <th class="pb-3">Session</th>
                        <th class="pb-3">Date & Horaires</th>
                        <th class="pb-3">Salle d'examen</th>
                        <th class="pb-3">Candidats</th>
                        <th class="pb-3 pr-1 text-right">Documents PDF</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($epreuvesPubliees as $epr)
                        <tr class="hover:bg-slate-50/60 transition group">
                            {{-- Code & Matière --}}
                            <td class="py-3.5 pl-1">
                                <div class="flex items-start gap-3">
                                    <span class="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-primary-100 font-mono text-xs font-bold text-primary-800">
                                        {{ substr($epr->code, 0, 3) }}
                                    </span>
                                    <div class="min-w-0">
                                        <span class="font-bold text-slate-900 block group-hover:text-primary-800 transition">
                                            {{ $epr->matiere?->nom ?? ($epr->matiere?->libelle_complet ?? 'Matière #'.$epr->matiere_id) }}
                                        </span>
                                        <div class="flex items-center gap-2 mt-0.5">
                                            <span class="font-mono text-[11px] font-medium text-slate-500">#{{ $epr->code }}</span>
                                            @if($epr->matiere?->code)
                                                <span class="inline-flex items-center rounded-md bg-slate-100 px-1.5 py-0.2 text-[10px] font-medium text-slate-600">
                                                    {{ $epr->matiere->code }}
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </td>

                            {{-- Session --}}
                            <td class="py-3.5 text-slate-600">
                                <span class="font-semibold text-slate-800 block text-xs">{{ $epr->session?->nom ?? '—' }}</span>
                                <span class="text-[11px] text-slate-500 block mt-0.5">
                                    {{ ucfirst($epr->session?->type ?? 'Principale') }}
                                </span>
                            </td>

                            {{-- Date & Horaires --}}
                            <td class="py-3.5">
                                <span class="font-semibold text-slate-900 text-xs block">
                                    {{ $epr->date?->format('d/m/Y') ?? 'Non planifiée' }}
                                </span>
                                <span class="inline-flex items-center gap-1 text-[11px] text-slate-500 font-mono mt-0.5">
                                    <svg class="h-3 w-3 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                    </svg>
                                    {{ substr((string)$epr->heure_debut, 0, 5) }} &rarr; {{ substr((string)$epr->heure_fin, 0, 5) }}
                                </span>
                            </td>

                            {{-- Salle --}}
                            <td class="py-3.5">
                                @if($epr->salle)
                                    <span class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2 py-0.5 text-xs font-semibold text-slate-800 shadow-2xs">
                                        <svg class="h-3.5 w-3.5 text-primary-700" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21" />
                                        </svg>
                                        {{ $epr->salle->code }}
                                    </span>
                                    <span class="block text-[10.5px] text-slate-500 mt-0.5 font-medium">
                                        Capacité : {{ $epr->salle->capacite ?? '—' }} pl.
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 text-xs text-amber-700 font-medium">
                                        <svg class="h-3.5 w-3.5 text-amber-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                                        </svg>
                                        Non assignée
                                    </span>
                                @endif
                            </td>

                            {{-- Candidats --}}
                            <td class="py-3.5">
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-primary-50 px-2.5 py-0.5 text-xs font-bold text-primary-800 ring-1 ring-primary-100">
                                    <svg class="h-3 w-3 text-primary-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                                    </svg>
                                    {{ $epr->candidats_count }} candidat{{ $epr->candidats_count > 1 ? 's' : '' }}
                                </span>
                            </td>

                            {{-- Actions Documents PDF --}}
                            <td class="py-3.5 pr-1 text-right">
                                <div class="inline-flex items-center justify-end gap-2">
                                    {{-- Émargement PDF --}}
                                    <a href="{{ route('admin.evaluations.epreuves.emargement', $epr) }}" target="_blank"
                                       class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-2xs hover:bg-slate-50 hover:text-slate-900 transition">
                                        <svg class="h-3.5 w-3.5 text-primary-700" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                                        </svg>
                                        <span>Émargement</span>
                                    </a>

                                    {{-- Convocations --}}
                                    <a href="{{ route('admin.evaluations.epreuves.convocation', $epr) }}"
                                       class="inline-flex items-center gap-1.5 rounded-xl bg-primary-800 hover:bg-primary-900 text-white px-3 py-1.5 text-xs font-bold shadow-xs hover:shadow-sm transition">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-5.25h5.25M7.5 15h3M3.375 5.25c-.621 0-1.125.504-1.125 1.125v3.026a2.999 2.999 0 0 1 0 5.198v3.026c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-3.026a2.999 2.999 0 0 1 0-5.198V6.375c0-.621-.504-1.125-1.125-1.125H3.375Z" />
                                        </svg>
                                        <span>Convocations</span>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-12 text-center">
                                <div class="mx-auto max-w-sm text-center">
                                    <div class="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-400">
                                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                                        </svg>
                                    </div>
                                    <h4 class="mt-3 text-sm font-bold text-slate-800">Aucune épreuve publiée disponible</h4>
                                    <p class="mt-1 text-xs text-slate-500">
                                        Les convocations ne sont accessibles que pour les épreuves des examens ayant fait l'objet d'une publication officielle (règle D-06).
                                    </p>
                                    <div class="mt-4 flex items-center justify-center gap-2">
                                        <a href="{{ route('admin.evaluation.examens.publication') }}" 
                                           class="inline-flex items-center gap-1.5 rounded-xl bg-primary-800 px-3.5 py-1.5 text-xs font-semibold text-white shadow-xs hover:bg-primary-900 transition">
                                            Valider et publier un examen
                                        </a>
                                        @if($sessionFiltre || $dateFiltre || $recherche)
                                            <a href="{{ route('admin.evaluation.examens.convocations') }}" 
                                               class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition">
                                                Effacer les filtres
                                            </a>
                                        @endif
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        @if($epreuvesPubliees->hasPages())
            <div class="mt-5 pt-4 border-t border-slate-100">
                {{ $epreuvesPubliees->links() }}
            </div>
        @endif
    </div>

    {{-- Section Documents récents générés --}}
    @if($documentsGeneres->isNotEmpty())
        <div class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <div>
                    <h3 class="font-bold text-sm text-slate-900">Derniers documents d'examen générés</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Historique des PDF certifiés récents pour archivage et réimpression immédiate.</p>
                </div>
                <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-600">
                    <svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                    Dernières générations
                </span>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3 mt-4">
                @foreach($documentsGeneres as $doc)
                    <div class="flex items-start justify-between gap-3 rounded-xl border border-slate-100 bg-slate-50/60 p-3 hover:bg-slate-50 transition">
                        <div class="min-w-0 flex items-start gap-2.5">
                            <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary-100 text-primary-700">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                                </svg>
                            </span>
                            <div class="min-w-0">
                                <span class="font-bold text-xs text-slate-800 block truncate">{{ $doc->nom_fichier ?? 'Document #'.$doc->id }}</span>
                                <div class="flex items-center gap-2 mt-0.5">
                                    <span class="inline-flex rounded-md bg-slate-100 px-1.5 py-0.2 text-[10px] font-semibold text-slate-700">
                                        {{ ucfirst($doc->type ?? 'émargement') }}
                                    </span>
                                    @if($doc->candidats_count)
                                        <span class="text-[11px] text-slate-500 font-medium">
                                            {{ $doc->candidats_count }} candidat{{ $doc->candidats_count > 1 ? 's' : '' }}
                                        </span>
                                    @endif
                                </div>
                                <span class="text-[10px] text-slate-400 block font-mono mt-0.5">
                                    {{ $doc->created_at?->format('d/m/Y H:i') }}
                                </span>
                            </div>
                        </div>
                        @if($doc->epreuve_id)
                            @if($doc->type === 'convocation')
                                <a href="{{ route('admin.evaluations.epreuves.convocation', $doc->epreuve_id) }}"
                                   class="inline-flex shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-white p-1.5 text-slate-600 hover:text-primary-800 shadow-2xs transition"
                                   title="Voir la convocation">
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                    </svg>
                                </a>
                            @else
                                <a href="{{ route('admin.evaluations.epreuves.emargement', $doc->epreuve_id) }}" target="_blank"
                                   class="inline-flex shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-white p-1.5 text-slate-600 hover:text-primary-800 shadow-2xs transition"
                                   title="Télécharger l'émargement">
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                    </svg>
                                </a>
                            @endif
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Zone dédiée aux documents professionnels (LOT 2) --}}
    @include('modules.evaluation.examens-advanced._zone-documents-professionnels', ['optionCourante' => 'convocations'])
</div>
@endsection