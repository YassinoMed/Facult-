@extends('layouts.app')

@section('title', 'Simulation ' . $simulation->reference)

@php
    $strategieLibelle = match($simulation->metriques['strategie'] ?? 'equilibre') {
        'mixite_genre' => 'Mixité de genre (Parité H/F)',
        'redoublants_homogenes' => 'Équilibre pédagogique (Redoublants)',
        'alphabetique' => 'Répartition alphabétique',
        default => 'Équilibrage quantitatif standard',
    };
@endphp

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header :titre="'Simulation ' . $simulation->reference" :description="'Statut : ' . $simulation->statut . ' · Stratégie : ' . $strategieLibelle . ' · Seed : ' . ($simulation->seed ?? '—')" />

        <div class="mt-4 grid gap-4 sm:grid-cols-4">
            <x-stat-card libelle="Candidats" :valeur="$simulation->candidats" />
            <x-stat-card libelle="Affectés" :valeur="$simulation->affectes" />
            <x-stat-card libelle="Non affectés" :valeur="$simulation->non_affectes" />
            <x-stat-card libelle="Écart max" :valeur="$simulation->metriques['ecart_max'] ?? '—'" />
        </div>

        <p class="mt-2 text-sm text-slate-600">
            Minimum {{ $simulation->metriques['minimum'] ?? '—' }} · Maximum {{ $simulation->metriques['maximum'] ?? '—' }} ·
            Moyenne {{ $simulation->metriques['moyenne'] ?? '—' }} · Capacité utilisée {{ $simulation->metriques['capacite_utilisee_pct'] ?? '—' }} % ·
            Taux d'affectation {{ $simulation->metriques['taux_affectation_pct'] ?? '—' }} % ·
            Groupes utilisés {{ $simulation->metriques['groupes_utilises'] ?? '—' }} ·
            Saturés {{ $simulation->metriques['groupes_satures'] ?? '—' }} ·
            Capacité {{ $simulation->metriques['capacite_totale'] ?? '—' }} (reste {{ $simulation->metriques['capacite_restante'] ?? '—' }})
        </p>

        @if(($simulation->metriques['groupes_supplementaires'] ?? 0) > 0)
            <x-alert type="warning" titre="Capacité insuffisante" class="mt-4">
                Reste à placer : {{ $simulation->metriques['manque'] }} —
                {{ $simulation->metriques['groupes_supplementaires'] }} groupe(s) supplémentaire(s) recommandé(s)
                (capacité {{ $simulation->metriques['capacite_recommandee'] }}@if($simulation->metriques['code_groupe_suggere']), ex. {{ $simulation->metriques['code_groupe_suggere'] }}@endif).
                La création reste un acte administratif.
            </x-alert>
        @endif

        @if(! in_array($simulation->statut, ['confirmee', 'annulee', 'expiree'], true))
            <form method="POST" action="{{ route('admin.affectations.automatique.regenerer', $simulation) }}" class="mt-4 flex gap-2 text-sm">
                @csrf
                <x-btn type="submit" variant="secondary">Régénérer (nouveau seed, mêmes paramètres)</x-btn>
            </form>
        @endif

        <h2 class="mt-6 font-bold">Groupes & Répartition</h2>
        <div class="mt-2 grid gap-4 sm:grid-cols-3">
            @foreach($groupes as $groupe)
                @php
                    $genres = $simulation->metriques['genres_par_groupe'][$groupe->id] ?? null;
                    $reds = $simulation->metriques['redoublants_par_groupe'][$groupe->id] ?? null;
                @endphp
                <div class="ds-card p-4 space-y-2">
                    <div class="flex items-center justify-between">
                        <p class="font-mono font-bold">{{ $groupe->code }}</p>
                        <span class="text-xs text-slate-500 font-mono">{{ $parGroupe[$groupe->id] ?? 0 }} / {{ $groupe->capacite ?? '∞' }}</span>
                    </div>

                    @if($genres)
                        <div class="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-400">
                            <span title="Femmes / Hommes">👩 {{ $genres['femmes'] }} / 👨 {{ $genres['hommes'] }}</span>
                            <span class="text-slate-300 dark:text-slate-700">·</span>
                            <span>{{ $genres['pct_femmes'] }}% F</span>
                        </div>
                    @endif

                    @if($reds && $reds['redoublants'] > 0)
                        <div class="text-xs text-amber-700 dark:text-amber-400">
                            <span>{{ $reds['redoublants'] }} réinscrit(s) ({{ $reds['pct_redoublants'] }}%)</span>
                        </div>
                    @endif

                    @if($simulation->groupeConfirme($groupe->id))
                        <x-status-badge statut="confirme" />
                    @elseif(! in_array($simulation->statut, ['confirmee', 'annulee', 'expiree'], true))
                        <form method="POST" action="{{ route('admin.affectations.automatique.confirmer', [$simulation, $groupe]) }}" class="mt-2">
                            @csrf
                            <x-btn type="submit" class="w-full">{{ session('continuer_confirmation') == $groupe->id ? 'Continuer la confirmation' : 'Confirmer ce groupe' }}</x-btn>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>

        @if($nonAffectes->isNotEmpty())
            <h2 class="mt-6 font-bold">Non affectés ({{ $nonAffectes->count() }})</h2>
            <ul class="mt-2 text-sm space-y-1">
                @foreach($nonAffectes as $item)
                    <li>{{ $item->etudiant?->num_inscription }} — {{ $item->etudiant?->nom }} {{ $item->etudiant?->prenom }}
                        <ul class="ml-4 text-slate-600">
                            @foreach($item->conflits ?? [] as $conflit)
                                <li>[{{ $conflit['type'] }}] {{ $conflit['raison'] }}</li>
                            @endforeach
                        </ul>
                        @include('admin.affectations._piste', ['piste' => ($pistes ?? [])[$item->etudiant_id] ?? null, 'etudiantId' => $item->etudiant_id])
                    </li>
                @endforeach
            </ul>
        @endif

        <h2 class="mt-6 font-bold">Propositions</h2>
        <form method="GET" class="mt-2 flex gap-2 text-sm">
            <input name="q" value="{{ request('q') }}" placeholder="Nom, prénom, matricule" class="rounded-xl border px-3 py-2">
            <select name="statut" class="rounded-xl border px-3 py-2">
                <option value="">Tous statuts</option>
                @foreach(['propose', 'modifie', 'confirme', 'non_affecte'] as $statut)
                    <option value="{{ $statut }}" @selected(request('statut') === $statut)>{{ $statut }}</option>
                @endforeach
            </select>
            <select name="flex" class="rounded-xl border px-3 py-2" title="Filtre flexibilité (groupes possibles)">
                <option value="">Toute flexibilité</option>
                <option value="1" @selected(request('flex') === '1')>1 seul groupe possible</option>
                <option value="2-3" @selected(request('flex') === '2-3')>2–3 groupes possibles</option>
                <option value="4+" @selected(request('flex') === '4+')>4+ groupes possibles</option>
                <option value="0" @selected(request('flex') === '0')>Aucun groupe possible</option>
            </select>
            <x-btn type="submit">Filtrer</x-btn>
        </form>

        <div class="mt-2 ds-card overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr><th class="text-left p-2">Matricule</th><th class="text-left p-2">Étudiant</th><th class="text-left p-2">Groupes possibles</th><th class="text-left p-2">Groupe proposé</th><th class="text-left p-2">Statut</th><th class="text-left p-2">Raison</th></tr></thead>
                <tbody>
                    @foreach($items as $item)
                        <tr class="border-t">
                            <td class="p-2 font-mono">{{ $item->etudiant?->num_inscription }}</td>
                            <td class="p-2">{{ $item->etudiant?->nom }} {{ $item->etudiant?->prenom }}<br><span class="text-[11px] text-slate-500">{{ \App\Models\AssignmentItem::flexibiliteLabel((int) $item->flexibilite, $item->groupe_propose_id !== null) }}</span></td>
                            <td class="p-2">
                                <details>
                                    <summary class="cursor-pointer underline">{{ $item->flexibilite }} groupe(s) possible(s)</summary>
                                    <ul class="mt-1 space-y-0.5 text-xs">
                                        @foreach($item->candidats ?? [] as $candidat)
                                            <li>
                                                @if($candidat['compatible'])
                                                    <span class="font-bold text-emerald-700">✓ {{ $candidat['code'] }}</span>
                                                    <span class="text-slate-500">({{ $candidat['places_restantes'] ?? '∞' }} places)</span>
                                                    @if($item->groupe_propose_id === $candidat['groupe_id'])
                                                        <span class="font-bold">← proposé</span>
                                                    @endif
                                                @else
                                                    <span class="text-rose-700">✗ {{ $candidat['code'] }}</span>
                                                    <span class="text-slate-500">{{ $candidat['raison'] }}</span>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </details>
                            </td>
                            <td class="p-2 font-mono">{{ $item->groupePropose?->code ?? '—' }}</td>
                            <td class="p-2">{{ $item->statut }}</td>
                            <td class="p-2 text-slate-600">{{ $item->raison }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $items->links() }}</div>

        <div class="mt-4 flex gap-4 text-sm">
            <a class="underline" href="{{ route('admin.affectations.automatique.exporter', $simulation) }}">Exporter CSV</a>
            @if(! in_array($simulation->statut, ['confirmee', 'annulee'], true))
                <form method="POST" action="{{ route('admin.affectations.automatique.annuler', $simulation) }}">
                    @csrf
                    <button class="underline" type="submit">Annuler la simulation</button>
                </form>
            @endif
        </div>
    </div>
@endsection
