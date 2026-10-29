@extends('layouts.app')

@section('title', 'Examens — Surveillance & Ratios d\'encadrement')

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

            gsap.utils.toArray('.surv-kpi-card').forEach(function(el, i) {
                gsap.fromTo(el, 
                    { y: 18, opacity: 0 }, 
                    { y: 0, opacity: 1, duration: 0.45, ease: 'power2.out', delay: i * 0.08 }
                );
            });
        } catch (e) {
            console.debug('Motion note:', e);
        }
    });
</script>

@php
    $totalGardes = $kpisSurveillance['surveillances_actives'] ?? 0;
    $tauxCouv = $kpisSurveillance['taux_couverture'] ?? 100.0;
    $sousSurveillees = $kpisSurveillance['epreuves_sous_surveillees'] ?? 0;
    $sansSurveillant = $kpisSurveillance['epreuves_sans_surveillant'] ?? 0;
    $enseignantsMobilises = $kpisSurveillance['enseignants_mobilises'] ?? 0;
@endphp

<div class="space-y-6">
    <x-page-header
        titre="Surveillance & Ratios d'encadrement"
        description="Affectation des enseignants aux épreuves, calcul des besoins légaux d'encadrement, prévention des conflits d'horaires et traçabilité des remplacements."
        :fil="['Évaluation' => route('admin.evaluations.index'), 'Examens' => route('admin.evaluation.examens.tableau-de-bord'), 'Surveillance' => '']"
    >
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.evaluations.affectations.index') }}" 
                   class="inline-flex items-center gap-2 rounded-xl bg-primary-800 px-3.5 py-2 text-xs font-semibold text-white shadow-xs hover:bg-primary-900 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 12c0-1.232-.046-2.453-.138-3.662a4.006 4.006 0 0 0-3.7-3.7 48.678 48.678 0 0 0-7.324 0 4.006 4.006 0 0 0-3.7 3.7c-.017.22-.032.441-.046.662M19.5 12l3-3m-3 3-3-3m-12 3c0 1.232.046 2.453.138 3.662a4.006 4.006 0 0 0 3.7 3.7 48.656 48.656 0 0 0 7.324 0 4.006 4.006 0 0 0 3.7-3.7c.017-.22.032-.441.046-.662M4.5 12l3 3m-3-3-3 3" />
                    </svg>
                    <span>Moteur d'affectation automatique</span>
                </a>
            </div>
        </x-slot:actions>
    </x-page-header>

    @include('modules.evaluation.examens-advanced._nav-tabs')

    {{-- Cartes KPI de synthèse --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {{-- KPI 1 : Gardes actives --}}
        <div class="surv-kpi-card relative overflow-hidden rounded-2xl border border-primary-200/80 bg-gradient-to-br from-primary-50/70 via-white to-white p-5 shadow-xs transition hover:shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-[11px] font-bold uppercase tracking-wider text-primary-800">Gardes actives</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-primary-100 text-primary-800 ring-4 ring-primary-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-3xl font-black tracking-tight text-slate-900">{{ $totalGardes }}</span>
                <span class="text-xs font-semibold text-primary-800">gardes</span>
            </div>
            <p class="mt-1 text-xs text-slate-500">Affectations confirmées ou assignées</p>
        </div>

        {{-- KPI 2 : Taux de couverture légale --}}
        <div class="surv-kpi-card relative overflow-hidden rounded-2xl border border-emerald-200/80 bg-gradient-to-br from-emerald-50/70 via-white to-white p-5 shadow-xs transition hover:shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-[11px] font-bold uppercase tracking-wider text-emerald-700">Couverture d'encadrement</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700 ring-4 ring-emerald-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17 17.25 21A2.67 2.67 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.07a4.5 4.5 0 0 0 4.486-6.32l-3.27 3.27-2.959-2.959 3.27-3.27A4.5 4.5 0 0 0 12.01 4.5c-.58.058-1.193.082-1.743.246" />
                    </svg>
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-3xl font-black tracking-tight text-slate-900">{{ $tauxCouv }}%</span>
                <span class="text-xs font-semibold text-emerald-700">quota atteint</span>
            </div>
            <p class="mt-1 text-xs text-slate-500">Épreuves avec quota complet de surveillants</p>
        </div>

        {{-- KPI 3 : Épreuves sous-surveillées --}}
        <div class="surv-kpi-card relative overflow-hidden rounded-2xl border {{ $sousSurveillees > 0 || $sansSurveillant > 0 ? 'border-rose-300/90 bg-gradient-to-br from-rose-50/70 via-white to-white' : 'border-slate-200/80 bg-white' }} p-5 shadow-xs transition hover:shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-[11px] font-bold uppercase tracking-wider {{ $sousSurveillees > 0 || $sansSurveillant > 0 ? 'text-rose-700' : 'text-slate-600' }}">Épreuves à renforcer</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl {{ $sousSurveillees > 0 || $sansSurveillant > 0 ? 'bg-rose-100 text-rose-700 ring-4 ring-rose-50' : 'bg-slate-100 text-slate-600 ring-4 ring-slate-50' }}">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                    </svg>
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-3xl font-black tracking-tight {{ $sousSurveillees > 0 || $sansSurveillant > 0 ? 'text-rose-700' : 'text-slate-900' }}">{{ $sousSurveillees + $sansSurveillant }}</span>
                <span class="text-xs font-semibold {{ $sousSurveillees > 0 || $sansSurveillant > 0 ? 'text-rose-700' : 'text-slate-500' }}">épreuve{{ ($sousSurveillees + $sansSurveillant) > 1 ? 's' : '' }}</span>
            </div>
            <p class="mt-1 text-xs text-slate-500">{{ $sansSurveillant }} sans aucun surveillant, {{ $sousSurveillees }} sous quota</p>
        </div>

        {{-- KPI 4 : Enseignants mobilisés --}}
        <div class="surv-kpi-card relative overflow-hidden rounded-2xl border border-indigo-200/80 bg-gradient-to-br from-indigo-50/70 via-white to-white p-5 shadow-xs transition hover:shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-[11px] font-bold uppercase tracking-wider text-indigo-700">Enseignants mobilisés</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-indigo-100 text-indigo-700 ring-4 ring-indigo-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                    </svg>
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-3xl font-black tracking-tight text-slate-900">{{ $enseignantsMobilises }}</span>
                <span class="text-xs font-semibold text-indigo-700">surveillants actifs</span>
            </div>
            <p class="mt-1 text-xs text-slate-500">Sur {{ $totalSurveillants }} enseignants disponibles</p>
        </div>
    </div>

    {{-- Audit d'encadrement des épreuves (Ratios réglementaires) --}}
    @if(!empty($epreuvesAuditees))
        <div class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs">
            <div class="flex items-center justify-between pb-4 border-b border-slate-100">
                <div>
                    <h3 class="font-bold text-sm text-slate-900">Audit de conformité des ratios d'encadrement par épreuve</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Calculé automatiquement selon les effectifs étudiants et les normes de sécurité en salle.</p>
                </div>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                    {{ count($epreuvesAuditees) }} épreuve{{ count($epreuvesAuditees) > 1 ? 's' : '' }} auditée{{ count($epreuvesAuditees) > 1 ? 's' : '' }}
                </span>
            </div>

            <div class="overflow-x-auto mt-4">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] font-bold uppercase tracking-wider text-slate-500">
                            <th class="pb-3 pl-1">Épreuve & Matière</th>
                            <th class="pb-3">Date & Heure</th>
                            <th class="pb-3">Salle</th>
                            <th class="pb-3 text-center">Candidats</th>
                            <th class="pb-3 text-center">Quota Requis</th>
                            <th class="pb-3 text-center">Affectés</th>
                            <th class="pb-3 text-right pr-1">Diagnostic Encadrement</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach(array_slice($epreuvesAuditees, 0, 10) as $eprAudit)
                            <tr class="hover:bg-slate-50/60 transition">
                                <td class="py-3 pl-1">
                                    <span class="font-bold text-slate-900 block text-xs">{{ $eprAudit['matiere'] }}</span>
                                    <span class="font-mono text-[11px] text-slate-500 block">#{{ $eprAudit['code'] }}</span>
                                </td>
                                <td class="py-3 text-xs text-slate-700">
                                    <span class="font-semibold block">{{ $eprAudit['date'] }}</span>
                                    <span class="text-[11px] text-slate-500 font-mono">{{ $eprAudit['horaire'] }}</span>
                                </td>
                                <td class="py-3 text-xs">
                                    <span class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-2 py-0.5 text-xs font-semibold text-slate-800 shadow-2xs">
                                        {{ $eprAudit['salle'] }}
                                    </span>
                                </td>
                                <td class="py-3 text-center text-xs font-semibold text-slate-800">
                                    {{ $eprAudit['candidats_count'] }}
                                </td>
                                <td class="py-3 text-center text-xs font-bold text-indigo-700">
                                    {{ $eprAudit['besoin_surveillants'] }} surveillant{{ $eprAudit['besoin_surveillants'] > 1 ? 's' : '' }}
                                </td>
                                <td class="py-3 text-center text-xs font-bold text-slate-900">
                                    {{ $eprAudit['surveillants_affectes'] }}
                                </td>
                                <td class="py-3 text-right pr-1">
                                    @if($eprAudit['statut_encadrement'] === 'conforme')
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-800 ring-1 ring-emerald-200">
                                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                            Conforme ({{ $eprAudit['surveillants_affectes'] }}/{{ $eprAudit['besoin_surveillants'] }})
                                        </span>
                                    @elseif($eprAudit['statut_encadrement'] === 'sans_surveillant')
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-rose-50 px-2.5 py-0.5 text-xs font-semibold text-rose-800 ring-1 ring-rose-200">
                                            <span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span>
                                            Aucun surveillant (0/{{ $eprAudit['besoin_surveillants'] }})
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-800 ring-1 ring-amber-200">
                                            <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                                            Sous-surveillée (-{{ $eprAudit['manque_surveillants'] }})
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Filtres et barre d'outils des affectations --}}
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200/80 bg-white p-3.5 shadow-xs">
        <form method="GET" action="{{ route('admin.evaluation.examens.surveillance') }}" class="flex flex-wrap items-center gap-2.5 flex-1 min-w-[280px]">
            {{-- Filtre Session --}}
            <div class="w-48">
                <select name="session_id" onchange="this.form.submit()" 
                        class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                    <option value="">Toutes les sessions</option>
                    @foreach($sessionsDisponibles as $sess)
                        <option value="{{ $sess->id }}" @selected($sessionFiltre === (int)$sess->id)>{{ $sess->nom }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Filtre Date --}}
            <div class="w-36">
                <input type="date" name="date" value="{{ $dateFiltre }}" onchange="this.form.submit()" 
                       class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs text-slate-800 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500" />
            </div>

            {{-- Filtre Statut --}}
            <div class="w-36">
                <select name="statut" onchange="this.form.submit()" 
                        class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs text-slate-800 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                    <option value="">Tous statuts</option>
                    <option value="assigne" @selected($statutFiltre === 'assigne')>Assigné</option>
                    <option value="confirme" @selected($statutFiltre === 'confirme')>Confirmé</option>
                    <option value="remplace" @selected($statutFiltre === 'remplace')>Remplacé</option>
                    <option value="annule" @selected($statutFiltre === 'annule')>Annulé</option>
                </select>
            </div>

            {{-- Filtre Rôle --}}
            <div class="w-36">
                <select name="role" onchange="this.form.submit()" 
                        class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs text-slate-800 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                    <option value="">Tous rôles</option>
                    <option value="principal" @selected($roleFiltre === 'principal')>Principal</option>
                    <option value="adjoint" @selected($roleFiltre === 'adjoint')>Adjoint</option>
                    <option value="surveillant" @selected($roleFiltre === 'surveillant')>Surveillant</option>
                    <option value="reserve" @selected($roleFiltre === 'reserve')>Garde / Réserve</option>
                </select>
            </div>

            {{-- Recherche --}}
            <div class="relative flex-1 min-w-[160px] max-w-xs">
                <input type="text" name="recherche" value="{{ $recherche }}" placeholder="Rechercher enseignant, matière…" 
                       class="w-full rounded-xl border border-slate-200 bg-white pl-8 pr-3 py-1.5 text-xs text-slate-800 placeholder-slate-400 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500" />
                <svg class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                </svg>
            </div>

            <button type="submit" class="rounded-xl bg-slate-100 hover:bg-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 transition">
                Filtrer
            </button>

            @if($sessionFiltre || $dateFiltre || $statutFiltre || $roleFiltre || $recherche)
                <a href="{{ route('admin.evaluation.examens.surveillance') }}" class="text-xs font-medium text-rose-600 hover:underline px-1">
                    Réinitialiser
                </a>
            @endif
        </form>

        <span class="text-xs font-medium text-slate-500">
            <span class="font-bold tabular-nums text-slate-800">{{ $surveillances->total() }}</span> affectation{{ $surveillances->total() > 1 ? 's' : '' }}
        </span>
    </div>

    {{-- Liste détaillée des affectations de surveillance --}}
    <div class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs">
        <h3 class="font-bold text-sm text-slate-900 mb-4">Dernières affectations de surveillance</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-100 text-[11px] font-bold uppercase tracking-wider text-slate-500">
                        <th class="pb-3 pl-1">Surveillant</th>
                        <th class="pb-3">Épreuve & Matière</th>
                        <th class="pb-3">Date & Horaires</th>
                        <th class="pb-3">Salle</th>
                        <th class="pb-3">Rôle</th>
                        <th class="pb-3">Statut</th>
                        <th class="pb-3 pr-1 text-right">Émargement / Présence</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($surveillances as $srv)
                        <tr class="hover:bg-slate-50/60 transition">
                            <td class="py-3.5 pl-1 font-semibold text-slate-900">
                                <div class="flex items-center gap-2">
                                    <span class="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-slate-100 font-mono text-xs font-bold text-slate-700">
                                        {{ substr($srv->libelleSurveillant(), 0, 2) }}
                                    </span>
                                    <div>
                                        <span class="block text-xs font-bold text-slate-900">{{ $srv->libelleSurveillant() }}</span>
                                        @if($srv->enseignant?->matricule)
                                            <span class="block font-mono text-[10px] text-slate-400">#{{ $srv->enseignant->matricule }}</span>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="py-3.5 text-slate-600">
                                <span class="font-medium text-slate-900 block text-xs">{{ $srv->epreuve?->matiere?->libelle_complet ?? 'Épreuve #'.$srv->epreuve_id }}</span>
                                <span class="font-mono text-[11px] text-slate-400">#{{ $srv->epreuve?->code }}</span>
                            </td>
                            <td class="py-3.5 text-slate-600 text-xs">
                                <span class="font-semibold text-slate-800 block">{{ $srv->epreuve?->date?->format('d/m/Y') }}</span>
                                <span class="text-[11px] text-slate-500 font-mono">{{ substr((string)$srv->epreuve?->heure_debut, 0, 5) }} &rarr; {{ substr((string)$srv->epreuve?->heure_fin, 0, 5) }}</span>
                            </td>
                            <td class="py-3.5 text-slate-600 text-xs">
                                <span class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-2 py-0.5 text-xs font-semibold text-slate-800 shadow-2xs">
                                    {{ $srv->salle?->code ?? $srv->epreuve?->salle?->code ?? '—' }}
                                </span>
                            </td>
                            <td class="py-3.5 text-xs">
                                <span class="inline-flex rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-700">
                                    {{ $srv->libelleRole() }}
                                </span>
                            </td>
                            <td class="py-3.5">
                                <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $srv->statut === 'confirme' ? 'bg-emerald-100 text-emerald-800' : ($srv->statut === 'remplace' ? 'bg-amber-100 text-amber-800' : 'bg-primary-50 text-primary-800') }}">
                                    {{ ucfirst($srv->statut) }}
                                </span>
                            </td>
                            <td class="py-3.5 pr-1 text-right text-xs">
                                @if($srv->presence === 'present')
                                    <span class="inline-flex items-center gap-1 text-emerald-700 font-bold">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                        Présent ({{ $srv->heure_arrivee ?? 'Pointé' }})
                                    </span>
                                @elseif($srv->presence === 'retard')
                                    <span class="inline-flex items-center gap-1 text-amber-700 font-bold">
                                        Retard ({{ $srv->heure_arrivee }})
                                    </span>
                                @elseif($srv->presence === 'absent')
                                    <span class="inline-flex items-center gap-1 text-rose-700 font-bold">
                                        Absent
                                    </span>
                                @else
                                    <span class="text-slate-400 italic">Non émargé</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-10 text-center text-sm text-slate-500">
                                Aucune affectation de surveillance ne correspond aux filtres appliqués.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($surveillances->hasPages())
            <div class="mt-4 pt-3 border-t border-slate-100">
                {{ $surveillances->links() }}
            </div>
        @endif
    </div>

    {{-- Zone dédiée aux documents professionnels (LOT 2) --}}
    @include('modules.evaluation.examens-advanced._zone-documents-professionnels', ['optionCourante' => 'surveillance'])
</div>
@endsection