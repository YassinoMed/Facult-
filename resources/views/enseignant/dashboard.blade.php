@extends('layouts.app')

@section('title', 'Tableau de bord — Espace Enseignant')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header :titre="'Bonjour ' . $enseignant->prenom" description="Vos séances du jour et vos prochaines surveillances."
            :fil="['Tableau de bord' => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('enseignant.profil.show') }}">Mon profil</x-btn>
            </x-slot:actions>
        </x-page-header>

        @if(session('success'))
            <div class="mt-4"><x-alert type="success" :message="session('success')" /></div>
        @endif

        <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-3">
            <x-stat-card libelle="Séances aujourd'hui" :valeur="count($seancesDuJour)" detail="Cours, TD, TP" couleur="primary" />
            <x-stat-card libelle="Gardes à venir" :valeur="$prochainesGardes->count()" detail="5 prochaines affichées" couleur="slate" />
            <x-stat-card libelle="Demandes en cours" :valeur="$demandesEnCours" detail="En traitement" couleur="indigo" />
            <x-stat-card libelle="Notifications non lues" :valeur="$notificationsNonLues" detail="À consulter" couleur="{{ $notificationsNonLues > 0 ? 'amber' : 'emerald' }}" />
        </div>

        <div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-4">
            <section aria-labelledby="titre-seances" class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                    <h2 id="titre-seances" class="text-sm font-black text-slate-900 dark:text-slate-100">Séances du jour</h2>
                    <a href="{{ route('enseignant.emploi-du-temps.semaine') }}" class="text-xs font-bold text-indigo-700 dark:text-indigo-300 underline underline-offset-2">Emploi du temps</a>
                </div>
                @if(count($seancesDuJour) > 0)
                    <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach($seancesDuJour as $seance)
                            <li class="px-5 py-3 text-sm flex items-center justify-between gap-3">
                                <div>
                                    <p class="font-bold text-slate-800 dark:text-slate-100">{{ $seance['matiere'] }} <span class="font-normal text-slate-500">· {{ strtoupper($seance['type']) }}</span></p>
                                    <p class="text-xs text-slate-500">{{ $seance['debut'] }} → {{ $seance['fin'] }} · Salle {{ $seance['salle'] ?? '—' }}</p>
                                </div>
                                <x-status-badge :statut="$seance['statut']" />
                            </li>
                        @endforeach
                    </ul>
                @else
                    <div class="p-5"><x-empty-state message="Aucune séance prévue aujourd'hui." /></div>
                @endif
            </section>

            <section aria-labelledby="titre-surveillances" class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                    <h2 id="titre-surveillances" class="text-sm font-black text-slate-900 dark:text-slate-100">Prochaines surveillances</h2>
                    <a href="{{ route('enseignant.surveillances.index') }}" class="text-xs font-bold text-indigo-700 dark:text-indigo-300 underline underline-offset-2">Tout voir</a>
                </div>
                @if($prochainesGardes->isNotEmpty())
                    <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach($prochainesGardes as $garde)
                            <li class="px-5 py-3 text-sm flex items-center justify-between gap-3">
                                <div>
                                    <p class="font-bold text-slate-800 dark:text-slate-100">{{ $garde->epreuve->code }} <span class="font-normal text-slate-500">· {{ $garde->epreuve->matiere?->nom }}</span></p>
                                    <p class="text-xs text-slate-500">{{ $garde->epreuve->date->format('d/m/Y') }} · {{ substr((string) $garde->epreuve->heure_debut, 0, 5) }} → {{ substr((string) $garde->epreuve->heure_fin, 0, 5 )}} · {{ $garde->epreuve->salle?->code ?? '—' }}</p>
                                </div>
                                <x-status-badge :statut="$garde->role" />
                            </li>
                        @endforeach
                    </ul>
                @else
                    <div class="p-5"><x-empty-state message="Aucune surveillance à venir." /></div>
                @endif
            </section>
        </div>

        <div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-4">
            <section aria-labelledby="titre-echeances" class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                    <h2 id="titre-echeances" class="text-sm font-black text-slate-900 dark:text-slate-100">Prochaines échéances</h2>
                    <a href="{{ route('enseignant.calendrier.agenda') }}" class="text-xs font-bold text-indigo-700 dark:text-indigo-300 underline underline-offset-2">Mon agenda</a>
                </div>
                @if(count($prochainesEcheances) > 0)
                    <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach($prochainesEcheances as $echeance)
                            <li class="px-5 py-3 text-sm flex items-center justify-between gap-3">
                                <div>
                                    <p class="font-bold text-slate-800 dark:text-slate-100">{{ $echeance['titre'] }}</p>
                                    <p class="text-xs text-slate-500">{{ ucfirst(str_replace('_', ' ', $echeance['type'])) }}</p>
                                </div>
                                <p class="text-sm font-bold tabular-nums text-slate-700 dark:text-slate-200">{{ \Carbon\Carbon::parse($echeance['date'])->format('d/m/Y') }}</p>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <div class="p-5"><x-empty-state message="Aucune échéance publiée à venir." /></div>
                @endif
            </section>

            <section aria-labelledby="titre-annonces" class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                    <h2 id="titre-annonces" class="text-sm font-black text-slate-900 dark:text-slate-100">Annonces</h2>
                    <a href="{{ route('enseignant.annonces.index') }}" class="text-xs font-bold text-indigo-700 dark:text-indigo-300 underline underline-offset-2">Tout voir</a>
                </div>
                @if($annoncesEnseignant->isNotEmpty())
                    <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach($annoncesEnseignant as $annonce)
                            <li class="px-5 py-3 text-sm">
                                <a href="{{ route('enseignant.annonces.show', $annonce) }}" class="font-bold text-slate-800 dark:text-slate-100 hover:underline">{{ $annonce->titre }}</a>
                                <p class="mt-1 text-xs text-slate-500">{{ $annonce->publiee_a?->format('d/m/Y') ?? 'Publication immédiate' }} · {{ ucfirst($annonce->type) }}</p>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <div class="p-5"><x-empty-state message="Aucune annonce publiée." /></div>
                @endif
            </section>
        </div>

        <div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-4">
            <section aria-labelledby="titre-documents" class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                    <h2 id="titre-documents" class="text-sm font-black text-slate-900 dark:text-slate-100">Documents récents</h2>
                    <a href="{{ route('enseignant.documents.index') }}" class="text-xs font-bold text-indigo-700 dark:text-indigo-300 underline underline-offset-2">Tous les documents</a>
                </div>
                @if($documentsRecents->isNotEmpty())
                    <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach($documentsRecents as $doc)
                            <li class="px-5 py-3 text-sm flex items-center justify-between gap-3">
                                <div>
                                    <p class="font-bold text-slate-800 dark:text-slate-100">{{ $doc->titre }}</p>
                                    <p class="text-xs text-slate-500">{{ $doc->categorie_libelle }} · {{ $doc->taille_humaine }}</p>
                                </div>
                                <a href="{{ route('enseignant.documents.telecharger', $doc) }}" aria-label="Télécharger {{ $doc->titre }}" class="text-xs font-semibold text-primary-700 hover:underline">Télécharger</a>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <div class="p-5"><x-empty-state message="Aucun document récent." /></div>
                @endif
            </section>

            <section aria-labelledby="titre-pointages" class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                    <h2 id="titre-pointages" class="text-sm font-black text-slate-900 dark:text-slate-100">Derniers pointages</h2>
                    <a href="{{ route('enseignant.presences.index') }}" class="text-xs font-bold text-indigo-700 dark:text-indigo-300 underline underline-offset-2">Mes présences</a>
                </div>
                @if($derniersPointages->isNotEmpty())
                    <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach($derniersPointages as $p)
                            <li class="px-5 py-3 text-sm flex items-center justify-between gap-3">
                                <div>
                                    <p class="font-bold text-slate-800 dark:text-slate-100">{{ $p->horodatage->format('d/m/Y à H:i') }}</p>
                                    <p class="text-xs text-slate-500">Moyen : {{ $p->moyen }} · Source : {{ $p->source }}</p>
                                </div>
                                <x-status-badge :statut="$p->type" />
                            </li>
                        @endforeach
                    </ul>
                @else
                    <div class="p-5"><x-empty-state message="Aucun pointage récent enregistré." /></div>
                @endif
            </section>
        </div>
    </div>
@endsection
