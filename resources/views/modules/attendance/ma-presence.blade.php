@extends('layouts.app')

@section('title', 'Détail de ma présence — Espace Enseignant')

@section('content')
    <div class="mx-auto max-w-4xl px-4 sm:px-6 py-8">
        <x-page-header :titre="'Séance du ' . $presence->date->format('d/m/Y')" :description="($presence->slot?->matiere?->nom ?? '—')"
            :fil="['Tableau de bord' => route('enseignant.dashboard'), 'Présences' => route('enseignant.presences.index'), 'Détail séance' => '#']">
            <x-slot:actions>
                <div class="flex items-center gap-2">
                    <x-btn variant="secondary" href="{{ route('enseignant.presences.index') }}">Retour à la liste</x-btn>
                    @if(in_array($presence->statut_effectif, ['absence_a_verifier', 'pointage_manquant', 'retard_potentiel', 'absence_validee'], true))
                        <x-btn variant="primary" href="{{ route('enseignant.reclamations.create', ['categorie' => 'presence', 'presence_id' => $presence->id, 'objet' => 'Régularisation présence du ' . $presence->date->format('d/m/Y') . ' (' . ($presence->slot?->matiere?->nom ?? '') . ')']) }}">
                            Régulariser cette séance
                        </x-btn>
                    @endif
                </div>
            </x-slot:actions>
        </x-page-header>

        {{-- Note explicative --}}
        <div class="mt-4 p-4 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm text-xs text-slate-600 dark:text-slate-300">
            <p><strong>Note d'intégrité :</strong> Cette séance est issue de l'emploi du temps officiel. Si aucun pointage n'a été enregistré ou si une anomalie matérielle est constatée, cette séance n'est <em>pas</em> automatiquement considérée comme une absence confirmée. Toute décision finale est prise après examen par la scolarité.</p>
        </div>

        {{-- Fiche détaillée de la séance --}}
        <section aria-label="Détail de la séance et présences" class="mt-6 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/40 flex items-center justify-between">
                <h3 class="text-sm font-black text-slate-900 dark:text-slate-100">Paramètres de la séance planifiée</h3>
                <x-status-badge :statut="$presence->statut_effectif" />
            </div>
            <dl class="divide-y divide-slate-100 dark:divide-slate-800 text-xs sm:text-sm">
                <div class="px-6 py-3.5 flex flex-col sm:flex-row sm:justify-between gap-1">
                    <dt class="text-slate-500 font-medium">Matière / ECUE</dt>
                    <dd class="font-bold text-slate-900 dark:text-slate-100">{{ $presence->slot?->matiere?->nom ?? '—' }} ({{ $presence->slot?->matiere?->code ?? '—' }})</dd>
                </div>
                <div class="px-6 py-3.5 flex flex-col sm:flex-row sm:justify-between gap-1">
                    <dt class="text-slate-500 font-medium">Groupe & Promotion</dt>
                    <dd class="font-semibold text-slate-800 dark:text-slate-200">{{ $presence->slot?->schedule?->groupe?->nom ?? '—' }}</dd>
                </div>
                <div class="px-6 py-3.5 flex flex-col sm:flex-row sm:justify-between gap-1">
                    <dt class="text-slate-500 font-medium">Horaire officiel prévu</dt>
                    <dd class="font-bold tabular-nums font-mono">{{ substr((string) ($presence->slot?->start_time ?? ''), 0, 5) }} → {{ substr((string) ($presence->slot?->end_time ?? ''), 0, 5) }}</dd>
                </div>
                <div class="px-6 py-3.5 flex flex-col sm:flex-row sm:justify-between gap-1">
                    <dt class="text-slate-500 font-medium">Salle affectée</dt>
                    <dd class="font-bold font-mono text-slate-800 dark:text-slate-200">{{ $presence->slot?->room?->code ?? '—' }}</dd>
                </div>
                <div class="px-6 py-3.5 flex flex-col sm:flex-row sm:justify-between gap-1">
                    <dt class="text-slate-500 font-medium">Pointages réels (Arrivée / Départ)</dt>
                    <dd class="font-semibold tabular-nums">
                        @if($presence->heure_arrivee || $presence->heure_depart)
                            Arrivée : <strong class="font-mono text-slate-900 dark:text-slate-100">{{ $presence->heure_arrivee ? substr((string) $presence->heure_arrivee, 0, 5) : '—' }}</strong>
                            · Départ : <strong class="font-mono text-slate-900 dark:text-slate-100">{{ $presence->heure_depart ? substr((string) $presence->heure_depart, 0, 5) : '—' }}</strong>
                            @if($presence->retard_minutes > 0)
                                <span class="ml-2 text-amber-600 font-bold">(+{{ $presence->retard_minutes }} min retard)</span>
                            @endif
                        @else
                            <span class="text-slate-400 italic">Aucun pointage relevé sur cette séance</span>
                        @endif
                    </dd>
                </div>
                <div class="px-6 py-3.5 flex flex-col sm:flex-row sm:justify-between gap-1">
                    <dt class="text-slate-500 font-medium">Statut calculé automatiquement</dt>
                    <dd><x-status-badge :statut="$presence->statut_calcule" /></dd>
                </div>
                <div class="px-6 py-3.5 flex flex-col sm:flex-row sm:justify-between gap-1">
                    <dt class="text-slate-500 font-medium">Statut officiellement validé</dt>
                    <dd>
                        @if($presence->statut_valide)
                            <x-status-badge :statut="$presence->statut_valide" />
                            @if($presence->valide_par)
                                <span class="ml-2 text-xs text-slate-500">(par {{ $presence->validePar?->name ?? 'Scolarité' }} le {{ $presence->valide_le?->format('d/m/Y H:i') }})</span>
                            @endif
                        @else
                            <span class="text-slate-400 italic">En attente de validation officielle</span>
                        @endif
                    </dd>
                </div>
                @if($presence->motif)
                    <div class="px-6 py-3.5 flex flex-col sm:flex-row sm:justify-between gap-1 bg-slate-50/50 dark:bg-slate-800/30">
                        <dt class="text-slate-500 font-medium">Motif ou décision enregistrée</dt>
                        <dd class="font-semibold text-slate-800 dark:text-slate-100 max-w-lg text-right">{{ $presence->motif }}</dd>
                    </div>
                @endif
            </dl>
        </section>

        {{-- Demandes de régularisation liées (Section 3.E) --}}
        <section aria-label="Demandes de régularisation liées" class="mt-6 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between gap-2">
                <div>
                    <h3 class="text-sm font-black text-slate-900 dark:text-slate-100">Demandes de régularisation & contestations ({{ $reclamations->count() }})</h3>
                    <p class="text-xs text-slate-500">Historique de vos démarches administratives pour cette séance précise.</p>
                </div>
                <x-btn variant="secondary" href="{{ route('enseignant.reclamations.create', ['categorie' => 'presence', 'presence_id' => $presence->id, 'objet' => 'Contestation de ma présence du ' . $presence->date->format('d/m/Y')]) }}">
                    Nouvelle demande
                </x-btn>
            </div>
            @if($reclamations->isNotEmpty())
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach($reclamations as $reclamation)
                        <li class="p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-3 hover:bg-slate-50/60 dark:hover:bg-slate-800/30">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="font-mono text-xs font-bold text-slate-900 dark:text-slate-100">{{ $reclamation->reference }}</span>
                                    <span class="text-xs text-slate-400">· Déposée le {{ $reclamation->created_at->format('d/m/Y H:i') }}</span>
                                </div>
                                <p class="text-xs font-semibold text-slate-800 dark:text-slate-200 mt-1">{{ $reclamation->objet }}</p>
                                @if($reclamation->reponse)
                                    <p class="text-xs text-emerald-700 dark:text-emerald-400 mt-1">Réponse scolarité : « {{ $reclamation->reponse }} »</p>
                                @endif
                            </div>
                            <div class="flex items-center gap-3 shrink-0">
                                <x-status-badge :statut="$reclamation->statut" />
                                <a href="{{ route('enseignant.reclamations.show', $reclamation) }}" class="text-xs font-bold text-indigo-700 dark:text-indigo-300 hover:underline">
                                    Voir suivi
                                </a>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @else
                <div class="p-6">
                    <x-empty-state message="Aucune demande de régularisation déposée pour cette séance." />
                </div>
            @endif
        </section>

        {{-- Traçabilité de l'audit interne --}}
        @if(!empty($presence->historique))
            <section aria-label="Historique d'audit" class="mt-6 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm p-6">
                <h3 class="text-xs font-black uppercase tracking-wider text-slate-400 mb-3">Journal des modifications et audit</h3>
                <ul class="space-y-3">
                    @foreach($presence->historique as $trace)
                        <li class="p-3 rounded-xl border border-slate-100 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-800/40 text-xs flex flex-col sm:flex-row sm:items-center justify-between gap-1">
                            <div>
                                <span class="font-bold text-slate-800 dark:text-slate-200">{{ ucfirst(str_replace('_', ' ', $trace['action'] ?? 'Action')) }}</span>
                                <span class="text-slate-500">· {{ $trace['motif'] ?? '—' }}</span>
                            </div>
                            <span class="text-slate-400 font-mono text-[11px] tabular-nums shrink-0">
                                {{ isset($trace['date']) ? \Carbon\Carbon::parse($trace['date'])->format('d/m/Y H:i:s') : '—' }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
@endsection
