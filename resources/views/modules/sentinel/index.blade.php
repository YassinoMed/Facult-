@extends('layouts.app')

@section('title', 'Platform Sentinel — Cockpit de surveillance')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 py-8 space-y-6">
        <x-page-header titre="Platform Sentinel — Supervision"
            :description="'Surveillance globale : ' . $ouverts . ' incident(s) ouvert(s), ' . $critiques . ' critique(s) actif(s).'"
            :fil="['Supervision' => '#', 'Sentinel' => route('admin.sentinel.index')]">
            <x-slot:actions>
                <a href="{{ route('admin.sentinel.regles') }}" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                    ⚙ Règles ({{ count(config('sentinel.checks', [])) }})
                </a>
                @can('sentinel.manage')
                    <form method="POST" action="{{ route('admin.sentinel.executer') }}" class="inline">
                        @csrf
                        <x-btn size="sm" type="submit">⚡ Lancer une ronde</x-btn>
                    </form>
                @endcan
            </x-slot:actions>
        </x-page-header>

        {{-- Sondes d'infrastructure en temps réel --}}
        <div class="ds-card p-5">
            <h2 class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-3">État des services & infrastructure</h2>
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                @foreach($sondes as $sonde)
                    <div class="rounded-xl border p-3 {{ $sonde['ok'] ? 'border-emerald-200 bg-emerald-50/50' : 'border-rose-200 bg-rose-50/50' }}">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-bold {{ $sonde['ok'] ? 'text-emerald-900' : 'text-rose-900' }}">{{ $sonde['nom'] }}</span>
                            <span class="inline-block h-2.5 w-2.5 rounded-full {{ $sonde['ok'] ? 'bg-emerald-500' : 'bg-rose-500' }}"></span>
                        </div>
                        <p class="mt-1 text-[11px] truncate {{ $sonde['ok'] ? 'text-emerald-700' : 'text-rose-700' }} font-mono" title="{{ $sonde['detail'] }}">{{ $sonde['detail'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Sauvegardes : dernier succès, prochaine exécution, volume --}}
        <div class="ds-card p-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-xs font-bold uppercase tracking-wider text-slate-500">Sauvegardes SQL</h2>
                <a href="{{ route('admin.system.backups.index') }}" class="text-[11px] font-bold text-primary-700 hover:underline">Historique complet →</a>
            </div>
            <div class="mt-3 grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
                <div>
                    <p class="font-bold uppercase text-[10px] text-slate-400">Dernier succès</p>
                    <p class="mt-0.5 font-semibold tabular-nums">{{ $sauvegarde['dernier_succes']?->completed_at?->format('d/m/Y H:i') ?? 'aucun' }}</p>
                </div>
                <div>
                    <p class="font-bold uppercase text-[10px] text-slate-400">Prochaine exécution</p>
                    <p class="mt-0.5 font-semibold tabular-nums">{{ $sauvegarde['prochaine']?->format('d/m/Y H:i') ?? 'planification désactivée' }}</p>
                </div>
                <div>
                    <p class="font-bold uppercase text-[10px] text-slate-400">Lots / volume</p>
                    <p class="mt-0.5 font-semibold tabular-nums">{{ $sauvegarde['statistiques']['succes'] ?? 0 }} succès · {{ number_format((($sauvegarde['statistiques']['octets'] ?? 0) / 1048576), 1, ',', ' ') }} Mo</p>
                </div>
                <div>
                    <p class="font-bold uppercase text-[10px] text-slate-400">Échecs (tous)</p>
                    <p class="mt-0.5 font-semibold tabular-nums">{{ $sauvegarde['statistiques']['echecs'] ?? 0 }}</p>
                </div>
            </div>
        </div>

        {{-- Statistiques et compteurs --}}
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <p class="text-2xl font-black tabular-nums text-slate-900">{{ $ouverts }}</p>
                <p class="text-[11px] font-bold uppercase text-slate-500">Ouverts</p>
            </div>
            <div class="rounded-2xl border border-rose-300 bg-rose-50 p-4">
                <p class="text-2xl font-black tabular-nums text-rose-900">{{ $critiques }}</p>
                <p class="text-[11px] font-bold uppercase text-rose-700">Critiques actifs</p>
            </div>
            <div class="rounded-2xl border border-amber-200 bg-amber-50/60 p-4">
                <p class="text-2xl font-black tabular-nums text-amber-900">{{ $acquittes }}</p>
                <p class="text-[11px] font-bold uppercase text-amber-700">Acquittés</p>
            </div>
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50/60 p-4">
                <p class="text-2xl font-black tabular-nums text-emerald-900">{{ $resolus }}</p>
                <p class="text-[11px] font-bold uppercase text-emerald-700">Résolus</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <p class="text-sm font-black tabular-nums text-slate-700 truncate" title="{{ $derniereExecution ?? 'Aucune' }}">{{ $derniereExecution ? \Illuminate\Support\Carbon::parse($derniereExecution)->diffForHumans() : '—' }}</p>
                <p class="text-[11px] font-bold uppercase text-slate-500">Dernière ronde</p>
            </div>
        </div>

        {{-- Barre de recherche et filtres --}}
        <form method="get" action="{{ route('admin.sentinel.index') }}" class="ds-card p-4 flex flex-wrap items-center gap-3">
            <div class="flex-1 min-w-[200px]">
                <input type="text" name="q" value="{{ request('q') }}" placeholder="Rechercher par titre, code ou diagnostic..." class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs text-slate-900 focus:border-primary-600 focus:outline-none" />
            </div>
            <select name="statut" class="rounded-xl border border-slate-200 px-3 py-2 text-xs" aria-label="Statut">
                <option value="">Tous statuts</option>
                @foreach(\App\Modules\Sentinel\Models\SentinelIncident::STATUTS as $statut)
                    <option value="{{ $statut }}" @selected(request('statut') === $statut)>{{ ucfirst($statut) }}</option>
                @endforeach
            </select>
            <select name="gravite" class="rounded-xl border border-slate-200 px-3 py-2 text-xs" aria-label="Gravité">
                <option value="">Toutes gravités</option>
                @foreach(\App\Modules\Sentinel\Models\SentinelIncident::GRAVITES as $gravite)
                    <option value="{{ $gravite }}" @selected(request('gravite') === $gravite)>{{ ucfirst($gravite) }}</option>
                @endforeach
            </select>
            @if(count($modules) > 0)
                <select name="module" class="rounded-xl border border-slate-200 px-3 py-2 text-xs" aria-label="Module">
                    <option value="">Tous modules</option>
                    @foreach($modules as $m)
                        <option value="{{ $m }}" @selected(request('module') === $m)>{{ ucfirst($m) }}</option>
                    @endforeach
                </select>
            @endif
            <x-btn size="sm" type="submit">Filtrer</x-btn>
            @if(request()->hasAny(['q', 'statut', 'gravite', 'module']))
                <a href="{{ route('admin.sentinel.index') }}" class="text-xs text-slate-500 hover:text-slate-800 underline">Réinitialiser</a>
            @endif
        </form>

        {{-- Tableau des incidents --}}
        <div class="ds-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-[11px] uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Gravité</th>
                            <th class="px-4 py-3">Incident & Diagnostic</th>
                            <th class="px-4 py-3">Module</th>
                            <th class="px-4 py-3 text-center">Récidives</th>
                            <th class="px-4 py-3">Statut</th>
                            <th class="px-4 py-3">Détecté le</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($incidents as $incident)
                            <tr class="hover:bg-slate-50/50 transition">
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <x-status-badge :statut="$incident->gravite" />
                                </td>
                                <td class="px-4 py-3">
                                    <p class="font-bold text-slate-900">
                                        <a href="{{ route('admin.sentinel.incidents.show', $incident) }}" class="hover:text-primary-600 hover:underline">
                                            {{ $incident->titre }}
                                        </a>
                                    </p>
                                    <p class="text-[11px] text-slate-500 mt-0.5">
                                        <span class="font-mono font-bold">{{ $incident->code }}</span> · {{ \Illuminate\Support\Str::limit($incident->diagnostic, 90) }}
                                    </p>
                                    @if($incident->recommandation)
                                        <p class="text-[10px] text-emerald-700 bg-emerald-50 rounded px-1.5 py-0.5 mt-1 inline-block">
                                            💡 {{ \Illuminate\Support\Str::limit($incident->recommandation, 80) }}
                                        </p>
                                    @endif
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-xs text-slate-600 font-mono">
                                    {{ $incident->module ?? 'transverse' }}
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-center">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold {{ $incident->compteur > 1 ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600' }}">
                                        {{ $incident->compteur }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <x-status-badge :statut="$incident->statut" />
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-xs text-slate-500 tabular-nums">
                                    {{ $incident->derniere_detection_le?->format('d/m/Y H:i') ?? '—' }}
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-right">
                                    <div class="inline-flex items-center gap-1.5">
                                        <a href="{{ route('admin.sentinel.incidents.show', $incident) }}" class="rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                                            Détail
                                        </a>
                                        @can('sentinel.manage')
                                            @if($incident->statut === \App\Modules\Sentinel\Models\SentinelIncident::STATUT_OUVERT)
                                                <form method="POST" action="{{ route('admin.sentinel.incidents.transiter', $incident) }}" class="inline">
                                                    @csrf
                                                    <input type="hidden" name="cible" value="acquitte" />
                                                    <button type="submit" class="rounded-lg border border-amber-300 bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-800 hover:bg-amber-100" title="Acquitter l'incident">
                                                        Acquitter
                                                    </button>
                                                </form>
                                                <form method="POST" action="{{ route('admin.sentinel.incidents.transiter', $incident) }}" class="inline">
                                                    @csrf
                                                    <input type="hidden" name="cible" value="resolu" />
                                                    <button type="submit" class="rounded-lg border border-emerald-300 bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-800 hover:bg-emerald-100" title="Marquer résolu">
                                                        Résoudre
                                                    </button>
                                                </form>
                                            @elseif($incident->statut === \App\Modules\Sentinel\Models\SentinelIncident::STATUT_ACQUITTE)
                                                <form method="POST" action="{{ route('admin.sentinel.incidents.transiter', $incident) }}" class="inline">
                                                    @csrf
                                                    <input type="hidden" name="cible" value="resolu" />
                                                    <button type="submit" class="rounded-lg border border-emerald-300 bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-800 hover:bg-emerald-100">
                                                        Résoudre
                                                    </button>
                                                </form>
                                            @elseif($incident->statut === \App\Modules\Sentinel\Models\SentinelIncident::STATUT_RESOLU || $incident->statut === \App\Modules\Sentinel\Models\SentinelIncident::STATUT_IGNORE)
                                                <form method="POST" action="{{ route('admin.sentinel.incidents.transiter', $incident) }}" class="inline">
                                                    @csrf
                                                    <input type="hidden" name="cible" value="ouvert" />
                                                    <button type="submit" class="rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                                                        Rouvrir
                                                    </button>
                                                </form>
                                            @endif
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-8 text-center text-slate-400">
                                    <p class="text-sm font-semibold">Aucun incident détecté</p>
                                    <p class="text-xs text-slate-400 mt-1">Tous les contrôles s'exécutent sans anomalie.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-4">{{ $incidents->links() }}</div>
    </div>
@endsection
