@extends('layouts.app')

@section('title', 'Audit des Acquis & Décision — ' . $etudiant->nom . ' ' . $etudiant->prenom)

@section('content')
<div class="mx-auto max-w-7xl px-4 sm:px-6 py-6 sm:py-8 space-y-6" x-data="{ modalCorrectionNote: false, noteSelectionnee: null }">

    <!-- En-tête avec navigation et actions officielles certifiées -->
    <div class="relative overflow-hidden rounded-3xl border border-slate-200/90 bg-white p-6 sm:p-8 shadow-sm">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
            <div>
                <div class="flex flex-wrap items-center gap-2 mb-2">
                    <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold border border-emerald-200 bg-emerald-50 text-emerald-900">
                        <span class="h-2 w-2 rounded-full bg-emerald-500" aria-hidden="true"></span>
                        Instruction Individuelle — Décret 2022-631
                    </span>
                    <span class="font-mono text-xs font-bold text-slate-500">
                        {{ $etudiant->nom }} {{ $etudiant->prenom }}
                        @if($etudiant->cin) • CIN : {{ $etudiant->cin }} @endif
                        @if($etudiant->matricule) • Matr : {{ $etudiant->matricule }} @endif
                    </span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-black tracking-tight text-slate-900">
                    Bilan Académique, Capitalisation &amp; Plan de Redoublement
                </h1>
                <p class="mt-1 text-xs sm:text-sm text-slate-500 max-w-3xl leading-relaxed">
                    Exécution certifiée du Décret n° 2022-631 et de l'Arrêté du 7 juillet 2023. Conservation garantie des crédits validés, compensation semestrielle et réinscription ciblée.
                </p>
            </div>

            <!-- Actions certifiées SHA-256 -->
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.redoublement.document', [$etudiant, 'releve_redoublement']) }}"
                   target="_blank"
                   class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 shadow-sm hover:bg-slate-50 transition min-h-[40px]">
                    <svg class="h-4 w-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                    </svg>
                    <span>Relevé SHA-256</span>
                </a>

                <a href="{{ route('admin.redoublement.document', [$etudiant, 'contrat_pedagogique']) }}"
                   target="_blank"
                   class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 shadow-sm hover:bg-slate-50 transition min-h-[40px]">
                    <svg class="h-4 w-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z" />
                    </svg>
                    <span>Contrat Pédagogique</span>
                </a>

                <a href="{{ route('admin.redoublement.index') }}"
                   class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-slate-100 px-3.5 py-2 text-xs font-bold text-slate-700 shadow-sm hover:bg-slate-200 transition min-h-[40px]">
                    ← Retour liste
                </a>
            </div>
        </div>

        <!-- Alertes de session -->
        @if(session('success'))
            <div class="mt-4 rounded-xl bg-emerald-50 border border-emerald-200 p-4 text-xs font-bold text-emerald-900 flex items-center gap-2">
                <span>✅</span>
                <span>{{ session('success') }}</span>
            </div>
        @endif
        @if(isset($errors) && $errors->any())
            <div class="mt-4 rounded-xl bg-rose-50 border border-rose-200 p-4 text-xs font-bold text-rose-900 space-y-1">
                @foreach($errors->all() as $error)
                    <div>❌ {{ $error }}</div>
                @endforeach
            </div>
        @endif

        <!-- Synthèse globale KPI -->
        <div class="mt-6 grid grid-cols-2 sm:grid-cols-4 gap-4">
            <div class="rounded-2xl border border-slate-100 bg-slate-50/70 p-4">
                <div class="text-[11px] font-bold text-slate-500 uppercase tracking-wider">Régime &amp; Cursus</div>
                <div class="mt-1 text-sm font-bold text-slate-900 truncate">{{ $analyse['regime']['nom'] }}</div>
                <div class="text-[11px] text-slate-500 font-mono mt-0.5">Année : {{ $analyse['regime']['annee'] ?? '—' }} ({{ $analyse['regime']['session'] ?? '—' }})</div>
            </div>

            <div class="rounded-2xl border border-slate-100 bg-slate-50/70 p-4">
                <div class="text-[11px] font-bold text-slate-500 uppercase tracking-wider">Statut Délibération</div>
                <div class="mt-1">
                    @if($analyse['regime']['deliberation_publiee'])
                        <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-bold bg-green-100 text-green-800 border border-green-200">
                            Officiellement Délibéré
                        </span>
                    @else
                        <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-bold bg-amber-100 text-amber-800 border border-amber-200">
                            En Attente Délibération
                        </span>
                    @endif
                </div>
                <div class="text-[11px] text-slate-500 mt-1 font-mono">Source jury : PV certifié</div>
            </div>

            <div class="rounded-2xl border border-slate-100 bg-slate-50/70 p-4">
                <div class="text-[11px] font-bold text-slate-500 uppercase tracking-wider">Crédits Capitalisés</div>
                <div class="mt-1 text-2xl font-black text-emerald-600">{{ $analyse['totaux']['credits_acquis'] }} ECTS</div>
                <div class="text-[11px] text-slate-500">Conservés définitivement</div>
            </div>

            <div class="rounded-2xl border border-slate-100 bg-slate-50/70 p-4">
                <div class="text-[11px] font-bold text-slate-500 uppercase tracking-wider">Crédits Restant à Valider</div>
                <div class="mt-1 text-2xl font-black {{ $analyse['totaux']['credits_a_obtenir'] > 0 ? 'text-amber-600' : 'text-slate-700' }}">
                    {{ $analyse['totaux']['credits_a_obtenir'] }} ECTS
                </div>
                <div class="text-[11px] text-slate-500">{{ count($analyse['ecues_a_repasser']) }} matière(s) à repasser</div>
            </div>
        </div>
    </div>

    <!-- Section Cas Spécifiques : Alertes & Contrôles Particuliers -->
    <div class="space-y-4">

        <!-- CAS A : Validation par compensation (Art. 9 & 10) -->
        @if((data_get($analyse, 'regime.regles.compensation') || ($analyse['totaux']['credits_acquis'] == 60)) && ($analyse['totaux']['credits_acquis'] == 60 || ($analyse['totaux']['moyenne_generale'] ?? 0) >= 10))
            <div class="rounded-3xl border border-teal-200 bg-teal-50/60 p-5 shadow-sm flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-teal-600 text-white font-bold text-lg">
                        ⚖️
                    </span>
                    <div>
                        <div class="text-xs font-bold uppercase tracking-wider text-teal-800">Cas Réglementaire : Compensation Annuelle (Art. 9 &amp; 10)</div>
                        <div class="text-sm font-bold text-slate-900">L'étudiant remplit les conditions de compensation semestrielle et annuelle.</div>
                        @php
                            $seuilEliminatoireAffiche = data_get($analyse, 'regime.seuil_competence_defaut') ?? data_get($analyse, 'regime.seuil_note_eliminatoire');
                        @endphp
                        <div class="text-xs text-slate-600 mt-0.5">Moyenne générale &ge; 10.00/20 et absence de note éliminatoire {{ $seuilEliminatoireAffiche !== null ? '(< ' . number_format($seuilEliminatoireAffiche, 2) . '/20, Art. 13)' : '(régime sans note éliminatoire)' }}. Les crédits de l'ensemble des semestres sont octroyés.</div>
                    </div>
                </div>
                <span class="inline-flex items-center px-3 py-1.5 rounded-xl text-xs font-bold bg-teal-600 text-white shadow-xs">
                    Statut ADMIS_COMPENSATION
                </span>
            </div>
        @endif

        <!-- CAS B : Blocage par Prérequis académique obligatoire -->
        @if($planActif && $planActif->statut === 'bloque')
            <div class="rounded-3xl border border-rose-300 bg-rose-50/70 p-5 shadow-sm space-y-3">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-rose-600 text-white font-bold text-lg">
                        ⛔
                    </span>
                    <div>
                        <div class="text-xs font-bold uppercase tracking-wider text-rose-800">Cas Critique : Blocage de Prérequis Non Satisfait</div>
                        <div class="text-sm font-bold text-rose-950">Inscription à l'ECUE supérieur bloquée par la réglementation.</div>
                        <div class="text-xs text-rose-800 mt-0.5">
                            L'étudiant n'a pas validé la matière prérequise obligatoire. L'accès aux unités de niveau supérieur reste verrouillé jusqu'à validation de la matière amont.
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <!-- CAS C : Contrôle des 4 Yeux (Double Validation obligatoire - Art. 13 & 22) -->
        @if($planActif && $planActif->statut === 'en_validation')
            <div class="rounded-3xl border border-indigo-200 bg-indigo-50/70 p-6 shadow-sm space-y-4">
                <div class="flex items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-indigo-600 text-white font-bold text-lg">
                            👁️
                        </span>
                        <div>
                            <div class="text-xs font-bold uppercase tracking-wider text-indigo-800">Cas Réglementaire : Contrôle des 4 Yeux Requis (Art. 13 &amp; 22)</div>
                            <div class="text-sm font-bold text-slate-900">Ce plan comporte des dérogations ou dispenses sensibles et requiert le visa d'un second agent.</div>
                        </div>
                    </div>
                    <span class="px-3 py-1 rounded-xl text-xs font-bold bg-indigo-200 text-indigo-900">
                        En attente de visa n°2
                    </span>
                </div>

                <form method="POST" action="{{ route('admin.redoublement.valider-double', $etudiant) }}" class="flex flex-col sm:flex-row items-end gap-3 pt-2">
                    @csrf
                    <div class="flex-1 w-full">
                        <label for="motif_4yeux" class="block text-xs font-bold text-indigo-900 mb-1">Motif du visa de conformité réglementaire</label>
                        <input type="text"
                               name="motif"
                               id="motif_4yeux"
                               required
                               placeholder="Ex : Vérification des pièces justificatives et avis favorable de la commission..."
                               class="w-full rounded-xl border border-indigo-300 bg-white px-3.5 py-2 text-xs text-slate-900 focus:border-indigo-600 focus:outline-none">
                    </div>
                    <button type="submit"
                            class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-5 py-2 text-xs font-bold text-white shadow-sm hover:bg-indigo-700 transition min-h-[38px] w-full sm:w-auto">
                        Apposer le visa 4 yeux &amp; Activer le plan
                    </button>
                </form>
            </div>
        @endif

        <!-- CAS D : Équivalences & Dispenses Externes -->
        @if($equivalences->isNotEmpty())
            <div class="rounded-3xl border border-teal-200 bg-white p-6 shadow-sm space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm sm:text-base font-bold text-slate-900 flex items-center gap-2">
                        <span class="text-teal-600">🏛️</span>
                        Cas d'Équivalences &amp; Acquis Externes (Mobilité / Transfert)
                    </h2>
                    <span class="text-xs font-mono font-semibold px-2.5 py-1 rounded-lg bg-teal-50 text-teal-800">
                        {{ $equivalences->count() }} demande(s) enregistrée(s)
                    </span>
                </div>

                <div class="divide-y divide-slate-100 border border-slate-200 rounded-2xl overflow-hidden">
                    @foreach($equivalences as $equiv)
                        <div class="p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-white">
                            <div>
                                <div class="font-bold text-xs text-slate-900">
                                    {{ $equiv->origine_libelle }} ({{ $equiv->origine_credits }} ECTS)
                                    <span class="text-slate-400 font-normal">→ Cible :</span>
                                    {{ $equiv->cibleMatiere?->nom ?? $equiv->cible_libelle }}
                                </div>
                                <div class="text-[11px] text-slate-500 font-mono mt-0.5">
                                    Établissement : {{ $equiv->etablissement ?? 'Non précisé' }} • Statut : <span class="font-bold uppercase">{{ $equiv->statut }}</span>
                                </div>
                            </div>

                            @if($equiv->statut === 'en_attente')
                                <form method="POST" action="{{ route('admin.redoublement.equivalence', $etudiant) }}" class="flex items-center gap-2">
                                    @csrf
                                    <input type="hidden" name="equivalence_id" value="{{ $equiv->id }}">
                                    <input type="hidden" name="motif" value="Validation par commission pédagogique">

                                    <button type="submit" name="decision" value="VALIDEE"
                                            class="px-3 py-1.5 rounded-lg bg-emerald-600 text-white text-xs font-bold hover:bg-emerald-700 transition">
                                        Valider
                                    </button>
                                    <button type="submit" name="decision" value="REJETEE"
                                            class="px-3 py-1.5 rounded-lg bg-rose-600 text-white text-xs font-bold hover:bg-rose-700 transition">
                                        Refuser
                                    </button>
                                </form>
                            @else
                                <span class="text-xs font-bold px-2.5 py-1 rounded-lg {{ $equiv->decision === 'validee' ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800' }}">
                                    {{ strtoupper($equiv->decision ?? $equiv->statut) }}
                                </span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

    </div>

    <!-- Actions Administratives Principales (Étape 1 & Étape 2) + Rectification de note -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

        <!-- Action 1 : Confirmation du plan de redoublement -->
        <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
            <h2 class="text-sm sm:text-base font-bold text-slate-900 flex items-center gap-2">
                <span class="w-2.5 h-2.5 rounded-full bg-blue-500"></span>
                Étape 1 : Valider le Plan de Redoublement
            </h2>
            <p class="text-xs text-slate-600 leading-relaxed">
                Génère et consigne le contrat pédagogique personnalisé dans l'historique académique officiel, garantissant que l'étudiant ne repassera que les éléments non validés.
            </p>

            <form method="POST" action="{{ route('admin.redoublement.confirmer-plan', $etudiant) }}" class="space-y-3">
                @csrf
                <div>
                    <label for="nouvelle_annee_id" class="block text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-1">
                        Année académique du redoublement
                    </label>
                    <select name="nouvelle_annee_id" id="nouvelle_annee_id" required
                            class="w-full rounded-xl border border-slate-300 px-3.5 py-2 text-xs text-slate-900 focus:border-blue-500 focus:outline-none">
                        @foreach($annees as $annee)
                            <option value="{{ $annee->id }}">{{ $annee->libelle ?? $annee->code }}</option>
                        @endforeach
                    </select>
                </div>

                <button type="submit"
                        class="w-full inline-flex items-center justify-center rounded-xl bg-blue-600 px-4 py-2.5 text-xs font-bold text-white shadow-sm hover:bg-blue-700 transition">
                    Valider officiellement le plan
                </button>
            </form>
        </div>

        <!-- Action 2 : Réinscription Pédagogique Ciblée -->
        <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
            <h2 class="text-sm sm:text-base font-bold text-slate-900 flex items-center gap-2">
                <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                Étape 2 : Réinscription Sélective
            </h2>
            <p class="text-xs text-slate-600 leading-relaxed">
                Inscrit l'étudiant exclusivement aux épreuves des éléments restants, tout en marquant automatiquement les éléments capitalisés comme validés sans réinscription aux examens.
            </p>

            <form method="POST" action="{{ route('admin.redoublement.reinscrire', $etudiant) }}" class="space-y-3">
                @csrf
                <div>
                    <label for="inscription_academique_id" class="block text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-1">
                        Dossier d'inscription cible
                    </label>
                    <select name="inscription_academique_id" id="inscription_academique_id" required
                            class="w-full rounded-xl border border-slate-300 px-3.5 py-2 text-xs text-slate-900 focus:border-emerald-500 focus:outline-none">
                        @foreach($etudiant->inscriptionsAcademiques as $insc)
                            <option value="{{ $insc->id }}">
                                {{ $insc->anneeUniversitaire?->libelle ?? 'Année' }} — {{ $insc->programmeRef?->nom ?? $insc->filiere ?? 'Filière' }} (Niveau {{ $insc->niveau }}) [ID: {{ $insc->id }}]
                            </option>
                        @endforeach
                    </select>
                </div>

                <button type="submit"
                        class="w-full inline-flex items-center justify-center rounded-xl bg-emerald-600 px-4 py-2.5 text-xs font-bold text-white shadow-sm hover:bg-emerald-700 transition">
                    Exécuter la réinscription ciblée
                </button>
            </form>
        </div>

        <!-- Action 3 : Rectification Auditée de Note (Erreur Matérielle PV - Art. 24) -->
        <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
            <h2 class="text-sm sm:text-base font-bold text-slate-900 flex items-center gap-2">
                <span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span>
                Rectification Auditée de Note (PV)
            </h2>
            <p class="text-xs text-slate-600 leading-relaxed">
                Correction d'erreur matérielle sur le PV officiel de jury. Met à jour la note avec audit complet et recalcule automatiquement les acquis et compensations.
            </p>

            <form method="POST" action="{{ route('admin.redoublement.corriger-note', $etudiant) }}" class="space-y-2.5">
                @csrf
                <div>
                    <label for="note_id" class="block text-[10px] font-bold uppercase tracking-wider text-slate-600 mb-0.5">Évaluation / Note</label>
                    <select name="note_id" id="note_id" required class="w-full rounded-xl border border-slate-300 px-3 py-1.5 text-xs">
                        @foreach($notes as $note)
                            <option value="{{ $note->id }}">
                                {{ $note->matiere?->nom ?? 'Matière' }} (S{{ $note->semestre?->numero ?? '?' }}) : {{ $note->valeur !== null ? number_format($note->valeur, 2) : '—' }}/20
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label for="nouvelle_note" class="block text-[10px] font-bold uppercase tracking-wider text-slate-600 mb-0.5">Note rectifiée (/20)</label>
                        <input type="number" step="0.01" min="0" max="20" name="nouvelle_note" id="nouvelle_note" required placeholder="Ex : 11.50"
                               class="w-full rounded-xl border border-slate-300 px-3 py-1.5 text-xs">
                    </div>
                    <div>
                        <label for="pv_reference" class="block text-[10px] font-bold uppercase tracking-wider text-slate-600 mb-0.5">Réf. PV Jury</label>
                        <input type="text" name="pv_reference" id="pv_reference" required placeholder="PV-2025-01"
                               class="w-full rounded-xl border border-slate-300 px-3 py-1.5 text-xs">
                    </div>
                </div>

                <div>
                    <label for="motif_rectif" class="block text-[10px] font-bold uppercase tracking-wider text-slate-600 mb-0.5">Motif réglementaire</label>
                    <input type="text" name="motif" id="motif_rectif" required placeholder="Erreur matérielle de transcription sur bordereau"
                           class="w-full rounded-xl border border-slate-300 px-3 py-1.5 text-xs">
                </div>

                <button type="submit"
                        class="w-full inline-flex items-center justify-center rounded-xl bg-amber-600 px-4 py-2 text-xs font-bold text-white shadow-sm hover:bg-amber-700 transition">
                    Rectifier la note &amp; Recalculer
                </button>
            </form>
        </div>

    </div>

    <!-- Simulation Prévisionnelle du Plan de Redoublement -->
    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-base font-bold text-slate-900">
                    Simulation Prévisionnelle du Plan de Redoublement (Dry-run)
                </h2>
                <p class="text-xs text-slate-500">Matières et ECUEs non capitalisés restant obligatoirement à repasser</p>
            </div>
            <span class="text-xs font-mono font-bold px-3 py-1 rounded-xl bg-slate-100 text-slate-800 border border-slate-200">
                Total restant : {{ $simulation['nb_elements_a_reprendre'] }} matière(s)
            </span>
        </div>

        @if(!empty($simulation['elements_a_reprendre']))
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                @foreach($simulation['elements_a_reprendre'] as $elem)
                    <div class="rounded-2xl border border-amber-200 bg-amber-50/40 p-4 flex flex-col justify-between">
                        <div>
                            <div class="flex items-center justify-between">
                                <span class="font-mono text-xs font-bold text-amber-800">{{ $elem['code'] }}</span>
                                <span class="text-xs font-bold font-mono text-slate-600">{{ $elem['credits'] }} ECTS</span>
                            </div>
                            <div class="text-xs font-bold text-slate-900 mt-1">{{ $elem['nom'] }}</div>
                            <div class="text-[11px] text-slate-500 mt-0.5">{{ $elem['ue_nom'] }} (Semestre {{ $elem['semestre'] }})</div>
                        </div>
                        <div class="mt-3 pt-2 border-t border-amber-200/60 text-[10px] text-amber-800 font-medium">
                            Motif : {{ $elem['motif'] }}
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-xs font-bold text-emerald-800 flex items-center gap-2">
                <span>🎉</span>
                <span>Aucun élément à repasser. L'ensemble des crédits requis a été validé ou compensé de plein droit.</span>
            </div>
        @endif
    </div>

    <!-- Tableau détaillé analytique par Unité d'Enseignement (UE) et ECUE -->
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <h2 class="text-base font-bold text-slate-900">
                Détail Analytique par Unité d'Enseignement (UE) et Élément Constitutif (ECUE)
            </h2>
            <span class="text-xs text-slate-500 font-mono">Décret n° 2022-631</span>
        </div>

        @foreach($analyse['ues'] as $ue)
            <div class="rounded-2xl border {{ $ue['est_validee'] ? 'border-emerald-200 bg-white' : 'border-slate-200 bg-white' }} shadow-sm overflow-hidden">
                <div class="px-5 py-3.5 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 {{ $ue['est_validee'] ? 'bg-emerald-50/40' : 'bg-slate-50/60' }}">
                    <div>
                        <span class="font-mono text-xs font-bold text-slate-700 mr-2">{{ $ue['code'] }}</span>
                        <span class="font-bold text-slate-900 text-sm">{{ $ue['nom'] }}</span>
                        <span class="text-xs text-slate-500 ml-2">({{ $ue['credits'] }} ECTS • Semestre {{ $ue['semestre'] }})</span>
                    </div>

                    <div>
                        @if($ue['est_capitalisee'])
                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-bold bg-emerald-100 text-emerald-800 border border-emerald-200">
                                Capitalisée à vie (Moyenne : {{ $ue['moyenne'] !== null ? number_format($ue['moyenne'], 2) : '—' }}/20)
                            </span>
                        @elseif($ue['est_compensee'])
                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-bold bg-blue-100 text-blue-800 border border-blue-200">
                                Validée par compensation
                            </span>
                        @else
                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-bold bg-rose-100 text-rose-800 border border-rose-200">
                                Non validée (Moyenne : {{ $ue['moyenne'] !== null ? number_format($ue['moyenne'], 2) : '—' }}/20)
                            </span>
                        @endif
                    </div>
                </div>

                @if(!empty($ue['ecues']))
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-50/80 text-slate-600 border-b border-slate-100 uppercase tracking-wider font-semibold">
                            <tr>
                                <th class="px-5 py-2.5">Code</th>
                                <th class="px-5 py-2.5">Matière / ECUE</th>
                                <th class="px-5 py-2.5 text-center">Crédits propres</th>
                                <th class="px-5 py-2.5 text-center">Note officielle /20</th>
                                <th class="px-5 py-2.5">Statut réglementaire</th>
                                <th class="px-5 py-2.5">Justification juridique</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($ue['ecues'] as $ecue)
                                <tr class="hover:bg-slate-50/70 {{ $ecue['est_a_repasser'] ? 'bg-amber-50/20' : '' }}">
                                    <td class="px-5 py-3 font-mono font-medium text-slate-600">{{ $ecue['code'] }}</td>
                                    <td class="px-5 py-3 font-medium text-slate-900">{{ $ecue['nom'] }}</td>
                                    <td class="px-5 py-3 text-center font-mono font-semibold">{{ $ecue['credits'] > 0 ? $ecue['credits'] . ' ECTS' : '0' }}</td>
                                    <td class="px-5 py-3 text-center font-mono font-bold">
                                        {{ $ecue['note'] !== null ? number_format($ecue['note'], 2) : '—' }}
                                    </td>
                                    <td class="px-5 py-3">
                                        @if($ecue['est_capitalise'])
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold bg-emerald-100 text-emerald-800">
                                                Capitalisé
                                            </span>
                                        @elseif($ecue['est_dispense'])
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold bg-blue-100 text-blue-800">
                                                Dispensé
                                            </span>
                                        @elseif($ecue['est_equivalent'])
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold bg-teal-100 text-teal-800">
                                                Équivalent
                                            </span>
                                        @elseif($ecue['est_a_repasser'])
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold bg-amber-100 text-amber-800">
                                                À repasser
                                            </span>
                                        @else
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-slate-100 text-slate-700">
                                                {{ $ecue['statut_libelle'] }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-slate-500 text-[11px]">{{ $ecue['motif'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        @endforeach
    </div>

</div>
@endsection
