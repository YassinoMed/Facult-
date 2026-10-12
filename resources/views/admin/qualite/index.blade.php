@extends('layouts.app')

@section('title', 'Qualité des données')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Qualité des données" :description="'Score : ' . $score . ' % résolues ou ignorées (formule : résolues+ignorées / total).'"
            :fil="['Qualité' => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('admin.qualite.index', ['temps_reel' => 1]) }}">Détection temps réel</x-btn>
            </x-slot:actions>
        </x-page-header>

        <form method="get" action="{{ route('admin.qualite.index') }}" class="mt-4 flex flex-wrap gap-2">
            <select name="statut" class="rounded-xl border border-slate-200 px-3 py-2 text-sm">
                <option value="">Tous statuts</option>
                @foreach(\App\Models\AnomalieQualite::STATUTS as $statut)
                    <option value="{{ $statut }}" @selected(request('statut') === $statut)>{{ $statut }}</option>
                @endforeach
            </select>
            <select name="gravite" class="rounded-xl border border-slate-200 px-3 py-2 text-sm">
                <option value="">Toutes gravités</option>
                @foreach(\App\Models\AnomalieQualite::GRAVITES as $gravite)
                    <option value="{{ $gravite }}" @selected(request('gravite') === $gravite)>{{ $gravite }}</option>
                @endforeach
            </select>
            <x-btn size="sm" type="submit">Filtrer</x-btn>
        </form>

        @if(!empty($temps_reel))
            <section class="mt-4 ds-card p-5">
                <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Détection temps réel ({{ count($temps_reel) }})</h2>
                <ul class="mt-2 divide-y divide-slate-100 text-sm">
                    @foreach($temps_reel as $anomalie)
                        <li class="py-1.5">[{{ $anomalie['source'] }} · {{ $anomalie['gravite'] }}] {{ $anomalie['message'] }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <div class="mt-4 ds-card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500">
                    <tr><th class="px-4 py-2">Gravité</th><th class="px-4 py-2">Problème</th><th class="px-4 py-2">Statut</th><th class="px-4 py-2"></th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($anomalies as $anomalie)
                        <tr>
                            <td class="px-4 py-2"><x-status-badge :statut="$anomalie->gravite" /></td>
                            <td class="px-4 py-2">
                                <p class="font-bold">{{ $anomalie->message }}</p>
                                <p class="text-xs text-slate-400">{{ $anomalie->source }} · {{ $anomalie->entite_libelle }} · {{ $anomalie->action_recommandee }}</p>
                            </td>
                            <td class="px-4 py-2"><x-status-badge :statut="$anomalie->statut" /></td>
                            <td class="px-4 py-2 text-right">
                                @can('qualite.manage')
                                    @foreach(array_diff(\App\Models\AnomalieQualite::TRANSITIONS[$anomalie->statut] ?? [], []) as $cible)
                                        <form method="post" action="{{ route('admin.qualite.transiter', $anomalie) }}" class="inline">
                                            @csrf
                                            <x-btn size="sm" variant="secondary" type="submit" name="statut" value="{{ $cible }}">→ {{ $cible }}</x-btn>
                                        </form>
                                    @endforeach
                                    @if($anomalie->lien)
                                        <a href="{{ $anomalie->lien }}" class="text-xs font-bold text-primary-800 underline underline-offset-2">Ouvrir</a>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-6 text-center text-slate-500">Aucune anomalie persistée. Lancez <code>qualite:auditer</code>.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="px-4 py-3">{{ $anomalies->links() }}</div>
        </div>
        <p class="mt-2 text-[11px] text-slate-400">Sources : population, référentiel, pédagogie (règles existantes, non dupliquées). Balayage hebdomadaire + tâches via <code>qualite:auditer --sync-tasks</code>.</p>
    </div>
@endsection
