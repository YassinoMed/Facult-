@extends('layouts.app')

@section('title', 'Gestion des versions & releases')

@section('content')
<div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8 sm:py-10">

    {{-- En-tête avec statut environnement et actions --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Système interne</p>
                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $etat['environnement'] === 'production' ? 'bg-rose-100 text-rose-800' : ($etat['environnement'] === 'recette' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800') }}">
                    Env : {{ mb_strtoupper($etat['environnement']) }}
                </span>
            </div>
            <h1 class="mt-1 text-2xl sm:text-3xl font-black tracking-tight text-slate-900">
                Versions & Releases
            </h1>
            <p class="mt-1 text-sm text-slate-500">
                Supervision locale, cohérence APP/DB/DEV/PROD, déploiements autonomes et historique des releases.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
            <a href="{{ route('admin.versioning.releases.create') }}" class="inline-flex items-center gap-1.5 rounded-xl bg-primary-800 px-4 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-primary-700 min-h-[44px]">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                Nouvelle release
            </a>
            <a href="{{ route('admin.versioning.releases.comparer') }}" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 min-h-[44px]">
                <svg class="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                </svg>
                Comparer
            </a>
            <a href="{{ route('admin.versioning.drift') }}" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 min-h-[44px]">
                <svg class="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                Drift
            </a>
        </div>
    </div>

    {{-- Messages flash --}}
    @if(session('succes'))
        <div class="mt-6 rounded-xl bg-emerald-50 border border-emerald-200 p-4 text-sm font-medium text-emerald-800">
            {{ session('succes') }}
        </div>
    @endif
    @if(session('erreur'))
        <div class="mt-6 rounded-xl bg-rose-50 border border-rose-200 p-4 text-sm font-medium text-rose-800">
            {{ session('erreur') }}
        </div>
    @endif

    {{-- Cartes des 4 composants centraux --}}
    <div class="mt-8 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {{-- APP --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Application (APP)</span>
                <span class="inline-flex items-center rounded-md bg-blue-50 px-2 py-0.5 text-xs font-semibold text-blue-700">Code</span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="font-mono text-3xl font-black text-slate-900">{{ $etat['app_version'] }}</span>
            </div>
            <p class="mt-2 text-xs text-slate-500">Source config/versioning.php</p>
        </div>

        {{-- DB --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-400">DB Release</span>
                <span class="inline-flex items-center rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700">Logique SemVer</span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="font-mono text-3xl font-black text-slate-900">{{ $etat['db_release'] ?? $etat['db_version'] }}</span>
            </div>
            <p class="mt-2 text-xs text-slate-500 font-medium">{{ $etat['db_schema']['texte'] ?? $etat['migrations']['applied'].' migrations appliquées' }}</p>
        </div>

        {{-- DEV --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Développement (DEV)</span>
                <span class="inline-flex items-center rounded-md bg-purple-50 px-2 py-0.5 text-xs font-semibold text-purple-700">Branche</span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="font-mono text-2xl sm:text-3xl font-black text-slate-900 truncate">{{ $etat['dev_version'] }}</span>
            </div>
            <p class="mt-2 text-xs text-slate-500">Itération en cours</p>
        </div>

        {{-- PROD --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Production (PROD)</span>
                <span class="inline-flex items-center rounded-md bg-indigo-50 px-2 py-0.5 text-xs font-semibold text-indigo-700">Cible</span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="font-mono text-3xl font-black text-slate-900">{{ $etat['prod_version'] }}</span>
            </div>
            <p class="mt-2 text-xs text-slate-500">Build : {{ $etat['build'] }}</p>
        </div>
    </div>

    {{-- État de la Release Active & Alertes --}}
    <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- Carte Release Actuellement Déployée --}}
        <div class="lg:col-span-2 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-base font-bold text-slate-900">Release actuellement déployée</h2>
                    <p class="text-xs text-slate-500">Version certifiée en exploitation sur cette machine</p>
                </div>
                @if($releaseDeployee)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-800">
                        <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                        Active
                    </span>
                @else
                    <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600">
                        Aucune release déployée
                    </span>
                @endif
            </div>

            @if($releaseDeployee)
                <div class="mt-5 grid grid-cols-1 sm:grid-cols-3 gap-4 border-t border-slate-100 pt-5">
                    <div>
                        <span class="text-xs text-slate-400">Version</span>
                        <p class="font-mono text-2xl font-bold text-slate-800">{{ $releaseDeployee->version }}</p>
                    </div>
                    <div>
                        <span class="text-xs text-slate-400">Build Number</span>
                        <p class="font-mono text-sm font-semibold text-slate-700">{{ $releaseDeployee->build_number }}</p>
                    </div>
                    <div>
                        <span class="text-xs text-slate-400">Déployée le</span>
                        <p class="text-sm font-semibold text-slate-700">
                            {{ $releaseDeployee->deployed_at ? $releaseDeployee->deployed_at->format('d/m/Y à H:i') : '—' }}
                        </p>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-slate-50 p-3.5 text-xs text-slate-600">
                    <div class="flex items-center gap-2">
                        <span class="font-semibold text-slate-500">Empreinte manifest :</span>
                        <span class="font-mono text-[11px] text-slate-700">{{ substr($releaseDeployee->manifest_hash ?? 'N/A', 0, 24) }}...</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <a href="{{ route('admin.versioning.releases.show', $releaseDeployee) }}" class="font-bold text-primary-700 hover:underline">
                            Voir la fiche &rarr;
                        </a>
                        <a href="{{ route('admin.versioning.manifest', $releaseDeployee->version) }}" class="font-bold text-slate-600 hover:underline">
                            Télécharger JSON
                        </a>
                    </div>
                </div>
            @else
                <div class="mt-6 rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
                    Le système fonctionne actuellement sur l'état initial des fichiers.
                    <div class="mt-3">
                        <a href="{{ route('admin.versioning.releases.create') }}" class="font-bold text-primary-700 hover:underline">
                            Créer la première release &rarr;
                        </a>
                    </div>
                </div>
            @endif
        </div>

        {{-- Santé, Drift & Compatibilité --}}
        <div class="flex flex-col gap-4">
            {{-- Drift --}}
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-bold text-slate-900">Dérive (Drift)</h3>
                    @if($drift['drift_detecte'])
                        <span class="inline-flex items-center rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-bold text-rose-800">
                            Drift détecté
                        </span>
                    @else
                        <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-bold text-emerald-800">
                            Aligné (100%)
                        </span>
                    @endif
                </div>

                <div class="mt-3">
                    <div class="flex items-center justify-between text-xs text-slate-500 mb-1">
                        <span>Score d'intégrité</span>
                        <span class="font-bold text-slate-800">{{ $drift['score_integrite'] }}%</span>
                    </div>
                    <div class="h-2 w-full rounded-full bg-slate-100 overflow-hidden">
                        <div class="h-full rounded-full {{ $drift['score_integrite'] >= 80 ? 'bg-emerald-500' : ($drift['score_integrite'] >= 50 ? 'bg-amber-500' : 'bg-rose-500') }}" style="width: {{ $drift['score_integrite'] }}%"></div>
                    </div>
                </div>

                <p class="mt-3 text-xs text-slate-500">
                    @if($drift['drift_detecte'])
                        {{ count($drift['ecarts']) }} écart(s) entre le manifest et l'état réel.
                    @else
                        Aucun écart constaté entre le manifest et l'état actif.
                    @endif
                </p>

                <div class="mt-4 pt-3 border-t border-slate-100 text-right">
                    <a href="{{ route('admin.versioning.drift') }}" class="text-xs font-bold text-primary-700 hover:underline">
                        Détails du drift &rarr;
                    </a>
                </div>
            </div>

            {{-- Compatibilité --}}
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-bold text-slate-900">Compatibilité</h3>
                    @if($compat['statut_global'] === 'compatible')
                        <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-bold text-emerald-800">
                            Compatible
                        </span>
                    @elseif($compat['statut_global'] === 'compatible_avec_avertissement')
                        <span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-bold text-amber-800">
                            Avertissement
                        </span>
                    @else
                        <span class="inline-flex items-center rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-bold text-rose-800">
                            Incompatible
                        </span>
                    @endif
                </div>

                <p class="mt-2 text-xs text-slate-500">
                    Cohérence sémantique entre le code applicatif et les schémas de base de données.
                </p>

                <div class="mt-4 pt-3 border-t border-slate-100 text-right">
                    <a href="{{ route('admin.versioning.compatibilite') }}" class="text-xs font-bold text-primary-700 hover:underline">
                        Matrice de compatibilité &rarr;
                    </a>
                </div>
            </div>
        </div>
    </div>

    {{-- Tableau des Releases Récentes --}}
    <div class="mt-8 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 bg-slate-50/50">
            <div>
                <h3 class="text-base font-bold text-slate-900">Releases récentes</h3>
                <p class="text-xs text-slate-500">Cycle de vie des versions enregistrées</p>
            </div>
            <a href="{{ route('admin.versioning.releases.index') }}" class="text-xs font-bold text-primary-700 hover:underline">
                Voir toutes les releases ({{ \App\Modules\Versioning\Models\Release::count() }}) &rarr;
            </a>
        </div>

        @if($releasesRecentes->isNotEmpty())
            <table class="w-full text-left text-xs sm:text-sm">
                <thead>
                    <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                        <th class="px-6 py-3 font-bold">Version</th>
                        <th class="px-6 py-3 font-bold">Statut</th>
                        <th class="px-6 py-3 font-bold">Environnement</th>
                        <th class="px-6 py-3 font-bold">Build</th>
                        <th class="px-6 py-3 font-bold">Créée le</th>
                        <th class="px-6 py-3 font-bold text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($releasesRecentes as $rel)
                        <tr class="hover:bg-slate-50/60 transition">
                            <td class="px-6 py-3.5 font-mono font-bold text-slate-800">
                                <a href="{{ route('admin.versioning.releases.show', $rel) }}" class="hover:text-primary-700">
                                    {{ $rel->version }}
                                </a>
                            </td>
                            <td class="px-6 py-3.5">
                                <span class="inline-flex items-center rounded-md px-2.5 py-0.5 text-xs font-semibold
                                    {{ $rel->statut === 'deployee' ? 'bg-emerald-100 text-emerald-800' :
                                       ($rel->statut === 'approuvee' ? 'bg-purple-100 text-purple-800' :
                                       ($rel->statut === 'preparee' ? 'bg-blue-100 text-blue-800' :
                                       ($rel->statut === 'echouee' ? 'bg-rose-100 text-rose-800' : 'bg-slate-100 text-slate-700'))) }}">
                                    {{ $rel->statut_libelle }}
                                </span>
                            </td>
                            <td class="px-6 py-3.5 text-xs font-semibold text-slate-600">
                                {{ ucfirst($rel->environnement) }}
                            </td>
                            <td class="px-6 py-3.5 font-mono text-xs text-slate-500">
                                {{ $rel->build_number }}
                            </td>
                            <td class="px-6 py-3.5 text-xs text-slate-500">
                                {{ $rel->created_at->format('d/m/Y H:i') }}
                            </td>
                            <td class="px-6 py-3.5 text-right font-medium">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('admin.versioning.releases.show', $rel) }}" class="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                        Détails
                                    </a>
                                    @if(in_array($rel->statut, ['preparee', 'testee', 'approuvee']))
                                        <a href="{{ route('admin.versioning.deployments.create', $rel) }}" class="rounded-lg bg-emerald-600 px-2.5 py-1 text-xs font-bold text-white hover:bg-emerald-700">
                                            Déployer
                                        </a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="p-8 text-center text-sm text-slate-500">
                Aucune release créée pour le moment.
            </div>
        @endif
    </div>

    {{-- Tableau des Déploiements Récents --}}
    <div class="mt-8 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 bg-slate-50/50">
            <div>
                <h3 class="text-base font-bold text-slate-900">Derniers déploiements & rollbacks</h3>
                <p class="text-xs text-slate-500">Historique des exécutions locales</p>
            </div>
            <a href="{{ route('admin.versioning.deployments.index') }}" class="text-xs font-bold text-primary-700 hover:underline">
                Voir l'historique complet &rarr;
            </a>
        </div>

        @if($deploiementsRecents->isNotEmpty())
            <table class="w-full text-left text-xs sm:text-sm">
                <thead>
                    <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                        <th class="px-6 py-3 font-bold">Type</th>
                        <th class="px-6 py-3 font-bold">Release</th>
                        <th class="px-6 py-3 font-bold">Statut</th>
                        <th class="px-6 py-3 font-bold">Durée</th>
                        <th class="px-6 py-3 font-bold">Date</th>
                        <th class="px-6 py-3 font-bold text-right">Détails</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($deploiementsRecents as $dep)
                        <tr class="hover:bg-slate-50/60 transition">
                            <td class="px-6 py-3.5">
                                <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-bold {{ $dep->type === 'rollback' ? 'bg-orange-100 text-orange-800' : 'bg-blue-100 text-blue-800' }}">
                                    {{ $dep->type_libelle }}
                                </span>
                            </td>
                            <td class="px-6 py-3.5 font-mono font-bold text-slate-800">
                                {{ $dep->release->version }}
                            </td>
                            <td class="px-6 py-3.5">
                                <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-semibold {{ $dep->statut === 'reussi' ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800' }}">
                                    {{ $dep->statut_libelle }}
                                </span>
                            </td>
                            <td class="px-6 py-3.5 text-xs text-slate-600">
                                {{ $dep->duree_secondes !== null ? "{$dep->duree_secondes}s" : '—' }}
                            </td>
                            <td class="px-6 py-3.5 text-xs text-slate-500">
                                {{ $dep->started_at ? $dep->started_at->format('d/m/Y H:i:s') : $dep->created_at->format('d/m/Y H:i') }}
                            </td>
                            <td class="px-6 py-3.5 text-right font-medium">
                                <a href="{{ route('admin.versioning.deployments.show', $dep) }}" class="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                    Consulter
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="p-8 text-center text-sm text-slate-500">
                Aucun déploiement n'a encore été exécuté.
            </div>
        @endif
    </div>

</div>
@endsection
