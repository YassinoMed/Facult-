@extends('layouts.app')

@section('title', 'Groupes — Scolarité')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Groupes" description="Groupes et classes par faculté, filière et niveau."
            :fil="['Tableau de bord' => route('admin.dashboard'), 'Groupes' => '#']">
            <x-slot:actions>
                @can('groupes.manage')
                    <x-btn href="{{ route('admin.groupes.create') }}">Nouveau groupe</x-btn>
                @endcan
                @can('data.import')
                    <x-btn href="{{ route('admin.donnees.import.form', 'groupes') }}" variant="secondary">Importer</x-btn>
                @endcan
                @can('data.export')
                    <x-btn href="{{ route('admin.donnees.export', ['groupes', 'csv']) }}" variant="ghost">Exporter CSV</x-btn>
                    <x-btn href="{{ route('admin.donnees.export', ['groupes', 'xlsx']) }}" variant="ghost">XLSX</x-btn>
                    <x-btn href="{{ route('admin.listes.exporter', ['format' => 'pdf', 'regroupement' => 'groupe']) }}" variant="ghost">PDF groupes+étudiants</x-btn>
                @endcan
            </x-slot:actions>
        </x-page-header>

        <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <x-stat-card libelle="Groupes" :valeur="$stats['groupes']" />
            <x-stat-card libelle="Effectif total" :valeur="$stats['effectif']" />
            <x-stat-card libelle="Complets" :valeur="$stats['complets']" couleur="amber" />
            <x-stat-card libelle="Vides" :valeur="$stats['vides']" couleur="rose" />
        </div>
        @if(isset($stats['capacite_totale']))
            <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <x-stat-card libelle="Capacité totale" :valeur="$stats['capacite_totale']" />
                <x-stat-card libelle="Places disponibles" :valeur="$stats['places_disponibles']" couleur="emerald" />
                <x-stat-card libelle="Presque complets" :valeur="$stats['presque_complets']" couleur="amber" />
                <div class="rounded-2xl border border-slate-200 bg-white p-4 text-sm">
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-600 font-semibold">Remplissage moyen</p>
                    <p class="mt-1 text-2xl font-black tabular-nums">{{ $stats['effectif'] > 0 && $stats['capacite_totale'] > 0 ? (int) round($stats['effectif'] / $stats['capacite_totale'] * 100) : 0 }} %</p>
                </div>
            </div>
        @endif

        <form method="GET" action="{{ route('admin.groupes.index') }}" role="search" class="mt-4 flex flex-col sm:flex-row gap-2">
            <input type="search" name="search" value="{{ request('search') }}" placeholder="Code, nom…"
                   aria-label="Rechercher un groupe"
                   class="flex-1 rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-primary-700 focus:outline-none min-h-[44px]">
            <select name="programme_id" aria-label="Programme" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                <option value="">Tous programmes</option>
                @foreach($programmes as $programme)
                    <option value="{{ $programme->id }}" @selected((string) request('programme_id') === (string) $programme->id)>{{ $programme->code }}</option>
                @endforeach
            </select>
            <select name="niveau" aria-label="Niveau" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                <option value="">Tous niveaux</option>
                @foreach(['L1','L2','L3','M1','M2','D1','D2','D3'] as $niveau)
                    <option value="{{ $niveau }}" @selected(request('niveau') === $niveau)>{{ $niveau }}</option>
                @endforeach
            </select>
            <select name="occupation" aria-label="Occupation" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                <option value="">Toutes occupations</option>
                @foreach(['vide' => 'Vides', 'partiel' => 'Partiels', 'presque_complet' => 'Presque complets (≥80 %)', 'complet' => 'Complets', 'sureffectif' => 'Sureffectifs', 'avec_etudiants' => 'Avec étudiants'] as $valeur => $libelle)
                    <option value="{{ $valeur }}" @selected(request('occupation') === $valeur)>{{ $libelle }}</option>
                @endforeach
            </select>
            <select name="statut" aria-label="Statut" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                <option value="">Actifs + inactifs</option>
                <option value="actif" @selected(request('statut') === 'actif')>Actifs</option>
                <option value="inactif" @selected(request('statut') === 'inactif')>Inactifs</option>
            </select>
            <select name="annee_universitaire_id" aria-label="Année" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                <option value="">Toutes années</option>
                @foreach($annees ?? [] as $annee)
                    <option value="{{ $annee->id }}" @selected((string) request('annee_universitaire_id') === (string) $annee->id)>{{ $annee->libelle }}</option>
                @endforeach
            </select>
            <x-btn type="submit" variant="secondary">Chercher</x-btn>
        </form>

        <section aria-label="Liste des groupes" class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            @if($groupes->isNotEmpty())
                <table class="w-full min-w-[760px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-700 font-bold">
                            <th scope="col" class="px-5 py-3 font-bold">Code</th>
                            <th scope="col" class="px-5 py-3 font-bold">Nom</th>
                            <th scope="col" class="px-5 py-3 font-bold">Faculté</th>
                            <th scope="col" class="px-5 py-3 font-bold">Niveau</th>
                            <th scope="col" class="px-5 py-3 font-bold">Occupation</th>
                            <th scope="col" class="px-5 py-3 font-bold">Plannings</th>
                            <th scope="col" class="px-5 py-3 font-bold"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($groupes as $groupe)
                            @php
                                $effectif = $groupe->affectations_actives ?? $groupe->etudiants_count;
                                $taux = $groupe->capacite ? (int) round($effectif / $groupe->capacite * 100) : null;
                            @endphp
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-5 py-3 font-mono font-bold text-slate-800">{{ $groupe->code }}</td>
                                <td class="px-5 py-3 font-bold text-slate-900">{{ $groupe->nom }}</td>
                                <td class="px-5 py-3 text-slate-600">{{ $groupe->faculte->code }}</td>
                                <td class="px-5 py-3 text-slate-600">{{ $groupe->niveau }}</td>
                                <td class="px-5 py-3">
                                    <span class="tabular-nums text-slate-700">{{ $effectif }}/{{ $groupe->capacite ?? '∞' }}</span>
                                    @if($taux !== null)
                                        <span class="ml-1 inline-block h-1.5 w-16 overflow-hidden rounded-full bg-slate-100 align-middle" role="img" aria-label="{{ $taux }} % d'occupation">
                                            <span class="block h-full {{ $taux > 100 ? 'bg-rose-600' : ($taux >= 100 ? 'bg-amber-500' : 'bg-emerald-500') }}" style="width: {{ min(100, $taux) }}%"></span>
                                        </span>
                                    @endif
                                    @if($groupe->capacite !== null && $effectif > $groupe->capacite)
                                        <x-status-badge statut="erreur" />
                                    @elseif($effectif === 0)
                                        <x-status-badge statut="vide" />
                                    @endif
                                </td>
                                <td class="px-5 py-3 tabular-nums text-slate-700">{{ $groupe->schedules_count }}</td>
                                <td class="px-5 py-3 text-right whitespace-nowrap">
                                    <a href="{{ route('admin.groupes.show', $groupe) }}" class="font-bold text-primary-800 underline underline-offset-2">Consulter</a>
                                    <a href="{{ route('admin.groupes.etudiants', $groupe) }}" class="ml-2 font-bold text-primary-800 underline underline-offset-2">Étudiants</a>
                                    @can('groupes.manage')
                                        <a href="{{ route('admin.groupes.edit', $groupe) }}" class="ml-2 font-bold text-slate-500 underline underline-offset-2">Modifier</a>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-5 py-3 border-t border-slate-100">{{ $groupes->links() }}</div>
            @else
                <div class="p-8 text-center">
                    <x-empty-state message="Aucun groupe dans ce périmètre." />
                    @if(request()->hasAny(['search', 'programme_id', 'niveau', 'occupation', 'statut', 'annee_universitaire_id']))
                        <div class="mt-4 flex flex-col items-center gap-2">
                            @if(request('occupation') && request('occupation') !== 'vide' && ($stats['effectif'] ?? 0) === 0)
                                <p class="text-xs text-amber-700 dark:text-amber-300">
                                    Information : Aucun étudiant n'est encore affecté aux groupes dans ce périmètre.
                                </p>
                            @endif
                            <a href="{{ route('admin.groupes.index') }}" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                                Réinitialiser les filtres
                            </a>
                        </div>
                    @endif
                </div>
            @endif
        </section>
    </div>
@endsection
