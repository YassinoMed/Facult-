@extends('layouts.app')

@section('title', 'Dossier Étudiant — ' . $etudiant->nom_complet)

@section('content')
<style>
    @keyframes fiche-apparait { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: none; } }
    .fiche-anim > * { animation: fiche-apparait 0.45s cubic-bezier(0.2, 0.7, 0.3, 1) both; }
    .fiche-anim > *:nth-child(2) { animation-delay: 0.07s; }
    .fiche-anim > *:nth-child(3) { animation-delay: 0.14s; }
    .fiche-anim section { animation: fiche-apparait 0.45s cubic-bezier(0.2, 0.7, 0.3, 1) both; }
    .fiche-anim section:nth-of-type(2) { animation-delay: 0.06s; }
    .fiche-anim section:nth-of-type(3) { animation-delay: 0.12s; }
    .fiche-anim section:nth-of-type(4) { animation-delay: 0.18s; }
    .fiche-anim section:nth-of-type(5) { animation-delay: 0.24s; }
    @media (prefers-reduced-motion: reduce) {
        .fiche-anim > * { animation: none; }
        .fiche-anim section { animation: none; }
    }
</style>
<div class="fiche-anim mx-auto max-w-7xl space-y-10 px-4 py-8 text-base leading-relaxed sm:px-6 lg:px-8">

    {{-- ── Couverture du dossier : bandeau plein, nom en grand, chiffres clés ── --}}
    <section class="-mx-4 bg-primary-950 px-4 py-10 text-white sm:-mx-6 sm:px-6 sm:py-14 lg:-mx-8 lg:px-8 print-card" aria-labelledby="titre-fiche">
        <nav aria-label="Fil d'Ariane">
            <ol class="flex flex-wrap items-center gap-2 text-[13px] font-semibold text-primary-300">
                <li><a href="{{ route('admin.etudiants.index') }}" class="hover:text-white hover:underline underline-offset-2">Registre des étudiants</a></li>
                <li aria-hidden="true" class="text-primary-400">/</li>
                <li><span aria-current="page" class="text-primary-100">{{ $etudiant->nom_complet }}</span></li>
            </ol>
        </nav>

        <div class="mt-6 flex flex-col gap-8 lg:flex-row lg:items-end lg:justify-between">
            <div class="min-w-0">
                <h1 id="titre-fiche" class="text-balance text-[clamp(1.75rem,6.5vw,5rem)] font-black leading-[0.98] tracking-tight text-white">{{ $etudiant->nom_complet }}</h1>
                <p class="mt-5 text-base text-primary-100">
                    {{ $etudiant->email }} · {{ $etudiant->telephone ?: 'Aucun téléphone renseigné' }}
                </p>
                <p class="mt-1 text-base text-primary-200">
                    CIN <span class="font-semibold tabular-nums text-white">{{ $etudiant->cin }}</span>
                    · Inscrit en {{ $etudiant->faculte->nom }}
                </p>
            </div>

            <div class="shrink-0 rounded-2xl bg-white/10 p-5 lg:text-right">
                <p class="text-[13px] font-semibold text-primary-200">Numéro d'inscription
                    <span class="mt-1 block text-3xl font-black tabular-nums text-white">{{ $etudiant->num_inscription }}</span>
                </p>
                <div class="mt-3">{!! $etudiant->statut_badge !!}</div>
            </div>
        </div>

        {{-- ── Actions administratives (statut + mots de passe), juste sous l'identité ── --}}
        <div class="mt-8 rounded-2xl bg-white/5 p-4 no-print sm:p-5">
            <p class="text-base text-primary-100">
                Statut actuel : <strong class="font-bold text-white">{{ $etudiant->statut_label }}</strong>.
                Les décisions sont historisées et notifiées à l'étudiant.
            </p>

            <div class="mt-4 flex flex-wrap gap-2">
                @if($etudiant->statut !== 'valide')
                    <form action="{{ route('admin.etudiants.updateStatut', $etudiant) }}" method="POST">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="statut" value="valide">
                        <button type="submit"
                                class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-700 px-4 py-2.5 text-base font-bold text-white transition hover:bg-emerald-600">
                            Valider la candidature
                        </button>
                    </form>
                @endif

                @if($etudiant->statut !== 'rejete')
                    <form action="{{ route('admin.etudiants.updateStatut', $etudiant) }}" method="POST">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="statut" value="rejete">
                        <button type="submit"
                                class="inline-flex items-center gap-1.5 rounded-xl bg-rose-700 px-4 py-2.5 text-base font-bold text-white transition hover:bg-rose-600">
                            Rejeter le dossier
                        </button>
                    </form>
                @endif

                @if($etudiant->statut !== 'en_attente')
                    <form action="{{ route('admin.etudiants.updateStatut', $etudiant) }}" method="POST">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="statut" value="en_attente">
                        <button type="submit"
                                class="inline-flex items-center gap-1.5 rounded-xl bg-amber-600 px-4 py-2.5 text-base font-bold text-white transition hover:bg-amber-500">
                            Remettre en attente
                        </button>
                    </form>
                @endif

                @can('archives.update')
                    <form action="{{ route('admin.etudiants.reinitialiserMotDePasse', $etudiant) }}" method="POST"
                          x-data="{ confirmer: false }" @submit.prevent="confirmer = true">
                        @csrf
                        <input type="hidden" name="confirmation" value="1">
                        <button type="submit"
                                class="inline-flex items-center gap-1.5 rounded-xl border border-white/25 px-4 py-2.5 text-base font-bold text-white transition hover:bg-white/10">
                            Réinitialiser le mot de passe
                        </button>
                        <x-modal titre="Réinitialiser le mot de passe étudiant ?" nom="confirmer">
                            <p>Un nouveau mot de passe sera généré et affiché <strong>une seule fois</strong>. L'ancien mot de passe de <strong>{{ $etudiant->nom_complet }}</strong> cessera immédiatement de fonctionner.</p>
                            <x-slot:actions>
                                <x-btn variant="secondary" @click="confirmer = false">Annuler</x-btn>
                                <x-btn @click="$root.requestSubmit()">Réinitialiser</x-btn>
                            </x-slot:actions>
                        </x-modal>
                    </form>
                @endcan
            </div>

            @if(session('mot_de_passe_provisoire'))
                <div class="mt-4 rounded-2xl bg-emerald-50 p-4" role="alert">
                    <p class="text-base font-bold text-emerald-900">Nouveau mot de passe — affiché une seule fois</p>
                    <p class="mt-1 select-all rounded-xl bg-white px-3 py-2 text-lg font-black tracking-wider text-emerald-900">{{ session('mot_de_passe_provisoire') }}</p>
                    <p class="mt-1 text-base text-emerald-800">Communiquez-le à l'étudiant maintenant (en main propre ou par téléphone) : il ne pourra plus être consulté.</p>
                </div>
            @endif
        </div>

        {{-- ── Chiffres du dossier, ligne de base de la couverture ── --}}
        <div class="mt-10 grid grid-cols-2 gap-x-8 gap-y-6 lg:grid-cols-4" role="group" aria-label="Indicateurs clés du dossier">
            @foreach([
                ['Moyenne générale', $resume['moyenne_generale'] ?? '—'],
                ['Crédits', $resume['credits_obtenus'] . ' / ' . $resume['credits_total']],
                ['Absences', $resume['absences']],
                ['Demandes ouvertes', $resume['demandes_ouvertes']],
            ] as [$libelle, $valeur])
                <div>
                    <p class="text-3xl font-black tabular-nums {{ $valeur === '—' ? 'text-primary-300' : 'text-white' }}">{{ $valeur }}</p>
                    <p class="mt-1 text-[13px] font-semibold text-primary-200">{{ $libelle }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- ── Barre d'actions secondaires ── --}}
    <div class="flex flex-col gap-3 no-print sm:flex-row sm:items-center sm:justify-between">
        <p class="text-base text-slate-600">
            Dossier déposé le {{ $etudiant->created_at->format('d/m/Y') }}, dernière mise à jour le {{ $etudiant->updated_at->format('d/m/Y') }}.
        </p>
        <div class="flex flex-wrap items-center gap-2" x-data="{ openDelete: false }">
            <x-quick-actions contexte="etudiant.show" :sujet="$etudiant" />
            @if(app(\App\Support\Modules\ModuleRegistry::class)->estActif('student-archive'))
                @can('student_archive.view')
                    <a href="{{ route('admin.etudiants.archive', $etudiant) }}"
                       class="inline-flex items-center rounded-xl px-4 py-2 text-base font-bold text-slate-700 transition hover:bg-slate-100">
                        Archive historique
                    </a>
                @endcan
            @endif
            @can('update', $etudiant)
                <a href="{{ route('admin.etudiants.modifier', $etudiant) }}"
                   class="inline-flex items-center rounded-xl px-4 py-2 text-base font-bold text-slate-700 transition hover:bg-slate-100">
                    Modifier
                </a>
            @endcan
            <button type="button" onclick="window.print()"
                    class="inline-flex items-center rounded-xl px-4 py-2 text-base font-bold text-slate-700 transition hover:bg-slate-100">
                Imprimer le dossier
            </button>

            <button type="button" @click="openDelete = true"
                    class="inline-flex items-center rounded-xl px-4 py-2 text-base font-bold text-rose-700 transition hover:bg-rose-50">
                Supprimer
            </button>

            <div x-show="openDelete" x-cloak
                 class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4 text-left">
                <div @click.away="openDelete = false"
                     class="w-full max-w-md rounded-3xl bg-white p-6 shadow-2xl"><!-- taste-skill-ignore : ombre de dialogue (suppression) -->
                    <h3 class="text-base font-bold text-slate-900">Supprimer définitivement ce dossier ?</h3>
                    <p class="mt-2 text-base leading-relaxed text-slate-600">
                        Supprimer le dossier de <strong>{{ $etudiant->nom_complet }}</strong> ({{ $etudiant->num_inscription }}) ? Cette opération est irréversible.
                    </p>
                    <div class="mt-6 flex justify-end gap-2.5">
                        <button type="button" @click="openDelete = false" class="rounded-xl px-4 py-2 text-base font-semibold text-slate-700 hover:bg-slate-100">
                            Annuler
                        </button>
                        <form action="{{ route('admin.etudiants.destroy', $etudiant) }}" method="POST">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="rounded-xl bg-rose-700 px-4 py-2 text-base font-bold text-white hover:bg-rose-600">
                                Confirmer la suppression
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Détail en onglets ── --}}
    <x-tabs :onglets="['synthese' => 'Synthèse 360°', 'infos' => 'Informations', 'parcours' => 'Parcours & situations', 'services' => 'Dossier & services']">
        <x-slot:panneau_synthese>
            <div class="space-y-12">
                {{-- Contrôle intelligent : verdict, score et anomalies sur un seul champ de couleur --}}
                @if(isset($verification))
                    <section class="rounded-3xl p-6 {{ match($verification->verdict) {
                        'CONFORME' => 'bg-emerald-50',
                        'BLOQUANT' => 'bg-rose-50',
                        default => 'bg-amber-50',
                    } }}" aria-label="Contrôle intelligent du dossier">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="flex items-start gap-3">
                                <span class="mt-2 h-3 w-3 shrink-0 rounded-full {{ match($verification->verdict) {
                                    'CONFORME' => 'bg-emerald-600',
                                    'BLOQUANT' => 'bg-rose-600',
                                    default => 'bg-amber-500',
                                } }}" aria-hidden="true"></span>
                                <div>
                                    <h2 class="text-xl font-bold text-slate-900">Contrôle intelligent : {{ $verification->verdict }}</h2>
                                    <p class="mt-1 text-base text-slate-700">
                                        Score {{ $verification->score }}/100 · {{ $verification->rulesPassed }}/{{ $verification->rulesChecked }} règles validées · {{ $verification->nombreAnomalies() }} anomalie(s)
                                    </p>
                                </div>
                            </div>
                            <div class="flex flex-wrap items-center gap-4">
                                <a href="{{ route('admin.verification.show', $etudiant) }}" class="text-base font-bold text-slate-800 underline underline-offset-2">
                                    Rapport détaillé
                                </a>
                                <form method="post" action="{{ route('admin.verification.verifier', $etudiant) }}">
                                    @csrf
                                    <button type="submit" class="inline-flex items-center rounded-xl bg-slate-900 px-5 py-2.5 text-base font-bold text-white transition hover:bg-slate-700 min-h-[44px] dark:bg-white dark:text-slate-900 dark:hover:bg-slate-200">
                                        Relancer
                                    </button>
                                </form>
                            </div>
                        </div>
                    </section>
                @endif

                {{-- Anomalies à traiter : liste de travail, à part du verdict --}}
                @if(!empty($anomaliesFiche))
                    <section aria-label="Anomalies à traiter">
                        <h2 class="text-xl font-bold text-slate-900">Anomalies à traiter ({{ count($anomaliesFiche) }})</h2>
                        <ul class="mt-3 space-y-2.5 text-base text-slate-700">
                            @foreach($anomaliesFiche as $anomalie)
                                <li class="flex flex-wrap items-center gap-2">
                                    <svg class="h-4 w-4 shrink-0 text-amber-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                                    </svg>
                                    <span>{{ $anomalie['message'] }}</span>
                                    <a href="{{ $anomalie['lien'] }}" class="font-bold underline underline-offset-2">{{ $anomalie['action'] }}</a>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                {{-- Doublons --}}
                @if($doublons->isNotEmpty())
                    <section class="rounded-3xl bg-rose-50 p-6" role="alert" aria-label="Doublons potentiels">
                        <h2 class="text-xl font-bold text-rose-900">Doublons potentiels</h2>
                        <p class="mt-1 text-base text-rose-900">Comparaison humaine requise, jamais de fusion automatique.</p>
                        <ul class="mt-3 space-y-1.5 text-base text-rose-800">
                            @foreach($doublons as $doublon)
                                <li>
                                    <a href="{{ route('admin.etudiants.show', $doublon) }}" class="font-bold underline underline-offset-2">{{ $doublon->nom_complet }}</a>
                                    <span class="tabular-nums"> · {{ $doublon->cin }} · {{ $doublon->email }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                {{-- Complétude du dossier --}}
                <section aria-label="Complétude du dossier">
                    <h2 class="text-xl font-bold text-slate-900">Dossier complet à {{ $completude['pourcentage'] }} %</h2>
                    <div class="mt-4 h-2 max-w-xl overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700" aria-hidden="true">
                        <div class="h-full rounded-full bg-primary-700" style="width: {{ $completude['pourcentage'] }}%"></div>
                    </div>
                    <ul class="mt-6 grid grid-cols-1 gap-x-10 gap-y-2.5 text-base sm:grid-cols-2">
                        @foreach($completude['items'] as $item)
                            <li class="flex items-center gap-2.5 {{ $item['ok'] ? 'text-slate-700' : 'font-bold text-amber-800' }}">
                                <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full {{ $item['ok'] ? 'bg-emerald-700 text-white' : 'bg-amber-500 text-white' }}" aria-hidden="true">@if($item['ok'])<svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>@else<svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>@endif</span>
                                <span class="{{ $item['ok'] ? '' : 'font-semibold text-amber-900' }}">{{ $item['libelle'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>

                {{-- Éligibilité & affectation --}}
                @if(isset($ficheEligibilite))
                    <section aria-label="Éligibilité et affectation">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <h2 class="text-xl font-bold text-slate-900">Éligibilité & affectation</h2>
                            <span class="rounded-full px-3 py-1 text-sm font-bold
                                @if($ficheEligibilite['statut_affectation']['badge'] === 'emerald') bg-emerald-100 text-emerald-900
                                @elseif($ficheEligibilite['statut_affectation']['badge'] === 'sky') bg-sky-100 text-sky-900
                                @elseif($ficheEligibilite['statut_affectation']['badge'] === 'rose') bg-rose-100 text-rose-900
                                @else bg-slate-200 text-slate-800 dark:bg-slate-800 dark:text-slate-100 @endif">
                                {{ $ficheEligibilite['statut_affectation']['label'] }}
                            </span>
                        </div>
                        <p class="mt-1 text-base text-slate-600">Contrôle des 5 piliers administratifs et affectation de groupe.</p>

                        <dl class="mt-5">
                            @foreach($ficheEligibilite['axes'] as $axe)
                                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-2.5">
                                    <dt class="flex items-center gap-2 text-base font-semibold text-slate-900">
                                        <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ $axe['badge'] === 'emerald' ? 'bg-emerald-600' : ($axe['badge'] === 'amber' ? 'bg-amber-500' : ($axe['badge'] === 'sky' ? 'bg-sky-600' : ($axe['badge'] === 'rose' ? 'bg-rose-600' : 'bg-slate-500'))) }}" aria-hidden="true"></span>
                                        {{ $axe['label'] }}
                                    </dt>
                                    <dd class="text-base text-slate-700">
                                        {{ $axe['texte'] }}@if(!empty($axe['detail']))<span class="text-slate-500"> — {{ $axe['detail'] }}</span>@endif
                                    </dd>
                                </div>
                            @endforeach
                        </dl>

                        @if(!empty($ficheEligibilite['blocking_reasons']))
                            <div class="mt-5 rounded-2xl bg-rose-100 p-4" role="alert">
                                <p class="text-base font-bold text-rose-900">Affectation bloquée ({{ count($ficheEligibilite['blocking_reasons']) }} anomalie(s))</p>
                                <ul class="mt-1.5 list-inside list-disc space-y-0.5 text-base text-rose-800">
                                    @foreach($ficheEligibilite['blocking_reasons'] as $motif)
                                        <li>{{ $motif }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </section>
                @endif

                {{-- Finances --}}
                @if($soldeFinance !== null)
                    <section aria-label="Situation financière">
                        <h2 class="text-xl font-bold text-slate-900">Situation financière</h2>
                        <p class="mt-2 text-base tabular-nums text-slate-700">
                            Total {{ \App\Modules\Finances\Models\Facture::formater($soldeFinance['total']) }} · Payé {{ \App\Modules\Finances\Models\Facture::formater($soldeFinance['paye']) }} ·
                            <strong class="text-xl font-bold text-slate-900">Reste {{ \App\Modules\Finances\Models\Facture::formater($soldeFinance['reste']) }}</strong>
                        </p>
                    </section>
                @endif

                {{-- Activité récente --}}
                <section aria-label="Activité récente">
                    <h2 class="text-xl font-bold text-slate-900">Activité récente</h2>
                    <div class="mt-4">
                        <x-timeline :elements="$timeline" />
                    </div>
                </section>

                <div class="flex flex-wrap gap-2">
                    @if($etudiant->groupe_id)
                        <a href="{{ route('admin.emplois-du-temps.explorer', ['groupe_id' => $etudiant->groupe_id]) }}" class="rounded-xl bg-slate-900 px-4 py-2.5 text-base font-bold text-white transition hover:bg-slate-700 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-200">Voir le planning du groupe</a>
                    @endif
                    <a href="{{ route('admin.etudiants.show', $etudiant) }}#parcours" class="rounded-xl px-4 py-2.5 text-base font-bold text-slate-800 underline underline-offset-2">Gérer le parcours</a>
                </div>
            </div>
        </x-slot:panneau_synthese>

        <x-slot:panneau_infos>
            <div class="rounded-3xl bg-white p-6 sm:p-8">
                <h2 class="text-xl font-bold text-slate-900">Informations déclarées</h2>
                <div class="mt-6 grid grid-cols-1 gap-x-10 gap-y-8 md:grid-cols-3">
                    {{-- État civil --}}
                    <section aria-label="État civil et contact">
                        <h3 class="text-base font-bold text-slate-900">État civil & contact</h3>
                        <dl class="mt-3 space-y-3 text-base">
                            <div>
                                <dt class="text-slate-500">Nom & prénom</dt>
                                <dd class="mt-0.5 font-semibold text-slate-900">{{ $etudiant->nom_complet }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Numéro de CIN</dt>
                                <dd class="mt-0.5 font-semibold tabular-nums text-slate-900">{{ $etudiant->cin }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Sexe</dt>
                                <dd class="mt-0.5 text-slate-800">{{ $etudiant->sexe === 'M' ? 'Masculin' : 'Féminin' }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Date de naissance</dt>
                                <dd class="mt-0.5 tabular-nums text-slate-800">{{ $etudiant->date_naissance ? $etudiant->date_naissance->format('d/m/Y') : 'Non renseignée' }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Adresse email</dt>
                                <dd class="mt-0.5 break-all"><a href="mailto:{{ $etudiant->email }}" class="font-semibold text-primary-700 underline underline-offset-2">{{ $etudiant->email }}</a></dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Téléphone</dt>
                                <dd class="mt-0.5 tabular-nums text-slate-800">
                                    @if($etudiant->telephone)
                                        <a href="tel:{{ $etudiant->telephone }}" class="underline underline-offset-2">{{ $etudiant->telephone }}</a>
                                    @else
                                        <span class="text-slate-500">Non renseigné</span>
                                    @endif
                                </dd>
                            </div>
                        </dl>
                    </section>

                    {{-- Résidence & bac --}}
                    <section aria-label="Résidence et baccalauréat">
                        <h3 class="text-base font-bold text-slate-900">Résidence & baccalauréat</h3>
                        <dl class="mt-3 space-y-3 text-base">
                            <div>
                                <dt class="text-slate-500">Ville de résidence</dt>
                                <dd class="mt-0.5 font-semibold text-slate-900">{{ $etudiant->ville }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Code postal</dt>
                                <dd class="mt-0.5 tabular-nums text-slate-800">{{ $etudiant->code_postal }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Adresse postale</dt>
                                <dd class="mt-0.5 leading-relaxed text-slate-800">{{ $etudiant->adresse }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Session du baccalauréat</dt>
                                <dd class="mt-0.5 font-bold text-slate-900">Session {{ $etudiant->annee_bac }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Mention obtenue</dt>
                                <dd class="mt-1"><span class="rounded-full bg-primary-100 px-3 py-1 text-sm font-bold text-primary-900">{{ $etudiant->mention_bac ?: 'Très Bien' }}</span></dd>
                            </div>
                        </dl>
                    </section>

                    {{-- Affectation universitaire --}}
                    <section aria-label="Affectation universitaire">
                        <h3 class="text-base font-bold text-slate-900">Affectation universitaire</h3>
                        <dl class="mt-3 space-y-3 text-base">
                            <div>
                                <dt class="text-slate-500">Faculté d'accueil</dt>
                                <dd class="mt-0.5 font-semibold leading-snug text-slate-900">
                                    {{ $etudiant->faculte->nom }}
                                    <span class="block text-slate-500">Code {{ $etudiant->faculte->code }}</span>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Niveau d'études</dt>
                                <dd class="mt-1"><span class="rounded-full bg-primary-100 px-3 py-1 text-sm font-bold text-primary-900">{{ $etudiant->niveau }}</span></dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Année académique</dt>
                                <dd class="mt-0.5 font-semibold text-slate-900">{{ $etudiant->annee_academique }}</dd>
                            </div>
                            @if($derniereInscription !== null)
                                <div>
                                    <dt class="text-slate-500">Paiement (site externe)</dt>
                                    <dd class="mt-1">
                                        <span class="rounded-full px-3 py-1 text-sm font-bold {{ $derniereInscription->paye ? 'bg-emerald-100 text-emerald-900' : 'bg-amber-100 text-amber-900' }}">
                                            {{ $derniereInscription->paye ? 'Oui — effectué' : 'Non — non effectué' }}
                                        </span>
                                    </dd>
                                </div>
                            @endif
                            <div>
                                <dt class="text-slate-500">Date de dépôt du dossier</dt>
                                <dd class="mt-0.5 tabular-nums text-slate-800">{{ $etudiant->created_at->format('d/m/Y à H:i') }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Dernière modification</dt>
                                <dd class="mt-0.5 tabular-nums text-slate-800">{{ $etudiant->updated_at->format('d/m/Y à H:i') }}</dd>
                            </div>
                            @if($etudiant->groupe_id)
                                <div>
                                    <dt class="text-slate-500">Groupe</dt>
                                    <dd class="mt-0.5 font-semibold tabular-nums text-slate-900">{{ $etudiant->groupe->code }} — {{ $etudiant->groupe->nom }}</dd>
                                </div>
                            @endif
                        </dl>
                    </section>
                </div>
            </div>
        </x-slot:panneau_infos>

        <x-slot:panneau_parcours>
            <div class="space-y-12">
                <section aria-label="Situation actuelle">
                    <h2 class="text-xl font-bold text-slate-900">Situation actuelle</h2>
                    <dl class="mt-4 grid grid-cols-1 gap-x-10 gap-y-4 text-base sm:grid-cols-3">
                        <div><dt class="text-slate-500">Programme</dt><dd class="mt-0.5 font-semibold text-slate-900">{{ $etudiant->programmeRef?->nom ?? '—' }}</dd></div>
                        <div><dt class="text-slate-500">Niveau</dt><dd class="mt-0.5 font-semibold text-slate-900">{{ $etudiant->niveauRef?->code ?? $etudiant->niveau }}</dd></div>
                        <div><dt class="text-slate-500">Groupe</dt><dd class="mt-0.5 font-semibold text-slate-900">{{ $etudiant->groupe?->code ?? '—' }}</dd></div>
                    </dl>
                    @if($etudiant->estSuspendu())
                        <p class="mt-4 rounded-2xl bg-rose-100 p-4 text-base font-bold text-rose-900" role="alert">Suspension active : les opérations académiques sont bloquées.</p>
                    @endif
                </section>

                {{-- Progression LMD --}}
                @if($derniereInscription !== null && $progression !== null)
                    <section aria-label="Progression LMD">
                        <h2 class="text-xl font-bold text-slate-900">Progression LMD</h2>
                        <dl class="mt-4 grid grid-cols-1 gap-x-10 gap-y-4 text-base sm:grid-cols-3">
                            <div><dt class="text-slate-500">Niveau actuel</dt><dd class="mt-0.5 font-semibold text-slate-900">{{ $derniereInscription->niveauRef?->code ?? $derniereInscription->niveau }} ({{ $derniereInscription->anneeUniversitaire?->libelle }})</dd></div>
                            <div><dt class="text-slate-500">Décision jury</dt><dd class="mt-0.5 font-semibold text-slate-900">{{ $progression['decision'] ?? '—' }}</dd></div>
                            <div><dt class="text-slate-500">Niveau cible</dt><dd class="mt-0.5 font-semibold text-slate-900">{{ $progression['niveau_cible']?->code ?? '—' }}{{ $progression['annee_cible'] ? ' · ' . $progression['annee_cible']->libelle : '' }}</dd></div>
                        </dl>
                        @if($progression['existante'] !== null)
                            <p class="mt-4 text-base text-slate-600">Inscription {{ $progression['annee_cible']?->libelle }} déjà créée (idempotence).</p>
                        @elseif($progression['peut_progresser'])
                            @can('archives.create')
                                <form method="POST" action="{{ route('admin.inscriptions.progresser', $derniereInscription) }}" class="mt-5 flex flex-wrap items-end gap-2">
                                    @csrf
                                    <input type="hidden" name="confirmer" value="1">
                                    <p class="w-full text-base text-slate-600">Action : {{ $progression['action'] }} — la décision publiée et le référentiel déterminent la cible. Cibles explicites = admission externe validée (ex. Master), jamais devinées.</p>
                                    <select name="programme_id" aria-label="Programme cible (optionnel)" class="rounded-xl border border-slate-300 px-3 py-2 text-base min-h-[44px] dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                                        <option value="">Programme calculé</option>
                                        @foreach($programmesOptions as $programme)
                                            <option value="{{ $programme->id }}">{{ $programme->code }} — {{ $programme->nom }}</option>
                                        @endforeach
                                    </select>
                                    <select name="niveau_id" aria-label="Niveau cible (optionnel)" class="rounded-xl border border-slate-300 px-3 py-2 text-base min-h-[44px] dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                                        <option value="">Niveau calculé</option>
                                        @foreach($niveauxOptions as $niveau)
                                            <option value="{{ $niveau->id }}">{{ $niveau->code }}</option>
                                        @endforeach
                                    </select>
                                    <x-btn type="submit" variant="secondary">Créer l'inscription {{ $progression['annee_cible']?->libelle }}</x-btn>
                                </form>
                            @endcan
                        @else
                            <p class="mt-4 text-base font-bold text-amber-800">{{ implode(' ', $progression['blocages']) }}</p>
                        @endif
                        @if($errors->has('progression'))
                            <p class="mt-2 text-base font-bold text-rose-700">{{ $errors->first('progression') }}</p>
                        @endif
                    </section>
                @endif

                @can('archives.update')
                    <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-2">
                        <section class="rounded-3xl bg-white p-6 sm:p-8" aria-label="Affecter ou changer de groupe">
                            <form method="POST" action="{{ route('admin.etudiants.affectations.store', $etudiant) }}" class="space-y-3">
                                @csrf
                                <h2 class="text-xl font-bold text-slate-900">Affecter / changer de groupe</h2>
                                <select name="groupe_id" required aria-label="Groupe cible" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-base min-h-[44px] dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                                    @foreach($groupesOptions as $groupe)
                                        <option value="{{ $groupe->id }}">{{ $groupe->code }}{{ $groupe->capacite ? ' (' . $groupe->effectif_actuel . '/' . $groupe->capacite . ')' : '' }}</option>
                                    @endforeach
                                </select>
                                <select name="annee_universitaire_id" required aria-label="Année" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-base min-h-[44px] dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                                    @foreach($anneesOptions as $annee)
                                        <option value="{{ $annee->id }}">{{ $annee->libelle }}</option>
                                    @endforeach
                                </select>
                                <input name="motif" required maxlength="255" placeholder="Motif (obligatoire)" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-base min-h-[44px] dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                                <div class="flex flex-wrap items-center gap-3">
                                    <x-btn type="submit" variant="secondary">Affecter (historisé)</x-btn>
                                    <button type="submit" formmethod="GET" formaction="{{ route('admin.etudiants.affectations.previsualiser', $etudiant) }}" formnovalidate
                                        class="inline-flex items-center rounded-xl px-4 py-2 text-base font-bold text-slate-800 underline underline-offset-2 min-h-[44px]">
                                        Prévisualiser (contraintes + impacts)
                                    </button>
                                </div>
                            </form>
                        </section>

                        <section class="rounded-3xl bg-white p-6 sm:p-8" aria-label="Suspendre, transférer ou rattacher">
                            <h2 class="text-xl font-bold text-slate-900">Suspendre, transférer, rattacher</h2>
                            <div class="mt-6 space-y-8">
                                <div>
                                    <h3 class="text-base font-bold text-slate-900">Suspendre</h3>
                                    <form method="POST" action="{{ route('admin.etudiants.suspendre', $etudiant) }}" class="mt-3 space-y-3">
                                        @csrf
                                        <div class="grid grid-cols-2 gap-2">
                                            <input type="date" name="date_debut" required aria-label="Début" class="rounded-xl border border-slate-300 px-3 py-2 text-base min-h-[44px] dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                                            <input type="date" name="date_fin" aria-label="Fin prévue" class="rounded-xl border border-slate-300 px-3 py-2 text-base min-h-[44px] dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                                        </div>
                                        <input name="motif" required maxlength="255" placeholder="Motif (obligatoire)" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-base min-h-[44px] dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                                        <x-btn type="submit" variant="danger">Suspendre</x-btn>
                                    </form>
                                </div>
                                <div>
                                    <h3 class="text-base font-bold text-slate-900">Transférer (programme)</h3>
                                    <form method="POST" action="{{ route('admin.etudiants.transferer', $etudiant) }}" class="mt-3 space-y-3">
                                        @csrf
                                        <select name="programme_id" required aria-label="Programme cible" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-base min-h-[44px] dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                                            @foreach($programmesOptions as $programme)
                                                <option value="{{ $programme->id }}">{{ $programme->code }} — {{ $programme->nom }}</option>
                                            @endforeach
                                        </select>
                                        <input name="motif" required maxlength="255" placeholder="Motif (obligatoire)" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-base min-h-[44px] dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                                        <x-btn type="submit" variant="secondary">Transférer (dossier conservé)</x-btn>
                                    </form>
                                </div>
                                <div>
                                    <h3 class="text-base font-bold text-slate-900">Rattachement LMD</h3>
                                    <form method="POST" action="{{ route('admin.etudiants.rattachement', $etudiant) }}" class="mt-3 space-y-3">
                                        @csrf @method('PATCH')
                                        <select name="programme_id" aria-label="Programme" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-base min-h-[44px] dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                                            <option value="">— Programme —</option>
                                            @foreach($programmesOptions as $programme)
                                                <option value="{{ $programme->id }}" @selected($etudiant->programme_id == $programme->id)>{{ $programme->code }}</option>
                                            @endforeach
                                        </select>
                                        <select name="niveau_id" aria-label="Niveau" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-base min-h-[44px] dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                                            <option value="">— Niveau —</option>
                                            @foreach($niveauxOptions as $niveau)
                                                <option value="{{ $niveau->id }}" @selected($etudiant->niveau_id == $niveau->id)>{{ $niveau->programme->code }} · {{ $niveau->code }}</option>
                                            @endforeach
                                        </select>
                                        <x-btn type="submit" variant="secondary">Rattacher</x-btn>
                                    </form>
                                </div>
                            </div>
                        </section>
                    </div>
                @endcan

                <section aria-label="Historique des situations">
                    <h2 class="text-xl font-bold text-slate-900">Historique ({{ $etudiant->situations->count() }} événements)</h2>
                    @if($etudiant->situations->isNotEmpty())
                        <ol class="mt-4 space-y-4">
                            @foreach($etudiant->situations as $situation)
                                <li class="text-base">
                                    <strong class="font-bold text-slate-900">{{ $situation->type }}</strong>
                                    @if($situation->ancienne_valeur || $situation->nouvelle_valeur)
                                        <span class="text-slate-600"> · {{ $situation->ancienne_valeur ?? '—' }} → {{ $situation->nouvelle_valeur ?? '—' }}</span>
                                    @endif
                                    @if($situation->motif)<span class="mt-0.5 block text-slate-600">{{ $situation->motif }}</span>@endif
                                    <span class="mt-0.5 block tabular-nums text-slate-500">{{ $situation->date_effet->format('d/m/Y') }}</span>
                                </li>
                            @endforeach
                        </ol>
                    @else
                        <p class="mt-3 text-base text-slate-600">Aucun événement historisé.</p>
                    @endif
                </section>

                <section aria-label="Affectations">
                    <h2 class="text-xl font-bold text-slate-900">Affectations ({{ $etudiant->affectations->count() }})</h2>
                    @if($etudiant->affectations->isNotEmpty())
                        <ul class="mt-4 space-y-4">
                            @foreach($etudiant->affectations as $affectation)
                                <li class="text-base">
                                    <strong class="font-bold tabular-nums text-slate-900">{{ $affectation->groupe->code }}</strong>
                                    <span class="text-slate-600">{{ $affectation->anneeUniversitaire->libelle }} · {{ $affectation->statut }}</span>
                                    <span class="mt-0.5 block tabular-nums text-slate-500">{{ $affectation->date_debut->format('d/m/Y') }}{{ $affectation->date_fin ? ' → ' . $affectation->date_fin->format('d/m/Y') : '' }}</span>
                                    @if($affectation->statut === 'active')
                                        @can('archives.update')
                                            <form method="POST" action="{{ route('admin.etudiants.affectations.retirer', $etudiant) }}" class="mt-2 flex gap-2">
                                                @csrf
                                                <input type="hidden" name="affectation_id" value="{{ $affectation->id }}" />
                                                <input name="motif" required maxlength="255" placeholder="Motif du retrait" aria-label="Motif du retrait" class="flex-1 rounded-xl border border-slate-300 px-2 py-1.5 text-base dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                                                <button type="submit" class="rounded-xl px-3 py-1.5 font-bold text-rose-700 underline underline-offset-2">Retirer</button>
                                            </form>
                                        @endcan
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="mt-3 text-base text-slate-600">Aucune affectation historisée.</p>
                    @endif
                </section>
            </div>
        </x-slot:panneau_parcours>

        <x-slot:panneau_services>
            <div class="no-print">
                <h2 class="text-xl font-bold text-slate-900">Dossier & services liés</h2>
                <ul class="mt-4 space-y-3 text-base">
                    @if(app(\App\Support\Modules\ModuleRegistry::class)->estActif('student-dossiers'))
                        <li><a href="{{ route('admin.student-dossiers.index', ['search' => $etudiant->cin]) }}" class="font-bold text-primary-800 underline underline-offset-2">Dossier administratif — ouvrir →</a></li>
                    @endif
                    @if(app(\App\Support\Modules\ModuleRegistry::class)->estActif('online-requests'))
                        <li><a href="{{ route('admin.demandes.index', ['search' => $etudiant->cin]) }}" class="font-bold text-primary-800 underline underline-offset-2">Demandes — voir →</a></li>
                    @endif
                    @if(app(\App\Support\Modules\ModuleRegistry::class)->estActif('schedule') && $etudiant->groupe_id)
                        <li><a href="{{ route('admin.emplois-du-temps.index') }}" class="font-bold text-primary-800 underline underline-offset-2">Emploi du temps du groupe {{ $etudiant->groupe->code }} →</a></li>
                    @endif
                </ul>
            </div>
        </x-slot:panneau_services>
    </x-tabs>
</div>
@endsection
