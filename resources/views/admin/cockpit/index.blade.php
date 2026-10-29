@extends('layouts.app')

@section('title', 'Cockpit')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Cockpit" description="Ce qui nécessite une intervention humaine maintenant."
            :fil="['Cockpit' => '#']">
            <x-slot:actions>
                <x-btn href="{{ route('admin.taches.index') }}">Centre de tâches ({{ $aTraiter }})</x-btn>
                <x-btn variant="secondary" href="{{ route('admin.statistiques.index') }}">Statistiques</x-btn>
                <x-btn variant="secondary" href="{{ route('admin.cockpit.kpi.export') }}">Export KPI (CSV)</x-btn>
            </x-slot:actions>
        </x-page-header>

        {{-- Section KPI Scolarité Unifiés (LOT 03) --}}
        @if(isset($snapshotKpi))
            <section aria-label="Indicateurs clés scolarité" class="mt-4 ds-card p-5">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-3">
                    <div>
                        <h2 class="text-sm font-black uppercase tracking-wider text-slate-800">Indicateurs clés de scolarité</h2>
                        <p class="text-xs text-slate-500">Rapprochés des sources officielles · Fraîcheur :
                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold {{ $snapshotKpi->statutFraicheurGlobal === 'a_jour' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
                                {{ $snapshotKpi->statutFraicheurGlobal === 'a_jour' ? 'À jour' : 'Périmé / Rechargement requis' }}
                            </span>
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        @if($snapshotKpi->syntheseAlertes['critique'] > 0)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-black bg-rose-100 text-rose-800">
                                {{ $snapshotKpi->syntheseAlertes['critique'] }} alerte(s) critique(s)
                            </span>
                        @endif
                        <a href="{{ route('admin.cockpit.kpi') }}" target="_blank" class="text-xs font-bold text-primary-800 underline">API JSON</a>
                        <span class="text-slate-300">|</span>
                        <a href="{{ route('admin.cockpit.kpi.export') }}" class="text-xs font-bold text-primary-800 underline">Export CSV</a>
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    @php
                        $clesAffichees = [
                            'effectif_total',
                            'taux_validation_inscriptions',
                            'taux_reussite_deliberations',
                            'taches_critiques_ouvertes',
                            'anomalies_qualite_ouvertes',
                            'epreuves_sans_salle',
                        ];
                    @endphp
                    @foreach($clesAffichees as $cle)
                        @if($ind = $snapshotKpi->get($cle))
                            <div class="rounded-lg border border-slate-100 p-3 bg-slate-50/50 hover:bg-white transition-colors" title="{{ $ind->definition->definition }} (Formule : {{ $ind->definition->formule }})">
                                <span class="text-[11px] font-bold text-slate-500 block truncate">{{ $ind->definition->nom }}</span>
                                <div class="mt-1 flex items-baseline justify-between">
                                    <span class="text-lg font-black text-slate-900">{{ $ind->valeurFormatee }}</span>
                                    <span class="text-[10px] font-bold px-1 rounded {{ $ind->statutAlerte === 'critique' ? 'bg-rose-100 text-rose-700' : ($ind->statutAlerte === 'attention' ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600') }}">
                                        {{ $ind->statutAlerte }}
                                    </span>
                                </div>
                                <span class="mt-1 text-[10px] text-slate-400 block truncate">Source : {{ $ind->definition->sourceVerite }}</span>
                            </div>
                        @endif
                    @endforeach
                </div>
            </section>
        @endif

        @if($urgentes->isNotEmpty())
            <section aria-label="Urgent" class="mt-4 ds-card border-rose-200 p-5">
                <h2 class="text-sm font-black uppercase tracking-wider text-rose-700">Urgent ({{ $urgentes->count() }})</h2>
                <x-task-list :taches="$urgentes" />
            </section>
        @endif

        <div class="mt-4 grid gap-3 lg:grid-cols-2">
            <section class="ds-card p-5">
                <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Mes tâches ({{ $miennes->count() }})</h2>
                <x-task-list :taches="$miennes" />
            </section>
            <section class="ds-card p-5">
                <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Aujourd’hui</h2>
                <ul class="divide-y divide-slate-100 text-sm">
                    @forelse($aujourdhui as $element)
                        <li class="py-2"><strong>{{ $element->titre }}</strong> <span class="text-slate-400">· {{ $element->type }}</span></li>
                    @empty
                        <li class="py-2 text-slate-500">Rien de prévu aujourd’hui.</li>
                    @endforelse
                </ul>
                @if($demandesEcheance->isNotEmpty())
                    <h3 class="mt-3 text-xs font-black uppercase tracking-wider text-amber-600">Demandes hors délai</h3>
                    <ul class="divide-y divide-slate-100 text-sm">
                        @foreach($demandesEcheance as $demande)
                            <li class="py-1.5"><a href="{{ route('admin.demandes.show', $demande) }}" class="font-bold text-primary-800 underline underline-offset-2">{{ $demande->reference }}</a> <span class="text-slate-400">· {{ $demande->objet }}</span></li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>

        <div class="mt-4 grid gap-3 lg:grid-cols-2">
            <section class="ds-card p-5">
                <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Activité récente</h2>
                <x-timeline :elements="$activite->map(fn ($a) => ['titre' => $a->action, 'detail' => ($a->etudiant?->nom_complet ?? '') . ' — ' . ($a->utilisateur?->name ?? 'système'), 'date' => $a->created_at?->format('d/m H:i')])->all()" />
            </section>
            <section class="ds-card p-5">
                <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Récemment consultés</h2>
                <ul class="divide-y divide-slate-100 text-sm">
                    @forelse($recents as $recent)
                        <li class="py-1.5"><a href="{{ $recent->lien }}" class="font-bold text-primary-800 underline underline-offset-2">{{ $recent->titre }}</a> <span class="text-slate-400">· {{ $recent->type }}</span></li>
                    @empty
                        <li class="py-2 text-slate-500">Aucun élément récent.</li>
                    @endforelse
                </ul>
            </section>
        </div>
    </div>
@endsection
