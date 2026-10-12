@extends('layouts.app')

@section('title', $titre)

@php
    $labelsColonnes = [
        'numero' => 'N°',
        'matricule' => 'Matricule',
        'cin' => 'CIN',
        'nom' => 'Nom',
        'prenom' => 'Prénom',
        'email' => 'Email',
        'telephone' => 'Téléphone',
        'groupe' => 'Groupe',
        'sous_groupe' => 'Sous-groupe',
        'programme' => 'Programme',
        'filiere' => 'Filière',
        'niveau' => 'Niveau',
        'faculte' => 'Faculté',
        'annee' => 'Année',
        'moyenne' => 'Moyenne',
        'credits' => 'Crédits',
        'decision' => 'Décision',
        'statut' => 'Statut',
    ];

    $labelsEntete = [
        'etablissement' => 'Établissement',
        'faculte' => 'Faculté',
        'departement' => 'Département',
        'annee' => 'Année univ.',
        'groupe' => 'Groupe / Classe',
        'programme' => 'Programme',
        'parcours' => 'Parcours',
        'niveau' => 'Niveau',
        'semestre' => 'Semestre',
        'effectif' => 'Effectif total',
        'date' => "Date d'édition",
        'utilisateur' => 'Opérateur',
        'filtres' => 'Filtres appliqués',
        'visa' => 'Cadre signature/visa',
    ];
@endphp

