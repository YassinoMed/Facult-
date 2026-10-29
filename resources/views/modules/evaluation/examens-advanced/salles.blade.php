@extends('layouts.app')

@section('title', 'Examens — Salles & Capacités')

@section('content')

@php
    $sessionsEnCours = $sessionsStats['open'] + $sessionsStats['ongoing'];
    $pointsAttention = $statsPreparation['sans_salle'] + $sallesSansCapacite + $epreuvesSansSurveillant;
@endphp

<div class="space-y-6">
    <x-page-header
        titre="Salles et capacités d'examen"
        description="Référentiel des salles, contrôle des capacités théoriques et autorisées avec ratio d'espacement réglementaire anti-fraude (50 %)."
        :fil="['Évaluation' => route('admin.evaluations.index'), 'Examens' => route('admin.evaluation.examens.tableau-de-bord'), 'Salles' => '']"
    />

    @include('modules.evaluation.examens-advanced._nav-tabs')

    {{-- Cartes KPI de Gouvernance des Salles --}}
    @if(isset($kpisSalles))
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {{-- KPI 1 : Total Salles --}}
            <div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Salles Répertoriées</span>
                    <span class="inline-flex rounded-full bg-slate-100 p-2 text-slate-600">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" /></svg>
                    </span>
                </div>
                <div class="mt-2 text-2xl font-bold text-slate-900">{{ $kpisSalles['total_salles'] }}</div>
                <div class="mt-1 text-xs text-slate-500">
                    {{ $kpisSalles['salles_actives'] }} actives · {{ $kpisSalles['salles_inactives'] }} fermées
                </div>
            </div>

            {{-- KPI 2 : Capacité Utile Examen 50% --}}
            <div class="rounded-2xl border border-primary-200/80 bg-primary-50/20 p-5 shadow-xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider text-primary-800">Capacité Utile 50%</span>
                    <span class="inline-flex rounded-full bg-primary-100 p-2 text-primary-700">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>
                    </span>
                </div>
                <div class="mt-2 text-2xl font-bold text-primary-900">{{ number_format($kpisSalles['capacite_utile_examen_50'], 0, ',', ' ') }}</div>
                <div class="mt-1 text-xs text-primary-700">
                    Théorique : {{ number_format($kpisSalles['capacite_nominale_totale'], 0, ',', ' ') }} places
                </div>
            </div>

            {{-- KPI 3 : Salles Validées Opposables --}}
            <div class="rounded-2xl border border-emerald-200/80 bg-emerald-50/20 p-5 shadow-xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider text-emerald-800">Salles Opposables</span>
                    <span class="inline-flex rounded-full bg-emerald-100 p-2 text-emerald-700">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    </span>
                </div>
                <div class="mt-2 text-2xl font-bold text-emerald-900">{{ $kpisSalles['salles_validees'] }}</div>
                <div class="mt-1 text-xs text-emerald-700">
                    {{ $kpisSalles['taux_conformite'] }}% du parc conforme
                </div>
            </div>

            {{-- KPI 4 : Salles Bloquantes --}}
            <div class="rounded-2xl border {{ $kpisSalles['salles_bloquantes'] > 0 ? 'border-rose-200/90 bg-rose-50/30' : 'border-slate-200/80 bg-white' }} p-5 shadow-xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider {{ $kpisSalles['salles_bloquantes'] > 0 ? 'text-rose-700' : 'text-slate-500' }}">Capacité Inconnue</span>
                    <span class="inline-flex rounded-full {{ $kpisSalles['salles_bloquantes'] > 0 ? 'bg-rose-100 text-rose-700' : 'bg-slate-100 text-slate-600' }} p-2">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
                    </span>
                </div>
                <div class="mt-2 text-2xl font-bold {{ $kpisSalles['salles_bloquantes'] > 0 ? 'text-rose-900' : 'text-slate-900' }}">{{ $kpisSalles['salles_bloquantes'] }}</div>
                <div class="mt-1 text-xs {{ $kpisSalles['salles_bloquantes'] > 0 ? 'text-rose-700 font-semibold' : 'text-slate-500' }}">
                    {{ $kpisSalles['salles_bloquantes'] > 0 ? 'Publication bloquée si utilisée' : 'Aucun blocage capacitaire' }}
                </div>
            </div>
        </div>
    @endif

    {{-- Règle D-04 --}}
    <div class="rounded-2xl border border-amber-200 bg-amber-50/50 p-4 text-xs text-amber-900 flex items-start gap-3">
        <svg class="h-5 w-5 text-amber-600 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
        <div>
            <p class="font-bold">Règle de capacité opposable (Décision D-04 & R-17/R-18) :</p>
            <p class="mt-0.5 text-amber-800">Seule la capacité validée d'examen est opposable à la publication. Une capacité inconnue ou nulle (0) bloque formellement la publication officielle. Le ratio d'espacement standard par défaut est de <strong>50 %</strong> (1 place sur 2 pour garantir la loyauté des épreuves).</p>
        </div>
    </div>

    {{-- Barre de recherche & Filtres --}}
    <div class="flex flex-wrap items-center justify-between gap-4 rounded-2xl bg-white p-4 border border-slate-200/80 shadow-xs">
        <form method="GET" action="{{ route('admin.evaluation.examens.salles') }}" class="flex flex-wrap items-center gap-3">
            @if(request('annee_id'))
                <input type="hidden" name="annee_id" value="{{ request('annee_id') }}">
            @endif

            <input type="text" name="recherche" value="{{ $recherche }}" placeholder="Rechercher par code ou nom..." class="rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2 text-sm font-medium text-slate-800 focus:border-primary-500 focus:bg-white focus:outline-none w-64 transition">

            @if(!empty($batiments))
                <select name="batiment_id" class="rounded-xl border border-slate-200 bg-slate-50/50 px-3 py-2 text-sm font-medium text-slate-700 focus:border-primary-500 focus:bg-white focus:outline-none">
                    <option value="">-- Tous les bâtiments --</option>
                    @foreach($batiments as $bat)
                        <option value="{{ $bat->id }}" @selected($batimentId === (int)$bat->id)>{{ $bat->nom }} ({{ $bat->code }})</option>
                    @endforeach
                </select>
            @endif

            <button type="submit" class="rounded-xl bg-slate-800 hover:bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition shadow-xs">
                Filtrer
            </button>

            @if($recherche || $batimentId)
                <a href="{{ route('admin.evaluation.examens.salles') }}" class="text-xs font-semibold text-slate-500 hover:text-slate-800 underline">
                    Réinitialiser
                </a>
            @endif
        </form>

        <span class="text-xs text-slate-500 font-medium">{{ $salles->total() }} salle(s) répertoriée(s)</span>
    </div>

    {{-- Tableau des salles --}}
    <div class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-100 text-xs font-semibold text-slate-500 uppercase tracking-wider">
                        <th class="pb-3">Code Salle</th>
                        <th class="pb-3">Nom & Bâtiment</th>
                        <th class="pb-3">Type</th>
                        <th class="pb-3">Capacité nominale</th>
                        <th class="pb-3">Capacité utile examen (50%)</th>
                        <th class="pb-3">État & Opposabilité</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($salles as $salle)
                        @php
                            $capaNominale = (int) ($salle->capacite ?? 0);
                            $capaExamen = (int) floor($capaNominale * $ratioDefaut);
                            $invalide = $capaNominale <= 0;
                        @endphp
                        <tr class="hover:bg-slate-50/40 transition">
                            <td class="py-3 font-bold text-slate-900 font-mono">{{ $salle->code }}</td>
                            <td class="py-3">
                                <div class="font-medium text-slate-800">{{ $salle->nom }}</div>
                                @if($salle->batimentRelation)
                                    <div class="text-xs text-slate-400 font-medium">{{ $salle->batimentRelation->nom }}</div>
                                @elseif($salle->batiment)
                                    <div class="text-xs text-slate-400 font-medium">{{ $salle->batiment }}</div>
                                @endif
                            </td>
                            <td class="py-3 text-slate-600 capitalize">
                                <span class="inline-flex rounded-lg bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">
                                    {{ $salle->type ?? 'Salle standard' }}
                                </span>
                            </td>
                            <td class="py-3">
                                @if($invalide)
                                    <span class="text-xs font-bold text-rose-600">Non renseignée (0)</span>
                                @else
                                    <div class="text-xs font-medium text-slate-500">Théorique</div>
                                    <div class="font-semibold text-slate-800">{{ $capaNominale }} places</div>
                                @endif
                            </td>
                            <td class="py-3">
                                @if($invalide)
                                    <span class="inline-flex rounded-full bg-rose-100 text-rose-800 px-2.5 py-0.5 text-xs font-bold">
                                        Bloquante (0 utile)
                                    </span>
                                @else
                                    <div class="text-xs font-medium text-slate-500">Autorisée (50%)</div>
                                    <div class="font-bold text-primary-800">{{ $capaExamen }} candidats max</div>
                                @endif
                            </td>
                            <td class="py-3">
                                @if(!$salle->actif)
                                    <span class="inline-flex rounded-full bg-slate-100 text-slate-600 px-2.5 py-0.5 text-xs font-semibold">
                                        Fermée
                                    </span>
                                @elseif($invalide)
                                    <span class="inline-flex rounded-full bg-amber-100 text-amber-800 px-2.5 py-0.5 text-xs font-semibold" title="Règle D-04 : Bloque la publication tant que la capacité est nulle">
                                        Capacité inconnue
                                    </span>
                                @else
                                    <span class="inline-flex rounded-full bg-emerald-100 text-emerald-800 px-2.5 py-0.5 text-xs font-semibold">
                                        Validée
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center text-sm text-slate-500">Aucune salle trouvée.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">
            {{ $salles->links() }}
        </div>
    </div>

    {{-- Zone dédiée aux documents professionnels (LOT 2) --}}
    @include('modules.evaluation.examens-advanced._zone-documents-professionnels', ['optionCourante' => 'salles'])
</div>
@endsection