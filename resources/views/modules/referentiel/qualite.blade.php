@extends('layouts.app')

@section('title', 'Qualité du référentiel')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Qualité du référentiel" description="Contrôles de cohérence calculés côté serveur : catégorie, gravité, entité, impact et action corrective. Le suivi est recalculé à chaque affichage (pilotage et prévalidation) — aucun état obsolète, aucune correction automatique."
            :fil="['Référentiel' => route('admin.referentiel.tableau-bord'), 'Pilotage' => route('admin.referentiel.pilotage'), 'Qualité' => '#']" />

        <form method="GET" action="{{ route('admin.referentiel.qualite') }}" class="mt-4 flex flex-col sm:flex-row gap-2">
            <select name="gravite" aria-label="Filtrer par gravité" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                <option value="">Toutes gravités</option>
                @foreach(['erreur' => 'Erreurs', 'avertissement' => 'Avertissements', 'info' => 'Informations'] as $valeur => $libelle)
                    <option value="{{ $valeur }}" @selected(request('gravite') === $valeur)>{{ $libelle }}</option>
                @endforeach
            </select>
            <x-btn type="submit" variant="secondary">Filtrer</x-btn>
            @if(request()->has('gravite') && request('gravite') !== '')
                <a href="{{ route('admin.referentiel.qualite') }}" class="inline-flex items-center text-xs font-bold text-slate-500 underline underline-offset-2 min-h-[44px]">Réinitialiser</a>
            @endif
        </form>

        @if($anomalies !== [])
            <section aria-label="Anomalies" class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                <table class="w-full min-w-[880px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-5 py-3 font-bold">Catégorie</th>
                            <th scope="col" class="px-5 py-3 font-bold">Gravité</th>
                            <th scope="col" class="px-5 py-3 font-bold">Entité</th>
                            <th scope="col" class="px-5 py-3 font-bold">Problème</th>
                            <th scope="col" class="px-5 py-3 font-bold">Impact</th>
                            <th scope="col" class="px-5 py-3 font-bold">Action corrective</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($anomalies as $anomalie)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-5 py-3 font-bold text-slate-700">{{ $anomalie['categorie'] }}</td>
                                <td class="px-5 py-3 text-xs font-black {{ $anomalie['gravite'] === 'erreur' ? 'text-rose-700' : ($anomalie['gravite'] === 'avertissement' ? 'text-amber-700' : 'text-sky-700') }}">
                                    {{ $anomalie['gravite'] === 'erreur' ? '🔴 Erreur' : ($anomalie['gravite'] === 'avertissement' ? '🟡 Avertissement' : '🔵 Info') }}
                                </td>
                                <td class="px-5 py-3 font-bold text-slate-900">
                                    @if($anomalie['lien'] !== '#')
                                        <a href="{{ $anomalie['lien'] }}" class="text-primary-800 underline underline-offset-2">{{ $anomalie['entite'] }}</a>
                                    @else
                                        {{ $anomalie['entite'] }}
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-slate-700">{{ $anomalie['probleme'] }}</td>
                                <td class="px-5 py-3 text-slate-600">{{ $anomalie['impact'] }}</td>
                                <td class="px-5 py-3 text-slate-600">{{ $anomalie['action'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
            <p class="mt-3 text-[11px] text-slate-500">Blocages d'activation détaillés (règle, correction, consommateurs) : <a href="{{ route('admin.referentiel.prevalidation.index') }}" class="font-bold text-primary-800 underline underline-offset-2">prévalidation →</a></p>
        @else
            <div class="mt-4"><x-empty-state message="Aucune anomalie détectée. ✓ Référentiel cohérent." /></div>
        @endif
    </div>
@endsection