@section('content')
<div x-data="printConfigApp()" class="min-h-screen pb-12">
    {{-- Injection dynamique de l'orientation pour window.print() --}}
    <div x-html="orientation === 'landscape' ? '<style>@media print { @page { size: A4 landscape; margin: 8mm; } }</style>' : '<style>@media print { @page { size: A4 portrait; margin: 10mm; } }</style>'"></div>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- BARRE DE COMMANDE ET CONFIGURATION FLOTTANTE (MASQUÉE À L'IMPRESSION) --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <div class="no-print sticky top-0 z-40 border-b border-slate-200 bg-white/95 backdrop-blur shadow-sm">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 py-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                {{-- Titre et badges de statut --}}
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary-800 text-white shadow-sm">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24-1.076-.672-2.072-1.258-2.955A9.006 9.006 0 0112 3c2.72 0 5.166 1.205 6.837 3.125a12.012 12.012 0 01-1.257 2.954m-10.86 4.75A9.034 9.034 0 0012 21a9.034 9.034 0 008.28-7.171M6 10.5a.75.75 0 01.75-.75h10.5a.75.75 0 010 1.5H6.75a.75.75 0 01-.75-.75z" />
                        </svg>
                    </span>
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-base font-black text-slate-900">Impression & Export PDF</h2>
                            <span class="inline-flex items-center rounded-full bg-primary-50 px-2 py-0.5 text-xs font-bold text-primary-700 tabular-nums">
                                <span x-text="totalLignes"></span> étudiant(s)
                            </span>
                        </div>
                        <p class="text-xs text-slate-500">
                            {{ $contexte }}
                        </p>
                    </div>
                </div>

                {{-- Actions principales --}}
                <div class="flex flex-wrap items-center gap-2">
                    {{-- Bouton bascule panneau options --}}
                    <button type="button"
                            @click="panneauOuvert = !panneauOuvert"
                            :class="panneauOuvert ? 'bg-slate-100 text-slate-800 border-slate-300' : 'bg-white text-slate-700 hover:bg-slate-50 border-slate-200'"
                            class="inline-flex items-center gap-2 rounded-xl border px-3.5 py-2 text-xs font-bold shadow-sm transition">
                        <svg class="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0h9.75" />
                        </svg>
                        <span>Personnaliser l'en-tête & colonnes</span>
                        <span class="rounded-full bg-slate-200 px-1.5 py-0.2 text-[10px] font-black text-slate-700 tabular-nums"
                              x-text="nbChampsEnteteActifs() + ' ch. / ' + colonnes.length + ' col.'"></span>
                        <svg class="h-3.5 w-3.5 text-slate-400 transition-transform" :class="panneauOuvert && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                        </svg>
                    </button>

                    {{-- Bouton Télécharger PDF avec la configuration actuelle --}}
                    <button type="button"
                            @click="telechargerPdf()"
                            class="inline-flex items-center gap-2 rounded-xl border border-emerald-600 bg-emerald-600 px-3.5 py-2 text-xs font-bold text-white shadow-sm hover:bg-emerald-700 transition">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                        </svg>
                        <span>Télécharger PDF</span>
                    </button>

                    {{-- Bouton Imprimer (window.print) --}}
                    <button type="button"
                            onclick="window.print()"
                            class="inline-flex items-center gap-2 rounded-xl bg-primary-800 px-4 py-2 text-xs font-bold text-white shadow-sm hover:bg-primary-900 transition">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24-1.076-.672-2.072-1.258-2.955A9.006 9.006 0 0112 3c2.72 0 5.166 1.205 6.837 3.125a12.012 12.012 0 01-1.257 2.954m-10.86 4.75A9.034 9.034 0 0012 21a9.034 9.034 0 008.28-7.171M6 10.5a.75.75 0 01.75-.75h10.5a.75.75 0 010 1.5H6.75a.75.75 0 01-.75-.75z" />
                        </svg>
                        <span>Imprimer</span>
                    </button>

                    {{-- Bouton Retour --}}
                    <a href="{{ url()->previous() }}"
                       class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-600 hover:bg-slate-50 transition">
                        Retour
                    </a>
                </div>
            </div>

            {{-- ── Panneau de personnalisation escamotable ── --}}
            <div x-show="panneauOuvert"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 -translate-y-2"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100 translate-y-0"
                 x-transition:leave-end="opacity-0 -translate-y-2"
                 class="mt-3 rounded-2xl border border-slate-200 bg-slate-50/80 p-4 shadow-inner">

                {{-- Navigation par onglets dans le panneau --}}
                <div class="flex items-center gap-2 border-b border-slate-200 pb-3 mb-4">
                    <button type="button"
                            @click="ongletActif = 'entete'"
                            :class="ongletActif === 'entete' ? 'bg-white text-primary-800 shadow-sm border-slate-200 font-bold' : 'text-slate-600 hover:text-slate-900 border-transparent'"
                            class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs transition">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25H12" />
                        </svg>
                        <span>1. Cartouche d'en-tête</span>
                        <span class="rounded-full bg-primary-100 px-1.5 py-0.2 text-[10px] font-black text-primary-800 tabular-nums"
                              x-text="nbChampsEnteteActifs()"></span>
                    </button>

                    <button type="button"
                            @click="ongletActif = 'colonnes'"
                            :class="ongletActif === 'colonnes' ? 'bg-white text-primary-800 shadow-sm border-slate-200 font-bold' : 'text-slate-600 hover:text-slate-900 border-transparent'"
                            class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs transition">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M12 17.25h8.25" />
                        </svg>
                        <span>2. Colonnes du tableau</span>
                        <span class="rounded-full bg-primary-100 px-1.5 py-0.2 text-[10px] font-black text-primary-800 tabular-nums"
                              x-text="colonnes.length"></span>
                    </button>

                    <button type="button"
                            @click="ongletActif = 'page'"
                            :class="ongletActif === 'page' ? 'bg-white text-primary-800 shadow-sm border-slate-200 font-bold' : 'text-slate-600 hover:text-slate-900 border-transparent'"
                            class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs transition">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        <span>3. Mise en page & Titre</span>
                    </button>
                </div>

                {{-- CONTENU ONGLET 1 : CHAMPS D'EN-TÊTE --}}
                <div x-show="ongletActif === 'entete'" class="space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="text-xs font-semibold text-slate-700">Sélectionnez les métadonnées institutionnelles à faire figurer dans l'en-tête :</span>
                        <div class="flex items-center gap-1.5">
                            <button type="button" @click="setEntetePreset('tout')" class="rounded bg-slate-200 px-2 py-1 text-[11px] font-bold text-slate-700 hover:bg-slate-300">Tout cocher</button>
                            <button type="button" @click="setEntetePreset('minimal')" class="rounded bg-slate-200 px-2 py-1 text-[11px] font-bold text-slate-700 hover:bg-slate-300">Minimal</button>
                            <button type="button" @click="setEntetePreset('aucun')" class="rounded bg-slate-200 px-2 py-1 text-[11px] font-bold text-slate-700 hover:bg-slate-300">Masquer tout</button>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-7 gap-2">
                        @foreach($labelsEntete as $cle => $libelle)
                            <label class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white p-2 text-xs font-medium text-slate-700 shadow-xs cursor-pointer hover:bg-slate-50 transition">
                                <input type="checkbox"
                                       x-model="champsEntete.{{ $cle }}"
                                       class="h-4 w-4 rounded border-slate-300 text-primary-600 focus:ring-primary-500">
                                <span class="truncate">{{ $libelle }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                {{-- CONTENU ONGLET 2 : COLONNES DU TABLEAU --}}
                <div x-show="ongletActif === 'colonnes'" class="space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="text-xs font-semibold text-slate-700">Présélections rapides de colonnes pour l'impression :</span>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <button type="button" @click="setPreset('standard')" class="rounded bg-primary-100 text-primary-800 px-2.5 py-1 text-[11px] font-bold hover:bg-primary-200">Standard</button>
                            <button type="button" @click="setPreset('emargement')" class="rounded bg-amber-100 text-amber-900 px-2.5 py-1 text-[11px] font-bold hover:bg-amber-200">Feuille d'émargement</button>
                            <button type="button" @click="setPreset('contact')" class="rounded bg-sky-100 text-sky-900 px-2.5 py-1 text-[11px] font-bold hover:bg-sky-200">Contact</button>
                            <button type="button" @click="setPreset('academique')" class="rounded bg-purple-100 text-purple-900 px-2.5 py-1 text-[11px] font-bold hover:bg-purple-200">Académique</button>
                            <button type="button" @click="setPreset('tout')" class="rounded bg-slate-200 text-slate-800 px-2.5 py-1 text-[11px] font-bold hover:bg-slate-300">Toutes les colonnes</button>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-2">
                        @foreach($catalogueColonnes as $colonne)
                            <label class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white p-2 text-xs font-medium text-slate-700 shadow-xs cursor-pointer hover:bg-slate-50 transition">
                                <input type="checkbox"
                                       :checked="isColonneActive('{{ $colonne }}')"
                                       @change="toggleColonne('{{ $colonne }}')"
                                       class="h-4 w-4 rounded border-slate-300 text-primary-600 focus:ring-primary-500">
                                <span class="truncate">{{ $labelsColonnes[$colonne] ?? $colonne }}</span>
                            </label>
                        @endforeach

                        {{-- Option spéciale : Colonne d'émargement --}}
                        <label class="flex items-center gap-2 rounded-lg border border-amber-300 bg-amber-50/80 p-2 text-xs font-bold text-amber-900 shadow-xs cursor-pointer hover:bg-amber-100 transition">
                            <input type="checkbox"
                                   x-model="emargement"
                                   class="h-4 w-4 rounded border-amber-400 text-amber-600 focus:ring-amber-500">
                            <span class="truncate">+ Émargement</span>
                        </label>
                    </div>
                </div>

                {{-- CONTENU ONGLET 3 : MISE EN PAGE & TITRE --}}
                <div x-show="ongletActif === 'page'" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    {{-- Titre personnalisé --}}
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Titre affiché sur le document</label>
                        <input type="text"
                               x-model="titreDocument"
                               class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-900 shadow-xs focus:border-primary-500 focus:ring-primary-500">
                    </div>

                    {{-- Orientation --}}
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Orientation de la page</label>
                        <div class="flex items-center gap-2">
                            <button type="button"
                                    @click="orientation = 'portrait'"
                                    :class="orientation === 'portrait' ? 'bg-primary-800 text-white font-bold' : 'bg-white text-slate-700 border-slate-300'"
                                    class="flex-1 rounded-lg border px-3 py-1.5 text-xs text-center transition">
                                Portrait
                            </button>
                            <button type="button"
                                    @click="orientation = 'landscape'"
                                    :class="orientation === 'landscape' ? 'bg-primary-800 text-white font-bold' : 'bg-white text-slate-700 border-slate-300'"
                                    class="flex-1 rounded-lg border px-3 py-1.5 text-xs text-center transition">
                                Paysage (recommandé si > 6 col.)
                            </button>
                        </div>
                    </div>

                    {{-- Densité --}}
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Densité des lignes</label>
                        <div class="flex items-center gap-2">
                            <button type="button"
                                    @click="densite = 'standard'"
                                    :class="densite === 'standard' ? 'bg-primary-800 text-white font-bold' : 'bg-white text-slate-700 border-slate-300'"
                                    class="flex-1 rounded-lg border px-3 py-1.5 text-xs text-center transition">
                                Standard
                            </button>
                            <button type="button"
                                    @click="densite = 'compact'"
                                    :class="densite === 'compact' ? 'bg-primary-800 text-white font-bold' : 'bg-white text-slate-700 border-slate-300'"
                                    class="flex-1 rounded-lg border px-3 py-1.5 text-xs text-center transition">
                                Compacte
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- FEUILLE DE DOCUMENT OFFICIELLE / ZONE D'IMPRESSION --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <div :class="orientation === 'landscape' ? 'max-w-7xl' : 'max-w-6xl'"
         class="mx-auto px-4 sm:px-6 py-6 print:p-0 print:m-0 print:max-w-none print-zone">

        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm print:rounded-none print:shadow-none print:border-none">

            {{-- ── 1. CARTOUCHE D'EN-TÊTE INSTITUTIONNEL ── --}}
            <div class="border-b-2 border-primary-900 bg-white p-6 print:p-3">
                {{-- Ligne 1 : Établissement, Faculté & Métadonnées d'édition --}}
                <div class="flex items-start justify-between gap-4 border-b border-slate-100 pb-3"
                     x-show="hasEnteteSuperieurVisible()">
                    <div>
                        <div x-show="champsEntete.etablissement && metadonnees.etablissement"
                             class="text-sm font-black uppercase tracking-wider text-primary-900"
                             x-text="metadonnees.etablissement"></div>
                        <div x-show="champsEntete.faculte && metadonnees.faculte"
                             class="text-xs font-semibold text-slate-700"
                             x-text="metadonnees.faculte"></div>
                        <div x-show="champsEntete.departement && metadonnees.departement"
                             class="text-xs text-slate-500"
                             x-text="'Département : ' + metadonnees.departement"></div>
                    </div>

                    <div class="text-right">
                        <div x-show="champsEntete.annee && metadonnees.annee"
                             class="text-xs font-bold text-slate-800">
                            Année Universitaire : <span class="text-primary-800" x-text="metadonnees.annee"></span>
                        </div>
                        <div x-show="champsEntete.date && metadonnees.date_generation"
                             class="text-[11px] text-slate-500">
                            Édité le <span x-text="metadonnees.date_generation"></span>
                        </div>
                        <div x-show="champsEntete.utilisateur && metadonnees.utilisateur"
                             class="text-[11px] text-slate-400">
                            Par <span x-text="metadonnees.utilisateur"></span>
                        </div>
                    </div>
                </div>

                {{-- Ligne 2 : Titre principal du document --}}
                <div class="my-4 text-center">
                    <h1 class="text-xl font-black uppercase tracking-tight text-slate-900 print:text-lg"
                        x-text="titreDocument"></h1>
                    <p class="mt-1 text-xs font-medium text-slate-500"
                       x-show="champsEntete.filtres && contexte"
                       x-text="'Critères : ' + contexte"></p>
                </div>

                {{-- Ligne 3 : Boîte récapitulative des détails académiques --}}
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5 rounded-xl border border-slate-200 bg-slate-50/70 p-3 text-xs text-slate-700 print:bg-slate-50 print:p-2"
                     x-show="hasDetailsVisibles()">
                    <div x-show="champsEntete.groupe && metadonnees.groupe">
                        <span class="font-bold text-slate-400 uppercase text-[10px] block">Groupe / Classe</span>
                        <span class="font-black text-slate-900 text-sm" x-text="metadonnees.groupe"></span>
                    </div>

                    <div x-show="champsEntete.programme && metadonnees.programme">
                        <span class="font-bold text-slate-400 uppercase text-[10px] block">Programme / Filière</span>
                        <span class="font-semibold text-slate-800" x-text="metadonnees.programme"></span>
                    </div>

                    <div x-show="champsEntete.parcours && metadonnees.parcours">
                        <span class="font-bold text-slate-400 uppercase text-[10px] block">Parcours</span>
                        <span class="font-medium text-slate-700" x-text="metadonnees.parcours"></span>
                    </div>

                    <div x-show="champsEntete.niveau && metadonnees.niveau">
                        <span class="font-bold text-slate-400 uppercase text-[10px] block">Niveau</span>
                        <span class="font-bold text-slate-900" x-text="metadonnees.niveau"></span>
                        <span x-show="champsEntete.semestre && metadonnees.semestre" x-text="' (' + metadonnees.semestre + ')'"></span>
                    </div>

                    <div x-show="champsEntete.effectif">
                        <span class="font-bold text-slate-400 uppercase text-[10px] block">Effectif</span>
                        <span class="font-black text-primary-800" x-text="totalLignes + ' étudiant(s)'"></span>
                    </div>
                </div>
            </div>

            {{-- ── 2. TABLEAU DES ÉTUDIANTS ── --}}
            <div class="overflow-x-auto admin-table-scroll">
                @if(! empty($sections))
                    @foreach($sections as $section)
                        <div class="break-inside-avoid">
                            <h2 class="break-after-avoid bg-slate-100 px-4 py-2 text-xs font-black uppercase tracking-wider text-slate-800 print:bg-slate-100">
                                {{ $section['titre'] }} — {{ $section['effectif'] }} étudiant(s)
                            </h2>
                            <table :class="densite === 'compact' ? 'densite-compact text-xs' : 'text-sm'"
                                   class="w-full print-table">
                                <thead>
                                    <tr class="bg-slate-50 text-left text-[11px] font-bold uppercase tracking-wider text-slate-600 print:bg-slate-100">
                                        @foreach($jeu['entetes'] as $entete)
                                            <th x-show="isColonneActive('{{ $entete }}')"
                                                class="whitespace-nowrap px-3 py-2 border-b border-slate-200">
                                                {{ $labelsColonnes[$entete] ?? $entete }}
                                            </th>
                                        @endforeach
                                        <th x-show="emargement" class="whitespace-nowrap px-3 py-2 border-b border-slate-200 text-center w-36">
                                            Signature / Émargement
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach($section['lignes'] as $ligne)
                                        <tr class="break-inside-avoid {{ $loop->iteration % 2 === 0 ? 'bg-slate-50/50' : 'bg-white' }}">
                                            @foreach($jeu['entetes'] as $entete)
                                                <td x-show="isColonneActive('{{ $entete }}')"
                                                    class="whitespace-nowrap px-3 py-2 tabular-nums">
                                                    {{ $ligne[$entete] ?? '' }}
                                                </td>
                                            @endforeach
                                            <td x-show="emargement" class="whitespace-nowrap px-3 py-2 text-center text-slate-300 border-l border-slate-200">&nbsp;</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endforeach
                @else
                    <table :class="densite === 'compact' ? 'densite-compact text-xs' : 'text-sm'"
                           class="w-full print-table">
                        <thead>
                            <tr class="bg-slate-50 text-left text-[11px] font-bold uppercase tracking-wider text-slate-600 print:bg-slate-100">
                                @foreach($jeu['entetes'] as $entete)
                                    <th x-show="isColonneActive('{{ $entete }}')"
                                        class="whitespace-nowrap px-3 py-2 border-b border-slate-200">
                                        {{ $labelsColonnes[$entete] ?? $entete }}
                                    </th>
                                @endforeach
                                <th x-show="emargement" class="whitespace-nowrap px-3 py-2 border-b border-slate-200 text-center w-36">
                                    Signature / Émargement
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($jeu['lignes'] as $index => $ligne)
                                <tr class="break-inside-avoid {{ $index % 2 === 1 ? 'bg-slate-50/50' : 'bg-white' }}">
                                    @foreach($jeu['entetes'] as $entete)
                                        <td x-show="isColonneActive('{{ $entete }}')"
                                            class="whitespace-nowrap px-3 py-2 tabular-nums">
                                            {{ $ligne[$entete] ?? '' }}
                                        </td>
                                    @endforeach
                                    <td x-show="emargement" class="whitespace-nowrap px-3 py-2 text-center text-slate-300 border-l border-slate-200">&nbsp;</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="20" class="px-4 py-8 text-center text-slate-400">
                                        Aucun étudiant ne correspond aux critères sélectionnés.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                @endif
            </div>

            {{-- ── 3. CADRE DE VISA & SIGNATURES OFFICIELLES (OPTIONNEL) ── --}}
            <div x-show="champsEntete.visa"
                 class="break-inside-avoid mt-6 border-t border-slate-200 p-6 print:p-4">
                <div class="grid grid-cols-2 gap-8 text-xs text-slate-700">
                    <div class="rounded-xl border border-dashed border-slate-300 p-4 text-center">
                        <p class="font-bold text-slate-900">Le Délégué / Représentant des étudiants</p>
                        <p class="text-[10px] text-slate-400 mt-0.5">Date, nom et émargement</p>
                        <div class="h-16"></div>
                    </div>
                    <div class="rounded-xl border border-dashed border-slate-300 p-4 text-center">
                        <p class="font-bold text-slate-900">L'Enseignant / Responsable pédagogique</p>
                        <p class="text-[10px] text-slate-400 mt-0.5">Signature manuscrite et cachet</p>
                        <div class="h-16"></div>
                    </div>
                </div>
            </div>

            {{-- ── 4. PIED DU DOCUMENT ── --}}
            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 px-6 py-3 text-[11px] text-slate-500 print:px-3 print:py-2">
                <span><strong class="tabular-nums" x-text="totalLignes"></strong> étudiant(s) inscrit(s)</span>
                <span>Document officiel — Édité le <span x-text="metadonnees.date_generation"></span></span>
            </div>
        </div>

        {{-- Boutons d'action en bas de page pour le confort web --}}
        <div class="no-print mt-6 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <button type="button"
                        onclick="window.print()"
                        class="inline-flex items-center gap-2 rounded-xl bg-primary-800 px-5 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-primary-900 transition">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24-1.076-.672-2.072-1.258-2.955A9.006 9.006 0 0112 3c2.72 0 5.166 1.205 6.837 3.125a12.012 12.012 0 01-1.257 2.954m-10.86 4.75A9.034 9.034 0 0012 21a9.034 9.034 0 008.28-7.171M6 10.5a.75.75 0 01.75-.75h10.5a.75.75 0 010 1.5H6.75a.75.75 0 01-.75-.75z" />
                    </svg>
                    <span>Imprimer la page</span>
                </button>
                <button type="button"
                        @click="telechargerPdf()"
                        class="inline-flex items-center gap-2 rounded-xl border border-emerald-600 bg-white px-4 py-2.5 text-sm font-bold text-emerald-700 shadow-sm hover:bg-emerald-50 transition">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                    </svg>
                    <span>Télécharger en PDF</span>
                </button>
            </div>
            <a href="{{ url()->previous() }}" class="text-xs font-semibold text-slate-500 hover:text-slate-800">
                ← Retour au tableau
            </a>
        </div>
    </div>
</div>

<script>
function printConfigApp() {
    return {
        panneauOuvert: true,
        ongletActif: 'entete',
        titreDocument: @js($titre),
        orientation: 'portrait',
        densite: 'standard',
        emargement: @js($emargementInitial),
        champsEntete: {
            etablissement: @js($champsEnteteInitiaux !== null ? in_array('etablissement', $champsEnteteInitiaux, true) : true),
            faculte: @js($champsEnteteInitiaux !== null ? in_array('faculte', $champsEnteteInitiaux, true) : true),
            departement: @js($champsEnteteInitiaux !== null ? in_array('departement', $champsEnteteInitiaux, true) : ! empty($metadonnees['departement'])),
            annee: @js($champsEnteteInitiaux !== null ? in_array('annee', $champsEnteteInitiaux, true) : true),
            groupe: @js($champsEnteteInitiaux !== null ? in_array('groupe', $champsEnteteInitiaux, true) : true),
            programme: @js($champsEnteteInitiaux !== null ? in_array('programme', $champsEnteteInitiaux, true) : ! empty($metadonnees['programme'])),
            parcours: @js($champsEnteteInitiaux !== null ? in_array('parcours', $champsEnteteInitiaux, true) : ! empty($metadonnees['parcours'])),
            niveau: @js($champsEnteteInitiaux !== null ? in_array('niveau', $champsEnteteInitiaux, true) : true),
            semestre: @js($champsEnteteInitiaux !== null ? in_array('semestre', $champsEnteteInitiaux, true) : ! empty($metadonnees['semestre'])),
            effectif: @js($champsEnteteInitiaux !== null ? in_array('effectif', $champsEnteteInitiaux, true) : true),
            date: @js($champsEnteteInitiaux !== null ? in_array('date', $champsEnteteInitiaux, true) : true),
            utilisateur: @js($champsEnteteInitiaux !== null ? in_array('utilisateur', $champsEnteteInitiaux, true) : true),
            filtres: @js($champsEnteteInitiaux !== null ? in_array('filtres', $champsEnteteInitiaux, true) : false),
            visa: @js($zoneSignatureInitiale),
        },
        colonnes: @js($colonnesInitiales),
        catalogueColonnes: @js($catalogueColonnes),
        metadonnees: @js($metadonnees),
        contexte: @js($contexte),
        totalLignes: @js(count($jeu['lignes'])),

        nbChampsEnteteActifs() {
            return Object.values(this.champsEntete).filter(Boolean).length;
        },

        hasEnteteSuperieurVisible() {
            return (this.champsEntete.etablissement && this.metadonnees.etablissement)
                || (this.champsEntete.faculte && this.metadonnees.faculte)
                || (this.champsEntete.departement && this.metadonnees.departement)
                || (this.champsEntete.annee && this.metadonnees.annee)
                || (this.champsEntete.date && this.metadonnees.date_generation)
                || (this.champsEntete.utilisateur && this.metadonnees.utilisateur);
        },

        hasDetailsVisibles() {
            return (this.champsEntete.groupe && this.metadonnees.groupe)
                || (this.champsEntete.programme && this.metadonnees.programme)
                || (this.champsEntete.parcours && this.metadonnees.parcours)
                || (this.champsEntete.niveau && this.metadonnees.niveau)
                || this.champsEntete.effectif;
        },

        isColonneActive(col) {
            return this.colonnes.includes(col);
        },

        toggleColonne(col) {
            if (this.colonnes.includes(col)) {
                if (this.colonnes.length > 1) {
                    this.colonnes = this.colonnes.filter(c => c !== col);
                }
            } else {
                this.colonnes.push(col);
            }
        },

        setPreset(type) {
            if (type === 'standard') {
                this.colonnes = ['numero', 'matricule', 'cin', 'nom', 'prenom', 'statut'].filter(c => this.catalogueColonnes.includes(c));
                this.emargement = false;
            } else if (type === 'emargement') {
                this.colonnes = ['numero', 'matricule', 'nom', 'prenom', 'cin'].filter(c => this.catalogueColonnes.includes(c));
                this.emargement = true;
                this.champsEntete.visa = true;
            } else if (type === 'contact') {
                this.colonnes = ['numero', 'matricule', 'nom', 'prenom', 'email', 'telephone'].filter(c => this.catalogueColonnes.includes(c));
                this.emargement = false;
            } else if (type === 'academique') {
                this.colonnes = ['numero', 'matricule', 'nom', 'prenom', 'programme', 'niveau', 'groupe'].filter(c => this.catalogueColonnes.includes(c));
                this.emargement = false;
            } else if (type === 'tout') {
                this.colonnes = [...this.catalogueColonnes];
            }
        },

        setEntetePreset(type) {
            if (type === 'tout') {
                Object.keys(this.champsEntete).forEach(k => this.champsEntete[k] = true);
            } else if (type === 'minimal') {
                Object.keys(this.champsEntete).forEach(k => this.champsEntete[k] = false);
                this.champsEntete.etablissement = true;
                this.champsEntete.faculte = true;
                this.champsEntete.groupe = true;
                this.champsEntete.annee = true;
                this.champsEntete.effectif = true;
            } else if (type === 'aucun') {
                Object.keys(this.champsEntete).forEach(k => this.champsEntete[k] = false);
            }
        },

        telechargerPdf() {
            const params = new URLSearchParams(window.location.search);
            params.set('titre', this.titreDocument);
            params.set('orientation', this.orientation);

            // Exportation des colonnes sélectionnées
            params.delete('colonnes[]');
            params.delete('colonnes');
            this.colonnes.forEach(col => params.append('colonnes[]', col));

            // Exportation des champs d'en-tête cochés
            params.delete('champs_entete[]');
            params.delete('champs_entete');
            Object.keys(this.champsEntete).forEach(k => {
                if (this.champsEntete[k]) {
                    params.append('champs_entete[]', k);
                }
            });

            if (this.emargement) {
                params.set('emargement', '1');
            } else {
                params.delete('emargement');
            }

            if (this.champsEntete.visa) {
                params.set('zone_signature', '1');
            } else {
                params.delete('zone_signature');
            }

            const url = '{{ route('admin.listes.exporter', ['format' => 'pdf']) }}?' + params.toString();
            window.location.href = url;
        }
    };
}
</script>
@endsection
