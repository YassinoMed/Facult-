@extends('layouts.app')

@section('title', 'Dossier Étudiant — ' . $etudiant->nom_complet)

@section('content')
<div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">

    {{-- ── Barre Supérieure & Navigation ── --}}
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 no-print">
        <a href="{{ route('admin.etudiants.index') }}"
           class="inline-flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-slate-500 hover:text-primary-700 transition-smooth">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
            </svg>
            Retour au registre des étudiants
        </a>

        <div class="flex items-center gap-2.5" x-data="{ openDelete: false }">
            <x-quick-actions contexte="etudiant.show" :sujet="$etudiant" />
            @if(app(\App\Support\Modules\ModuleRegistry::class)->estActif('student-archive'))
                @can('student_archive.view')
                    <a href="{{ route('admin.etudiants.archive', $etudiant) }}"
                       class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 shadow-xs transition-smooth">
                        Archive historique
                    </a>
                @endcan
            @endif
            <button type="button"
                    onclick="window.print()"
                    class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 shadow-xs transition-smooth">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24-1.076-.673-2.13-1.28-3.14m0 0A9.953 9.953 0 0 1 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-1.474 0-2.887-.319-4.158-.89m0 0L3 21l1.89-3.72" />
                </svg>
                Imprimer le dossier
            </button>

            <button type="button"
                    @click="openDelete = true"
                    class="inline-flex items-center gap-1.5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-2 text-xs font-bold text-rose-700 hover:bg-rose-100 transition-smooth">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                </svg>
                Supprimer
            </button>

            {{-- Modal de confirmation de suppression --}}
            <div x-show="openDelete" x-cloak
                 class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 text-left">
                <div @click.away="openDelete = false"
                     class="w-full max-w-md rounded-3xl bg-white p-6 shadow-2xl">
                    <h3 class="text-base font-bold text-slate-900">Supprimer définitivement ce dossier ?</h3>
                    <p class="mt-2 text-xs sm:text-sm text-slate-600">
                        Êtes-vous certain de vouloir supprimer le dossier de <strong>{{ $etudiant->nom_complet }}</strong> ({{ $etudiant->num_inscription }}) ? Cette opération est irréversible.
                    </p>
                    <div class="mt-6 flex justify-end gap-2.5">
                        <button type="button" @click="openDelete = false" class="rounded-xl border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                            Annuler
                        </button>
                        <form action="{{ route('admin.etudiants.destroy', $etudiant) }}" method="POST">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="rounded-xl bg-rose-600 px-4 py-2 text-xs font-bold text-white hover:bg-rose-700 shadow-xs">
                            Confirmer la suppression
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

    {{-- ── En-tête Dossier Étudiant avec Présentation Profil ── --}}
    <div class="mb-8 overflow-hidden rounded-3xl border border-slate-200/90 bg-white shadow-xl shadow-slate-200/40 print-card">
        <div class="bg-gradient-to-r from-slate-950 via-primary-950 to-primary-900 p-6 sm:p-10 text-white relative">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-6">
                <div class="flex items-center gap-5">
                    <div class="flex h-20 w-20 items-center justify-center rounded-2xl bg-white/10 text-2xl font-black text-white shadow-inner backdrop-blur-md border border-white/10">
                        {{ strtoupper(substr($etudiant->prenom, 0, 1)) }}{{ strtoupper(substr($etudiant->nom, 0, 1)) }}
                    </div>
                    <div>
                        <div class="flex flex-wrap items-center gap-2.5">
                            <h1 class="text-2xl sm:text-3xl font-black tracking-tight">{{ $etudiant->nom_complet }}</h1>
                            <span class="rounded-lg bg-white/15 px-2.5 py-0.5 font-mono text-xs font-bold text-white border border-white/10">
                                CIN: {{ $etudiant->cin }}
                            </span>
                        </div>
                        <p class="text-xs sm:text-sm text-primary-200/80 mt-1">
                            {{ $etudiant->email }} • {{ $etudiant->telephone ?: 'Aucun téléphone renseigné' }}
                        </p>
                    </div>
                </div>

                {{-- N° Inscription Box --}}
                <div class="text-left md:text-right bg-white/10 border border-white/15 p-4 rounded-2xl backdrop-blur-md">
                    <span class="block text-[10px] font-bold uppercase tracking-widest text-primary-200">N° Inscription Officiel</span>
                    <span class="font-mono text-xl sm:text-2xl font-black tracking-wider text-white">
                        {{ $etudiant->num_inscription }}
                    </span>
                    <div class="mt-2">
                        {!! $etudiant->statut_badge !!}
                    </div>
                </div>
            </div>
        </div>

        {{-- ── Quick Status Update Bar (Scolarité) ── --}}
        <div class="bg-slate-50/80 border-b border-slate-200/80 px-6 py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-4 no-print">
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Action administrative</p>
                <p class="text-xs text-slate-600 mt-0.5">Statut actuel : <strong class="text-slate-900">{{ $etudiant->statut_label }}</strong></p>
            </div>

            <div class="flex flex-wrap gap-2">
                @if($etudiant->statut !== 'valide')
                    <form action="{{ route('admin.etudiants.updateStatut', $etudiant) }}" method="POST">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="statut" value="valide">
                        <button type="submit"
                                class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-4 py-2 text-xs font-bold text-white shadow-xs hover:bg-emerald-700 transition-smooth">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                            </svg>
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
                                class="inline-flex items-center gap-1.5 rounded-xl bg-rose-600 px-4 py-2 text-xs font-bold text-white shadow-xs hover:bg-rose-700 transition-smooth">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                            </svg>
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
                                class="inline-flex items-center gap-1.5 rounded-xl bg-amber-500 px-4 py-2 text-xs font-bold text-white shadow-xs hover:bg-amber-600 transition-smooth">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
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
                                class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 transition-smooth">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-6 6m-3.46-2.54a3.75 3.75 0 1 1-5.3-5.3m-2.99 2.99a6 6 0 0 1 6-6v.75c0 .67.27 1.31.73 1.77l.52.52c.46.46.71 1.1.71 1.77v.75" />
                            </svg>
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
                <div class="mt-3 rounded-xl border-2 border-emerald-500 bg-emerald-50 p-4" role="alert">
                    <p class="text-xs font-bold uppercase tracking-wider text-emerald-800">Nouveau mot de passe — affiché une seule fois</p>
                    <p class="mt-1 select-all rounded-lg bg-white px-3 py-2 font-mono text-lg font-black tracking-wider text-emerald-900">{{ session('mot_de_passe_provisoire') }}</p>
                    <p class="mt-1 text-xs text-emerald-700">Communiquez-le à l'étudiant maintenant (en main propre ou par téléphone) — il ne pourra plus être consulté.</p>
                </div>
            @endif
        </div>

        {{-- ── Détail en onglets : informations + dossier & services ── --}}
        <x-tabs :onglets="['synthese' => 'Synthèse 360°', 'infos' => 'Informations', 'parcours' => 'Parcours & situations', 'services' => 'Dossier & services']">
            <x-slot:panneau_synthese>
                {{-- ── Vérification intelligente du dossier (§14) ── --}}
                @if(isset($verification))
                    <div class="mb-4 rounded-2xl border p-4 shadow-xs {{ match($verification->verdict) {
                        'CONFORME' => 'border-emerald-200 bg-emerald-50/70',
                        'BLOQUANT' => 'border-rose-200 bg-rose-50/70',
                        default => 'border-amber-200 bg-amber-50/70',
                    } }}">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="flex items-center gap-3">
                                <span class="inline-flex h-3 w-3 rounded-full {{ match($verification->verdict) {
                                    'CONFORME' => 'bg-emerald-500',
                                    'BLOQUANT' => 'bg-rose-500',
                                    default => 'bg-amber-500',
                                } }} animate-pulse"></span>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs font-black uppercase tracking-wider text-slate-700">Contrôle intelligent :</span>
                                        <span class="text-xs font-black {{ match($verification->verdict) {
                                            'CONFORME' => 'text-emerald-700',
                                            'BLOQUANT' => 'text-rose-700',
                                            default => 'text-amber-700',
                                        } }}">{{ $verification->verdict }}</span>
                                    </div>
                                    <p class="text-xs text-slate-600">
                                        Score : <strong class="font-black">{{ $verification->score }}/100</strong> ·
                                        {{ $verification->rulesPassed }}/{{ $verification->rulesChecked }} règles validées ·
                                        {{ $verification->nombreAnomalies() }} anomalie(s)
                                    </p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <a href="{{ route('admin.verification.show', $etudiant) }}" class="inline-flex items-center gap-1 rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-50 transition-smooth">
                                    Voir rapport détaillé
                                </a>
                                <form method="post" action="{{ route('admin.verification.verifier', $etudiant) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="inline-flex items-center gap-1 rounded-xl bg-slate-900 px-3 py-1.5 text-xs font-bold text-white hover:bg-slate-800 transition-smooth">
                                        Relancer
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                @endif
                @if(!empty($anomaliesFiche))
                    <div class="mb-4 rounded-2xl border border-amber-200 bg-amber-50 p-4" role="alert">
                        <p class="text-xs font-black uppercase tracking-wider text-amber-700">Anomalies détectées ({{ count($anomaliesFiche) }})</p>
                        <ul class="mt-2 space-y-1.5 text-sm">
                            @foreach($anomaliesFiche as $anomalie)
                                <li class="flex flex-wrap items-center gap-2">
                                    <span>⚠ {{ $anomalie['message'] }}</span>
                                    <a href="{{ $anomalie['lien'] }}" class="text-xs font-bold text-primary-800 underline underline-offset-2">{{ $anomalie['action'] }}</a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    @foreach([
                        ['Moyenne générale', $resume['moyenne_generale'] ?? '—'],
                        ['Crédits', $resume['credits_obtenus'] . ' / ' . $resume['credits_total']],
                        ['Absences', $resume['absences']],
                        ['Demandes ouvertes', $resume['demandes_ouvertes']],
                    ] as [$libelle, $valeur])
                        <div class="rounded-2xl border border-slate-200 bg-white p-4">
                            <p class="text-2xl font-black tabular-nums text-slate-900">{{ $valeur }}</p>
                            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">{{ $libelle }}</p>
                        </div>
                    @endforeach
                </div>

                <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-4">
                    <p class="text-xs font-black uppercase tracking-wider text-slate-500">Dossier — {{ $completude['pourcentage'] }} %</p>                    <ul class="mt-2 space-y-1 text-sm">
                        @foreach($completude['items'] as $item)
                            <li>{{ $item['ok'] ? '✓' : '⚠' }} {{ $item['libelle'] }}</li>
                        @endforeach
                    </ul>
                </div>

                @if(isset($ficheEligibilite))
                    {{-- ── PHASE 22 : État d'éligibilité et d'affectation intelligente ── --}}
                    <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-xs">
                        <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                            <div>
                                <p class="text-xs font-black uppercase tracking-wider text-slate-800 flex items-center gap-2">
                                    <span class="inline-block h-2 w-2 rounded-full {{ $ficheEligibilite['eligible'] ? 'bg-emerald-500' : 'bg-rose-500' }}"></span>
                                    Éligibilité & Affectation
                                </p>
                                <p class="text-[11px] text-slate-500 mt-0.5">Contrôle des 5 piliers administratifs et affectation groupe</p>
                            </div>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold
                                @if($ficheEligibilite['statut_affectation']['badge'] === 'emerald') bg-emerald-100 text-emerald-800
                                @elseif($ficheEligibilite['statut_affectation']['badge'] === 'sky') bg-sky-100 text-sky-800
                                @elseif($ficheEligibilite['statut_affectation']['badge'] === 'rose') bg-rose-100 text-rose-800
                                @else bg-slate-100 text-slate-700 @endif">
                                {{ $ficheEligibilite['statut_affectation']['label'] }}
                            </span>
                        </div>

                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2.5 mt-3">
                            @foreach($ficheEligibilite['axes'] as $cle => $axe)
                                <div class="rounded-xl border p-2.5 transition-colors
                                    @if($axe['badge'] === 'emerald') border-emerald-200 bg-emerald-50/40
                                    @elseif($axe['badge'] === 'amber') border-amber-200 bg-amber-50/40
                                    @elseif($axe['badge'] === 'sky') border-sky-200 bg-sky-50/40
                                    @elseif($axe['badge'] === 'rose') border-rose-200 bg-rose-50/40
                                    @else border-slate-200 bg-slate-50/60 @endif">
                                    <div class="flex items-center justify-between">
                                        <span class="text-[10px] font-bold uppercase tracking-wider text-slate-500">{{ $axe['label'] }}</span>
                                        <span class="text-xs">
                                            @if($axe['badge'] === 'emerald') 🟢
                                            @elseif($axe['badge'] === 'amber') 🟠
                                            @elseif($axe['badge'] === 'sky') 🔵
                                            @elseif($axe['badge'] === 'rose') 🔴
                                            @else ⚫ @endif
                                        </span>
                                    </div>
                                    <p class="mt-1 text-xs font-bold
                                        @if($axe['badge'] === 'emerald') text-emerald-800
                                        @elseif($axe['badge'] === 'amber') text-amber-800
                                        @elseif($axe['badge'] === 'sky') text-sky-800
                                        @elseif($axe['badge'] === 'rose') text-rose-800
                                        @else text-slate-700 @endif">
                                        {{ $axe['texte'] }}
                                    </p>
                                    @if(!empty($axe['detail']))
                                        <p class="mt-0.5 text-[10px] text-slate-500 truncate" title="{{ $axe['detail'] }}">{{ $axe['detail'] }}</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        @if(!empty($ficheEligibilite['blocking_reasons']))
                            <div class="mt-3 rounded-xl border border-rose-200 bg-rose-50/80 p-3 text-xs text-rose-800" role="alert">
                                <p class="font-bold flex items-center gap-1.5 mb-1 text-rose-900">
                                    <svg class="h-4 w-4 shrink-0 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                    Affectation bloquée ({{ count($ficheEligibilite['blocking_reasons']) }} anomalie(s)) :
                                </p>
                                <ul class="list-disc list-inside space-y-0.5 text-[11px] text-rose-700 font-medium">
                                    @foreach($ficheEligibilite['blocking_reasons'] as $motif)
                                        <li>{{ $motif }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </div>
                @endif

                @if($doublons->isNotEmpty())
                    <div class="mt-4 rounded-2xl border border-rose-200 bg-rose-50 p-4" role="alert">
                        <p class="text-xs font-black uppercase tracking-wider text-rose-700">Doublons potentiels — comparaison humaine requise, jamais de fusion automatique</p>
                        <ul class="mt-2 space-y-1 text-sm">
                            @foreach($doublons as $doublon)
                                <li><a href="{{ route('admin.etudiants.show', $doublon) }}" class="font-bold text-primary-800 underline underline-offset-2">{{ $doublon->nom_complet }}</a> <span class="text-slate-500">· {{ $doublon->cin }} · {{ $doublon->email }}</span></li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if($soldeFinance !== null)
                    <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-4">
                        <p class="text-xs font-black uppercase tracking-wider text-slate-500">Situation financière</p>
                        <p class="mt-1 text-sm tabular-nums">Total : {{ \App\Modules\Finances\Models\Facture::formater($soldeFinance['total']) }} · Payé : {{ \App\Modules\Finances\Models\Facture::formater($soldeFinance['paye']) }} · <strong>Reste : {{ \App\Modules\Finances\Models\Facture::formater($soldeFinance['reste']) }}</strong></p>
                    </div>
                @endif

                <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-4">
                    <p class="text-xs font-black uppercase tracking-wider text-slate-500">Timeline</p>
                    <x-timeline :elements="$timeline" />
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    @if($etudiant->groupe_id)
                        <a href="{{ route('admin.emplois-du-temps.explorer', ['groupe_id' => $etudiant->groupe_id]) }}" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-bold text-slate-700">Voir le planning du groupe</a>
                    @endif
                    <a href="{{ route('admin.etudiants.show', $etudiant) }}#parcours" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-bold text-slate-700">Gérer le parcours</a>
                </div>
            </x-slot:panneau_synthese>
            <x-slot:panneau_infos>
        {{-- ── Grille Desktop Détails (3 Colonnes Équilibrées) ── --}}
        <div class="grid grid-cols-1 md:grid-cols-3 divide-y md:divide-y-0 md:divide-x divide-slate-200/80 bg-white">
            {{-- Colonne 1 : Identité & Contact --}}
            <div class="p-6 sm:p-8">
                <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-5 flex items-center gap-2">
                    <span class="flex h-5 w-5 items-center justify-center rounded-md bg-primary-100 text-primary-700 text-[10px]">1</span>
                    État Civil & Contact
                </h3>

                <dl class="space-y-4 text-xs sm:text-sm">
                    <div>
                        <dt class="text-xs text-slate-400">Nom & Prénom</dt>
                        <dd class="font-bold text-slate-900 mt-0.5">{{ $etudiant->nom_complet }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-400">Numéro de CIN</dt>
                        <dd class="font-mono font-bold text-slate-900 mt-0.5">{{ $etudiant->cin }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-400">Sexe</dt>
                        <dd class="font-medium text-slate-900 mt-0.5">{{ $etudiant->sexe === 'M' ? 'Masculin' : 'Féminin' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-400">Date de Naissance</dt>
                        <dd class="font-medium text-slate-900 mt-0.5">
                            {{ $etudiant->date_naissance ? $etudiant->date_naissance->format('d/m/Y') : 'Non renseignée' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-400">Adresse Email</dt>
                        <dd class="font-medium text-primary-700 mt-0.5 break-all">
                            <a href="mailto:{{ $etudiant->email }}" class="hover:underline">{{ $etudiant->email }}</a>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-400">Téléphone</dt>
                        <dd class="font-medium text-slate-900 mt-0.5">
                            @if($etudiant->telephone)
                                <a href="tel:{{ $etudiant->telephone }}" class="hover:text-primary-700">{{ $etudiant->telephone }}</a>
                            @else
                                <span class="text-slate-400">Non renseigné</span>
                            @endif
                        </dd>
                    </div>
                </dl>
            </div>

            {{-- Colonne 2 : Résidence & Baccalauréat --}}
            <div class="p-6 sm:p-8">
                <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-5 flex items-center gap-2">
                    <span class="flex h-5 w-5 items-center justify-center rounded-md bg-primary-100 text-primary-700 text-[10px]">2</span>
                    Résidence & Bac
                </h3>

                <dl class="space-y-4 text-xs sm:text-sm">
                    <div>
                        <dt class="text-xs text-slate-400">Ville de Résidence</dt>
                        <dd class="font-bold text-slate-900 mt-0.5">{{ $etudiant->ville }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-400">Code Postal</dt>
                        <dd class="font-mono text-slate-900 mt-0.5">{{ $etudiant->code_postal }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-400">Adresse Postale</dt>
                        <dd class="text-slate-700 mt-0.5 leading-relaxed">{{ $etudiant->adresse }}</dd>
                    </div>
                    <div class="pt-3 border-t border-slate-100">
                        <dt class="text-xs text-slate-400">Session du Baccalauréat</dt>
                        <dd class="font-black text-slate-900 mt-0.5 text-base">Session {{ $etudiant->annee_bac }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-400">Mention Obtenue</dt>
                        <dd class="mt-0.5">
                            <span class="inline-block rounded-lg bg-primary-50 px-2.5 py-1 text-xs font-bold text-primary-700 border border-primary-200">
                                {{ $etudiant->mention_bac ?: 'Très Bien' }}
                            </span>
                        </dd>
                    </div>
                </dl>
            </div>

            {{-- Colonne 3 : Inscription Universitaire --}}
            <div class="p-6 sm:p-8">
                <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-5 flex items-center gap-2">
                    <span class="flex h-5 w-5 items-center justify-center rounded-md bg-primary-100 text-primary-700 text-[10px]">3</span>
                    Affectation Universitaire
                </h3>

                <dl class="space-y-4 text-xs sm:text-sm">
                    <div>
                        <dt class="text-xs text-slate-400">Faculté d'Accueil</dt>
                        <dd class="font-bold text-slate-900 mt-0.5 leading-snug">
                            {{ $etudiant->faculte->nom }}
                            <span class="block text-xs font-mono font-semibold text-primary-700 mt-0.5">Code : {{ $etudiant->faculte->code }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-400">Niveau d'Études</dt>
                        <dd class="mt-0.5">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-black bg-primary-100 text-primary-800">
                                {{ $etudiant->niveau }}
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-400">Année Académique</dt>
                        <dd class="font-semibold text-slate-900 mt-0.5">{{ $etudiant->annee_academique }}</dd>
                    </div>
                    <div class="pt-3 border-t border-slate-100">
                        <dt class="text-xs text-slate-400">Date de Dépôt du Dossier</dt>
                        <dd class="font-medium text-slate-700 mt-0.5">{{ $etudiant->created_at->format('d/m/Y à H:i') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-400">Dernière Modification</dt>
                        <dd class="font-medium text-slate-700 mt-0.5">{{ $etudiant->updated_at->format('d/m/Y à H:i') }}</dd>
                    </div>
                    @if($etudiant->groupe_id)
                        <div>
                            <dt class="text-xs text-slate-400">Groupe</dt>
                            <dd class="font-mono font-bold text-slate-900 mt-0.5">{{ $etudiant->groupe->code }} — {{ $etudiant->groupe->nom }}</dd>
                        </div>
                    @endif
                </dl>
            </div>
        </div>
            </x-slot:panneau_infos>
            <x-slot:panneau_parcours>
    {{-- ── Situation LMD + affectations + historique (Phase 2) ── --}}
    <div class="ds-card p-5">
        <h3 class="text-sm font-black uppercase tracking-wider text-slate-500">Situation actuelle</h3>
        <dl class="mt-2 grid grid-cols-1 sm:grid-cols-3 gap-2 text-sm">
            <div><dt class="text-[11px] text-slate-400">Programme</dt><dd class="font-bold">{{ $etudiant->programmeRef?->nom ?? '—' }}</dd></div>
            <div><dt class="text-[11px] text-slate-400">Niveau</dt><dd class="font-bold">{{ $etudiant->niveauRef?->code ?? $etudiant->niveau }}</dd></div>
            <div><dt class="text-[11px] text-slate-400">Groupe</dt><dd class="font-bold">{{ $etudiant->groupe?->code ?? '—' }}</dd></div>
        </dl>
        @if($etudiant->estSuspendu())
            <p class="mt-2 text-xs font-black text-rose-700">⛔ Suspension active — opérations académiques bloquées.</p>
        @endif
    </div>

    {{-- ── Progression LMD : décision officielle → niveau cible ── --}}
    @if($derniereInscription !== null && $progression !== null)
        <div class="ds-card p-5 mt-3">
            <h3 class="text-sm font-black uppercase tracking-wider text-slate-500">Progression LMD</h3>
            <dl class="mt-2 grid grid-cols-1 sm:grid-cols-3 gap-2 text-sm">
                <div><dt class="text-[11px] text-slate-400">Niveau actuel</dt><dd class="font-bold">{{ $derniereInscription->niveauRef?->code ?? $derniereInscription->niveau }} ({{ $derniereInscription->anneeUniversitaire?->libelle }})</dd></div>
                <div><dt class="text-[11px] text-slate-400">Décision jury</dt><dd class="font-bold">{{ $progression['decision'] ?? '—' }}</dd></div>
                <div><dt class="text-[11px] text-slate-400">Niveau cible</dt><dd class="font-bold">{{ $progression['niveau_cible']?->code ?? '—' }}{{ $progression['annee_cible'] ? ' · ' . $progression['annee_cible']->libelle : '' }}</dd></div>
            </dl>
            @if($progression['existante'] !== null)
                <p class="mt-2 text-xs text-slate-500">Inscription {{ $progression['annee_cible']?->libelle }} déjà créée (idempotence).</p>
            @elseif($progression['peut_progresser'])
                @can('archives.create')
                    <form method="POST" action="{{ route('admin.inscriptions.progresser', $derniereInscription) }}" class="mt-2 flex flex-wrap items-end gap-2">
                        @csrf
                        <input type="hidden" name="confirmer" value="1">
                        <p class="w-full text-xs text-slate-500">Action : {{ $progression['action'] }} — la décision publiée et le référentiel déterminent la cible. Cibles explicites = admission externe validée (ex. Master), jamais devinées.</p>
                        <select name="programme_id" aria-label="Programme cible (optionnel)" class="rounded-xl border border-slate-300 px-3 py-2 text-sm min-h-[44px]">
                            <option value="">Programme calculé</option>
                            @foreach($programmesOptions as $programme)
                                <option value="{{ $programme->id }}">{{ $programme->code }} — {{ $programme->nom }}</option>
                            @endforeach
                        </select>
                        <select name="niveau_id" aria-label="Niveau cible (optionnel)" class="rounded-xl border border-slate-300 px-3 py-2 text-sm min-h-[44px]">
                            <option value="">Niveau calculé</option>
                            @foreach($niveauxOptions as $niveau)
                                <option value="{{ $niveau->id }}">{{ $niveau->code }}</option>
                            @endforeach
                        </select>
                        <x-btn type="submit" variant="secondary">Créer l'inscription {{ $progression['annee_cible']?->libelle }}</x-btn>
                    </form>
                @endcan
            @else
                <p class="mt-2 text-xs font-bold text-amber-700">{{ implode(' ', $progression['blocages']) }}</p>
            @endif
            @if($errors->has('progression'))
                <p class="mt-2 text-xs font-bold text-rose-700">{{ $errors->first('progression') }}</p>
            @endif
        </div>
    @endif

    @can('archives.update')
        <div class="mt-3 grid grid-cols-1 lg:grid-cols-2 gap-3">
            <form method="POST" action="{{ route('admin.etudiants.affectations.store', $etudiant) }}" class="ds-card p-4 space-y-2">
                @csrf
                <h3 class="text-xs font-black uppercase tracking-wider text-slate-500">Affecter / changer de groupe</h3>
                <select name="groupe_id" required aria-label="Groupe cible" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm min-h-[44px]">
                    @foreach($groupesOptions as $groupe)
                        <option value="{{ $groupe->id }}">{{ $groupe->code }}{{ $groupe->capacite ? ' (' . $groupe->effectif_actuel . '/' . $groupe->capacite . ')' : '' }}</option>
                    @endforeach
                </select>
                <select name="annee_universitaire_id" required aria-label="Année" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm min-h-[44px]">
                    @foreach($anneesOptions as $annee)
                        <option value="{{ $annee->id }}">{{ $annee->libelle }}</option>
                    @endforeach
                </select>
                <input name="motif" required maxlength="255" placeholder="Motif (obligatoire)" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm min-h-[44px]">
                <div class="flex flex-wrap gap-2">
                    <x-btn type="submit" variant="secondary">Affecter (historisé)</x-btn>
                    <button type="submit" formmethod="GET" formaction="{{ route('admin.etudiants.affectations.previsualiser', $etudiant) }}" formnovalidate
                        class="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2 text-xs font-bold text-slate-700 min-h-[44px]">
                        Prévisualiser (contraintes + impacts)
                    </button>
                </div>
            </form>
            <div class="space-y-3">
                <form method="POST" action="{{ route('admin.etudiants.suspendre', $etudiant) }}" class="ds-card p-4 space-y-2">
                    @csrf
                    <h3 class="text-xs font-black uppercase tracking-wider text-slate-500">Suspendre</h3>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="date" name="date_debut" required aria-label="Début" class="rounded-xl border border-slate-300 px-3 py-2 text-sm min-h-[44px]">
                        <input type="date" name="date_fin" aria-label="Fin prévue" class="rounded-xl border border-slate-300 px-3 py-2 text-sm min-h-[44px]">
                    </div>
                    <input name="motif" required maxlength="255" placeholder="Motif (obligatoire)" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm min-h-[44px]">
                    <x-btn type="submit" variant="danger">Suspendre</x-btn>
                </form>
                <form method="POST" action="{{ route('admin.etudiants.transferer', $etudiant) }}" class="ds-card p-4 space-y-2">
                    @csrf
                    <h3 class="text-xs font-black uppercase tracking-wider text-slate-500">Transférer (programme)</h3>
                    <select name="programme_id" required aria-label="Programme cible" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm min-h-[44px]">
                        @foreach($programmesOptions as $programme)
                            <option value="{{ $programme->id }}">{{ $programme->code }} — {{ $programme->nom }}</option>
                        @endforeach
                    </select>
                    <input name="motif" required maxlength="255" placeholder="Motif (obligatoire)" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm min-h-[44px]">
                    <x-btn type="submit" variant="secondary">Transférer (dossier conservé)</x-btn>
                </form>
                <form method="PATCH" action="{{ route('admin.etudiants.rattachement', $etudiant) }}" class="ds-card p-4 space-y-2">
                    @csrf @method('PATCH')
                    <h3 class="text-xs font-black uppercase tracking-wider text-slate-500">Rattachement LMD</h3>
                    <select name="programme_id" aria-label="Programme" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm min-h-[44px]">
                        <option value="">— Programme —</option>
                        @foreach($programmesOptions as $programme)
                            <option value="{{ $programme->id }}" @selected($etudiant->programme_id == $programme->id)>{{ $programme->code }}</option>
                        @endforeach
                    </select>
                    <select name="niveau_id" aria-label="Niveau" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm min-h-[44px]">
                        <option value="">— Niveau —</option>
                        @foreach($niveauxOptions as $niveau)
                            <option value="{{ $niveau->id }}" @selected($etudiant->niveau_id == $niveau->id)>{{ $niveau->programme->code }} · {{ $niveau->code }}</option>
                        @endforeach
                    </select>
                    <x-btn type="submit" variant="secondary">Rattacher</x-btn>
                </form>
            </div>
        </div>
    @endcan

    <div class="mt-3 ds-card p-5">
        <h3 class="text-sm font-black uppercase tracking-wider text-slate-500">Historique ({{ $etudiant->situations->count() }} événements)</h3>
        @if($etudiant->situations->isNotEmpty())
            <ol class="mt-2 space-y-1.5 text-xs">
                @foreach($etudiant->situations as $situation)
                    <li class="rounded-xl border border-slate-100 px-3 py-2">
                        <strong>{{ $situation->type }}</strong>
                        @if($situation->ancienne_valeur || $situation->nouvelle_valeur)
                            <span class="text-slate-600">{{ $situation->ancienne_valeur ?? '—' }} → {{ $situation->nouvelle_valeur ?? '—' }}</span>
                        @endif
                        @if($situation->motif)<span class="block text-slate-500">{{ $situation->motif }}</span>@endif
                        <span class="block text-slate-400 tabular-nums">{{ $situation->date_effet->format('d/m/Y') }}</span>
                    </li>
                @endforeach
            </ol>
        @else
            <p class="mt-2 text-xs text-slate-500">Aucun événement historisé.</p>
        @endif
    </div>

    <div class="mt-3 ds-card p-5">
        <h3 class="text-sm font-black uppercase tracking-wider text-slate-500">Affectations ({{ $etudiant->affectations->count() }})</h3>
        @if($etudiant->affectations->isNotEmpty())
            <ul class="mt-2 space-y-1.5 text-xs">
                @foreach($etudiant->affectations as $affectation)
                    <li class="rounded-xl border border-slate-100 px-3 py-2">
                        <strong>{{ $affectation->groupe->code }}</strong>
                        <span class="text-slate-600">{{ $affectation->anneeUniversitaire->libelle }} · {{ $affectation->statut }}</span>
                        <span class="block text-slate-400 tabular-nums">{{ $affectation->date_debut->format('d/m/Y') }}{{ $affectation->date_fin ? ' → ' . $affectation->date_fin->format('d/m/Y') : '' }}</span>
                        @if($affectation->statut === 'active')
                            @can('archives.update')
                                <form method="POST" action="{{ route('admin.etudiants.affectations.retirer', $etudiant) }}" class="mt-1.5 flex gap-1.5">
                                    @csrf
                                    <input type="hidden" name="affectation_id" value="{{ $affectation->id }}" />
                                    <input name="motif" required maxlength="255" placeholder="Motif du retrait" aria-label="Motif du retrait" class="flex-1 rounded-lg border border-slate-200 px-2 py-1" />
                                    <button type="submit" class="rounded-lg border border-rose-200 px-2 py-1 font-bold text-rose-700">Retirer</button>
                                </form>
                            @endcan
                        @endif
                    </li>
                @endforeach
            </ul>
        @else
            <p class="mt-2 text-xs text-slate-500">Aucune affectation historisée.</p>
        @endif
    </div>
            </x-slot:panneau_parcours>
            <x-slot:panneau_services>
    {{-- ── Modules liés : raccourcis vers dossier, demandes, planning ── --}}
    <div class="no-print grid grid-cols-1 sm:grid-cols-3 gap-3">
        @if(app(\App\Support\Modules\ModuleRegistry::class)->estActif('student-dossiers'))
            <a href="{{ route('admin.student-dossiers.index', ['search' => $etudiant->cin]) }}"
               class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm hover:border-primary-300 hover:shadow-md transition">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Dossier administratif</p>
                <p class="mt-1 text-sm font-black text-primary-800">Ouvrir le dossier →</p>
            </a>
        @endif
        @if(app(\App\Support\Modules\ModuleRegistry::class)->estActif('online-requests'))
            <a href="{{ route('admin.demandes.index', ['search' => $etudiant->cin]) }}"
               class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm hover:border-primary-300 hover:shadow-md transition">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Demandes</p>
                <p class="mt-1 text-sm font-black text-primary-800">Voir les demandes →</p>
            </a>
        @endif
        @if(app(\App\Support\Modules\ModuleRegistry::class)->estActif('schedule') && $etudiant->groupe_id)
            <a href="{{ route('admin.emplois-du-temps.index') }}"
               class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm hover:border-primary-300 hover:shadow-md transition">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Emploi du temps</p>
                <p class="mt-1 text-sm font-black text-primary-800">Groupe {{ $etudiant->groupe->code }} →</p>
            </a>
        @endif
    </div>
            </x-slot:panneau_services>
        </x-tabs>
</div>
@endsection
