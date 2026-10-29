@extends('layouts.app')

@section('title', 'Préparation — ' . $session->nom)

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Assistant de préparation"
            :description="$session->nom.' · '.$session->type.' · du '.$session->date_debut->format('d/m/Y').' au '.$session->date_fin->format('d/m/Y')"
            :fil="['Évaluations' => route('admin.evaluations.index'), 'Préparation' => route('admin.examens.preparation.index'), $session->nom => '#']">
            <x-slot:actions>
                <x-status-badge :statut="$session->statut" />
            </x-slot:actions>
        </x-page-header>

        {{-- ── Étapes de l'assistant ── --}}
        <div class="mt-4 ds-card p-4 flex flex-wrap items-center gap-x-3 gap-y-2 text-xs font-bold uppercase tracking-wider text-slate-500">
            <span>1. Session</span><span class="text-slate-300">→</span>
            <span>2. Épreuves</span><span class="text-slate-300">→</span>
            <span>3. Salles</span><span class="text-slate-300">→</span>
            <span>4. Candidats</span><span class="text-slate-300">→</span>
            <span>5. Vérifications</span><span class="text-slate-300">→</span>
            <span>6. Validation</span><span class="text-slate-300">→</span>
            <span>7. Émargement PDF</span>
        </div>

        {{-- ── Épreuves + vérifications ── --}}
        <div class="mt-4 ds-card overflow-hidden">
            @if($verifications->isEmpty())
                <x-empty-state :message="'Aucune épreuve pour cette session — Générez les épreuves depuis la maquette (écran sessions) avant la préparation.'" />
            @else
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-4 py-2">Épreuve</th>
                            <th class="px-4 py-2">Date · heure</th>
                            <th class="px-4 py-2">Salle(s)</th>
                            <th class="px-4 py-2">Candidats</th>
                            <th class="px-4 py-2">Population</th>
                            <th class="px-4 py-2">Contrôles</th>
                            <th class="px-4 py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($verifications as $verification)
                            @php
                                $epreuve = $verification['epreuve'];
                                $controles = $verification['controles'];
                                $correspondance = $controles['correspondance'] ?? [];
                                $perimetre = $correspondance['perimetre'] ?? [];
                                $compteurs = $correspondance['compteurs'] ?? [];
                                $sallesUtilisees = $epreuve->candidats->groupBy(fn ($c) => $c->salle_id ?? $epreuve->salle_id);
                            @endphp
                            <tr>
                                <td class="px-4 py-2">
                                    <p class="font-bold">{{ $epreuve->matiere?->libelle_complet ?? '—' }} <span class="text-[11px] font-mono text-slate-400">(#{{ $epreuve->matiere_id }})</span></p>
                                    <p class="text-xs text-slate-500">
                                        {{ $epreuve->groupe?->code ?? 'Tous groupes' }}
                                        @if(!empty($perimetre['niveau_code'])) · Niveau {{ $perimetre['niveau_code'] }} @endif
                                        @if(!empty($perimetre['filiere']) && $perimetre['filiere'] !== '—') · {{ $perimetre['filiere'] }} @endif
                                    </p>
                                </td>
                                <td class="px-4 py-2 tabular-nums whitespace-nowrap">
                                    {{ $epreuve->date->format('d/m/Y') }}<br>
                                    <span class="text-xs text-slate-500">{{ substr((string) $epreuve->heure_debut, 0, 5) }} → {{ substr((string) $epreuve->heure_fin, 0, 5) }}</span>
                                </td>
                                <td class="px-4 py-2 text-xs">
                                    @forelse($sallesUtilisees as $salleId => $groupe)
                                        {{ $groupe->first()->salleEffective()?->code ?? 'non affectée' }} <span class="text-slate-400">({{ $groupe->count() }})</span>@if(!$loop->last)<br>@endif
                                    @empty
                                        <span class="text-rose-600 font-bold">Aucune</span>
                                    @endforelse
                                </td>
                                <td class="px-4 py-2 tabular-nums">
                                    <span class="font-bold">{{ $controles['effectif'] }}</span>
                                    @if(!empty($compteurs))
                                        <span class="block text-[11px] text-slate-500">
                                            Insc: {{ $compteurs['inscrits'] ?? 0 }} · Élig: {{ $compteurs['eligibles'] ?? 0 }} · Excl: {{ $compteurs['exclus'] ?? 0 }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-xs whitespace-nowrap">
                                    @if($controles['population_validee'])
                                        @if($controles['population_modifiee'])
                                            <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 font-bold text-amber-700">Modifiée — à revalider</span>
                                        @else
                                            <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 font-bold text-emerald-700">Validée</span>
                                        @endif
                                    @else
                                        <span class="text-slate-400">Non validée</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-xs max-w-56">
                                    @foreach($controles['bloquants'] as $bloquant)
                                        <p class="text-rose-700 font-semibold">⛔ {{ $bloquant }}</p>
                                    @endforeach
                                    @foreach($controles['avertissements'] as $avertissement)
                                        <p class="text-amber-700">⚠ {{ $avertissement }}</p>
                                    @endforeach
                                    @if($controles['bloquants'] === [] && $controles['avertissements'] === [])
                                        <span class="text-emerald-700 font-bold">✓ Conforme</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right whitespace-nowrap">
                                    @can('evaluations.update')
                                        <form method="post" action="{{ route('admin.examens.preparation.epreuves.population', $epreuve) }}" class="inline">
                                            @csrf
                                            <x-btn size="sm" variant="secondary" type="submit">Valider population</x-btn>
                                        </form>
                                    @endcan
                                    <a href="{{ route('admin.examens.preparation.epreuves.emargement', $epreuve) }}?apercu=1"
                                       class="inline-flex items-center rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-50">Aperçu</a>
                                    <a href="{{ route('admin.examens.preparation.epreuves.emargement', $epreuve) }}"
                                       class="inline-flex items-center rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-50">Émargement PDF</a>
                                    @foreach($sallesUtilisees as $salleId => $groupe)
                                        @if($salleId !== null && $sallesUtilisees->count() > 1)
                                            <a href="{{ route('admin.examens.preparation.epreuves.emargement', $epreuve) }}?salle_id={{ $salleId }}"
                                               class="block mt-1 text-xs font-bold text-primary-700 underline underline-offset-2">Feuille {{ $groupe->first()->salleEffective()?->code }}</a>
                                        @endif
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- ── Historique des documents générés ── --}}
        <div class="mt-4 ds-card p-4">
            <p class="text-xs font-black uppercase tracking-wider text-slate-500 mb-3">Historique de génération — réimpression et traçabilité</p>
            @if($documents->isEmpty())
                <p class="text-sm text-slate-500">Aucun document généré pour cette session.</p>
            @else
                <ul class="divide-y divide-slate-100 text-sm">
                    @foreach($documents as $document)
                        <li class="py-2 flex flex-wrap items-center justify-between gap-2">
                            <span>
                                <span class="font-bold">{{ ucfirst($document->type) }}</span>
                                <span class="text-slate-500">· {{ $document->salle?->code ?? 'toutes salles' }} · {{ $document->candidats_count }} candidat(s) · {{ $document->nom_fichier }}</span>
                            </span>
                            <span class="text-xs text-slate-400">
                                {{ $document->generePar?->name ?? 'système' }} · {{ $document->created_at->format('d/m/Y H:i') }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
@endsection
