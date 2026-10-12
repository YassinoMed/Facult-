@extends('layouts.app')

@section('title', 'Statistiques population')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Population universitaire" description="Effectifs et inscriptions — scopés à votre périmètre."
            :fil="['Population' => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('admin.population.qualite') }}">Qualité des données</x-btn>
            </x-slot:actions>
        </x-page-header>

        <form method="GET" action="{{ route('admin.population.stats') }}" class="mt-4 flex flex-col sm:flex-row gap-2">
            <select name="annee_universitaire_id" aria-label="Filtrer par année" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                <option value="">Toutes années (inscriptions)</option>
                @foreach($annees as $annee)
                    <option value="{{ $annee->id }}" @selected($anneeId == $annee->id)>{{ $annee->libelle }}</option>
                @endforeach
            </select>
            <x-btn type="submit" variant="secondary">Filtrer</x-btn>
        </form>

        <div class="mt-4 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
            @foreach([
                ['Étudiants', $stats['etudiants_total']],
                ['Validés', $stats['etudiants_valides']],
                ['En attente', $stats['etudiants_attente']],
                ['Inscriptions', $stats['inscriptions']],
                ['Inscriptions validées', $stats['inscriptions_validees']],
                ['Inscriptions aujourd\'hui', $stats['inscriptions_aujourdhui']],
                ['Inscriptions en cours', $stats['inscriptions_encours']],
                ['Ajournées', $stats['inscriptions_ajournees']],
                ['Réinscriptions', $stats['reinscriptions']],
                ['Enseignants actifs', $stats['enseignants']],
                ['Groupes', $stats['groupes']],
            ] as [$libelle, $valeur])
                <div class="ds-card p-4">
                    <p class="text-2xl font-black tabular-nums text-slate-900">{{ $valeur }}</p>
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">{{ $libelle }}</p>
                </div>
            @endforeach
        </div>

        <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <div class="ds-card p-4">
                <p class="text-2xl font-black tabular-nums text-slate-900">{{ $ventilations['crees_aujourdhui'] }}</p>
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Créés aujourd'hui</p>
            </div>
            @foreach(['par_niveau' => 'Par niveau', 'par_sexe' => 'Par sexe', 'par_programme' => 'Par programme', 'inscriptions_par_programme' => 'Inscriptions par programme'] as $cle => $titre)
                <div class="ds-card p-4">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">{{ $titre }}</p>
                    @forelse($ventilations[$cle] as $code => $total)
                        <p class="mt-1 flex justify-between text-sm"><span class="font-bold">{{ $code }}</span><span class="tabular-nums">{{ $total }}</span></p>
                    @empty
                        <p class="mt-1 text-sm text-slate-400">—</p>
                    @endforelse
                </div>
            @endforeach
        </div>
    </div>
@endsection
