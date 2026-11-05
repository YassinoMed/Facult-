@extends('layouts.app')

@section('title', 'Gestion des Acquis & Redoublement — Scolarité')

@section('content')
<div class="mx-auto max-w-7xl px-4 sm:px-6 py-6 sm:py-8 space-y-6" x-data="simulationCohorteModal()">

    <!-- En-tête principal -->
    <div class="relative overflow-hidden rounded-3xl border border-slate-200/90 bg-white p-6 sm:p-8 shadow-sm">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
            <div>
                <div class="flex flex-wrap items-center gap-2 mb-2">
                    <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold border border-emerald-200 bg-emerald-50 text-emerald-900">
                        <span class="h-2 w-2 rounded-full bg-emerald-500" aria-hidden="true"></span>
                        Décret n° 2022-631 &amp; Arrêté du 7 juillet 2023
                    </span>
                    <span class="font-mono text-xs font-bold text-slate-500">
                        LMD • Capitalisation Définitive • Compensation • Prérequis • 4 Yeux
                    </span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-black tracking-tight text-slate-900">
                    Cockpit Acquis Académiques &amp; Redoublement
                </h1>
                <p class="mt-1 text-xs sm:text-sm text-slate-500 max-w-3xl leading-relaxed">
                    Pilotage intégral des parcours étudiants : conservation stricte des crédits ECTS acquis, gestion de la compensation annuelle, blocage automatique des prérequis non validés, double validation des dérogations et réinscription sélective sans doublons.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="button"
                        @click="ouvrirModal()"
                        class="inline-flex items-center gap-2 rounded-xl bg-primary-800 px-4 py-2.5 text-xs font-bold text-white shadow-sm hover:bg-primary-900 transition min-h-[42px]">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
                    </svg>
                    <span>Simulation de Cohorte</span>
                </button>

                <a href="{{ route('admin.dashboard') }}"
                   class="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-xs font-bold text-slate-700 shadow-sm hover:bg-slate-50 transition min-h-[42px]">
                    ← Cockpit principal
                </a>
            </div>
        </div>

        <!-- 5 KPI Cards d'état académique -->
        <div class="mt-8 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 pt-6 border-t border-slate-100">
            <!-- Total -->
            <a href="{{ route('admin.redoublement.index', ['statut' => 'tous', 'programme_id' => request('programme_id')]) }}"
               class="p-3.5 rounded-2xl border transition {{ $statutActif === 'tous' ? 'bg-slate-900 border-slate-900 text-white' : 'bg-slate-50 border-slate-200/80 hover:bg-slate-100 text-slate-800' }}">
                <div class="text-[11px] font-bold uppercase tracking-wider {{ $statutActif === 'tous' ? 'text-slate-300' : 'text-slate-500' }}">Dossiers Total</div>
                <div class="mt-1 text-2xl font-black">{{ $kpi['total_dossiers'] }}</div>
                <div class="mt-1 text-[10px] {{ $statutActif === 'tous' ? 'text-slate-400' : 'text-slate-500' }}">Actifs en scolarité</div>
            </a>

            <!-- Redoublants -->
            <a href="{{ route('admin.redoublement.index', ['statut' => 'redoublant', 'programme_id' => request('programme_id')]) }}"
               class="p-3.5 rounded-2xl border transition {{ $statutActif === 'redoublant' ? 'bg-amber-600 border-amber-600 text-white' : 'bg-amber-50/60 border-amber-200 hover:bg-amber-100 text-amber-900' }}">
                <div class="text-[11px] font-bold uppercase tracking-wider {{ $statutActif === 'redoublant' ? 'text-amber-100' : 'text-amber-700' }}">Redoublants</div>
                <div class="mt-1 text-2xl font-black">{{ $kpi['total_redoublants'] }}</div>
                <div class="mt-1 text-[10px] {{ $statutActif === 'redoublant' ? 'text-amber-200' : 'text-amber-700' }}">Plans à instruire</div>
            </a>

            <!-- Compensés -->
            <a href="{{ route('admin.redoublement.index', ['statut' => 'compense', 'programme_id' => request('programme_id')]) }}"
               class="p-3.5 rounded-2xl border transition {{ $statutActif === 'compense' ? 'bg-teal-700 border-teal-700 text-white' : 'bg-teal-50/60 border-teal-200 hover:bg-teal-100 text-teal-900' }}">
                <div class="text-[11px] font-bold uppercase tracking-wider {{ $statutActif === 'compense' ? 'text-teal-100' : 'text-teal-700' }}">Compensés</div>
                <div class="mt-1 text-2xl font-black">{{ $kpi['total_compenses'] }}</div>
                <div class="mt-1 text-[10px] {{ $statutActif === 'compense' ? 'text-teal-200' : 'text-teal-700' }}">Art. 9 (Moyenne &ge; 10)</div>
            </a>

            <!-- Prérequis bloquants -->
            <a href="{{ route('admin.redoublement.index', ['statut' => 'prerequis', 'programme_id' => request('programme_id')]) }}"
               class="p-3.5 rounded-2xl border transition {{ $statutActif === 'prerequis' ? 'bg-rose-700 border-rose-700 text-white' : 'bg-rose-50/60 border-rose-200 hover:bg-rose-100 text-rose-900' }}">
                <div class="text-[11px] font-bold uppercase tracking-wider {{ $statutActif === 'prerequis' ? 'text-rose-100' : 'text-rose-700' }}">Prérequis</div>
                <div class="mt-1 text-2xl font-black">{{ $kpi['total_prerequis'] }}</div>
                <div class="mt-1 text-[10px] {{ $statutActif === 'prerequis' ? 'text-rose-200' : 'text-rose-700' }}">Inscriptions bloquées</div>
            </a>

            <!-- 4 Yeux -->
            <a href="{{ route('admin.redoublement.index', ['statut' => 'quatre_yeux', 'programme_id' => request('programme_id')]) }}"
               class="p-3.5 rounded-2xl border transition {{ $statutActif === 'quatre_yeux' ? 'bg-indigo-700 border-indigo-700 text-white' : 'bg-indigo-50/60 border-indigo-200 hover:bg-indigo-100 text-indigo-900' }}">
                <div class="text-[11px] font-bold uppercase tracking-wider {{ $statutActif === 'quatre_yeux' ? 'text-indigo-100' : 'text-indigo-700' }}">Contrôle 4 Yeux</div>
                <div class="mt-1 text-2xl font-black">{{ $kpi['total_quatre_yeux'] }}</div>
                <div class="mt-1 text-[10px] {{ $statutActif === 'quatre_yeux' ? 'text-indigo-200' : 'text-indigo-700' }}">Visa 2nd agent requis</div>
            </a>

            <!-- En attente jury -->
            <a href="{{ route('admin.redoublement.index', ['statut' => 'attente', 'programme_id' => request('programme_id')]) }}"
               class="p-3.5 rounded-2xl border transition {{ $statutActif === 'attente' ? 'bg-slate-700 border-slate-700 text-white' : 'bg-slate-100 border-slate-200 hover:bg-slate-200 text-slate-800' }}">
                <div class="text-[11px] font-bold uppercase tracking-wider {{ $statutActif === 'attente' ? 'text-slate-200' : 'text-slate-600' }}">En Attente</div>
                <div class="mt-1 text-2xl font-black">{{ $kpi['total_attente'] }}</div>
                <div class="mt-1 text-[10px] {{ $statutActif === 'attente' ? 'text-slate-300' : 'text-slate-500' }}">PV non publié</div>
            </a>
        </div>
    </div>

    <!-- Filtres, Recherche et Onglets rapides -->
    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm space-y-4">
        <!-- Onglets rapides -->
        <div class="flex items-center gap-2 overflow-x-auto pb-1 text-xs font-semibold">
            <span class="text-slate-400 text-[11px] uppercase tracking-wider font-bold mr-1">Cas :</span>
            <a href="{{ route('admin.redoublement.index', ['statut' => 'tous', 'programme_id' => request('programme_id'), 'recherche' => request('recherche')]) }}"
               class="px-3 py-1.5 rounded-xl transition {{ $statutActif === 'tous' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">
                Tous ({{ $kpi['total_dossiers'] }})
            </a>
            <a href="{{ route('admin.redoublement.index', ['statut' => 'redoublant', 'programme_id' => request('programme_id'), 'recherche' => request('recherche')]) }}"
               class="px-3 py-1.5 rounded-xl transition {{ $statutActif === 'redoublant' ? 'bg-amber-600 text-white' : 'bg-amber-50 text-amber-900 hover:bg-amber-100' }}">
                Redoublants à statuer ({{ $kpi['total_redoublants'] }})
            </a>
            <a href="{{ route('admin.redoublement.index', ['statut' => 'compense', 'programme_id' => request('programme_id'), 'recherche' => request('recherche')]) }}"
               class="px-3 py-1.5 rounded-xl transition {{ $statutActif === 'compense' ? 'bg-teal-700 text-white' : 'bg-teal-50 text-teal-900 hover:bg-teal-100' }}">
                Compensés Art. 9 ({{ $kpi['total_compenses'] }})
            </a>
            <a href="{{ route('admin.redoublement.index', ['statut' => 'prerequis', 'programme_id' => request('programme_id'), 'recherche' => request('recherche')]) }}"
               class="px-3 py-1.5 rounded-xl transition {{ $statutActif === 'prerequis' ? 'bg-rose-700 text-white' : 'bg-rose-50 text-rose-900 hover:bg-rose-100' }}">
                Prérequis bloquants ({{ $kpi['total_prerequis'] }})
            </a>
            <a href="{{ route('admin.redoublement.index', ['statut' => 'quatre_yeux', 'programme_id' => request('programme_id'), 'recherche' => request('recherche')]) }}"
               class="px-3 py-1.5 rounded-xl transition {{ $statutActif === 'quatre_yeux' ? 'bg-indigo-700 text-white' : 'bg-indigo-50 text-indigo-900 hover:bg-indigo-100' }}">
                Visa 4 yeux requis ({{ $kpi['total_quatre_yeux'] }})
            </a>
            <a href="{{ route('admin.redoublement.index', ['statut' => 'attente', 'programme_id' => request('programme_id'), 'recherche' => request('recherche')]) }}"
               class="px-3 py-1.5 rounded-xl transition {{ $statutActif === 'attente' ? 'bg-slate-700 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">
                En délibération ({{ $kpi['total_attente'] }})
            </a>
        </div>

        <!-- Formulaire de recherche textuelle et sélection de formation -->
        <form method="GET" action="{{ route('admin.redoublement.index') }}" class="grid grid-cols-1 sm:grid-cols-3 lg:grid-cols-4 gap-3 pt-2">
            <input type="hidden" name="statut" value="{{ $statutActif }}">

            <div>
                <label for="recherche" class="block text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-1">Recherche étudiant</label>
                <input type="text"
                       name="recherche"
                       id="recherche"
                       value="{{ request('recherche') }}"
                       placeholder="Nom, prénom, CIN, matricule..."
                       class="w-full rounded-xl border border-slate-300 px-3 py-2 text-xs text-slate-900 focus:border-primary-800 focus:outline-none focus:ring-1 focus:ring-primary-800">
            </div>

            <div>
                <label for="programme_id" class="block text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-1">Programme d'études</label>
                <select name="programme_id"
                        id="programme_id"
                        class="w-full rounded-xl border border-slate-300 px-3 py-2 text-xs text-slate-900 focus:border-primary-800 focus:outline-none focus:ring-1 focus:ring-primary-800">
                    <option value="">Tous les programmes</option>
                    @foreach($programmes as $prog)
                        <option value="{{ $prog->id }}" {{ request('programme_id') == $prog->id ? 'selected' : '' }}>
                            {{ $prog->nom }} ({{ $prog->code }})
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="sm:col-span-1 lg:col-span-2 flex items-end gap-2">
                <button type="submit"
                        class="inline-flex items-center justify-center rounded-xl bg-primary-800 px-4 py-2 text-xs font-bold text-white shadow-sm hover:bg-primary-900 transition min-h-[38px] flex-1">
                    Appliquer les filtres
                </button>
                <a href="{{ route('admin.redoublement.index') }}"
                   class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-slate-100 px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-slate-200 transition min-h-[38px]">
                    Réinitialiser
                </a>
            </div>
        </form>
    </div>

    <!-- Table des étudiants avec indicateurs par cas -->
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-slate-50 text-slate-600 border-b border-slate-200 uppercase tracking-wider font-semibold">
                    <tr>
                        <th class="px-5 py-3.5">Étudiant</th>
                        <th class="px-5 py-3.5">Cursus &amp; Niveau</th>
                        <th class="px-5 py-3.5">Situation &amp; Délibération</th>
                        <th class="px-5 py-3.5">Crédits ECTS Capitalisés</th>
                        <th class="px-5 py-3.5">Alertes &amp; Cas</th>
                        <th class="px-5 py-3.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($etudiants as $etudiant)
                        @php
                            $derniereInsc = $etudiant->inscriptionsAcademiques->sortByDesc('id')->first();
                            $diag = $etudiant->diagnostic;
                        @endphp
                        <tr class="hover:bg-slate-50/80 transition">
                            <!-- Identité -->
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-3">
                                    <div class="h-9 w-9 rounded-xl bg-primary-100 text-primary-800 font-bold flex items-center justify-center text-xs flex-shrink-0">
                                        {{ mb_substr($etudiant->prenom, 0, 1) }}{{ mb_substr($etudiant->nom, 0, 1) }}
                                    </div>
                                    <div>
                                        <div class="font-bold text-slate-900">{{ $etudiant->nom }} {{ $etudiant->prenom }}</div>
                                        <div class="text-[11px] text-slate-500 font-mono">
                                            CIN : <span class="font-semibold text-slate-700">{{ $etudiant->cin ?? '—' }}</span>
                                            @if($etudiant->matricule)
                                                • Matr : <span class="text-slate-700">{{ $etudiant->matricule }}</span>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </td>

                            <!-- Cursus -->
                            <td class="px-5 py-3.5">
                                @if($derniereInsc)
                                    <div class="font-semibold text-slate-800">{{ $derniereInsc->programmeRef?->nom ?? $derniereInsc->filiere ?? '—' }}</div>
                                    <div class="text-[11px] text-slate-500 font-mono">
                                        Niveau {{ $derniereInsc->niveau }} • {{ $derniereInsc->anneeUniversitaire?->libelle ?? '—' }}
                                    </div>
                                @else
                                    <span class="text-slate-400 italic">Aucune inscription active</span>
                                @endif
                            </td>

                            <!-- Statut délibéré -->
                            <td class="px-5 py-3.5">
                                <div class="flex flex-col gap-1">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[11px] font-bold border {{ $diag['badge_class'] }} w-fit">
                                        <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                                        {{ $diag['libelle'] }}
                                    </span>
                                    <span class="text-[10px] text-slate-400 font-mono">
                                        {{ $diag['deliberation_publiee'] ? 'PV officiel publié' : 'Délibération en attente' }}
                                    </span>
                                </div>
                            </td>

                            <!-- Progression Crédits -->
                            <td class="px-5 py-3.5">
                                <div class="w-36">
                                    <div class="flex items-center justify-between text-[11px] font-bold font-mono text-slate-700 mb-1">
                                        <span>{{ $diag['credits_acquis'] }} / {{ $diag['credits_total'] }} ECTS</span>
                                        <span>{{ $diag['pourcentage'] }}%</span>
                                    </div>
                                    <div class="w-full h-2 rounded-full bg-slate-100 overflow-hidden">
                                        <div class="h-full rounded-full {{ $diag['pourcentage'] >= 100 ? 'bg-emerald-500' : ($diag['pourcentage'] >= 50 ? 'bg-blue-500' : 'bg-amber-500') }}"
                                             style="width: {{ min(100, $diag['pourcentage']) }}%"></div>
                                    </div>
                                </div>
                            </td>

                            <!-- Alertes & Cas spécifiques -->
                            <td class="px-5 py-3.5">
                                @if($diag['alerte'])
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                                        ⚠️ {{ $diag['alerte'] }}
                                    </span>
                                @elseif($diag['code'] === 'QUATRE_YEUX')
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold bg-indigo-50 text-indigo-700 border border-indigo-200">
                                        👁️ Visa 4 yeux requis
                                    </span>
                                @elseif($diag['code'] === 'COMPENSE')
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold bg-teal-50 text-teal-700 border border-teal-200">
                                        ⚖️ Validé par compensation
                                    </span>
                                @else
                                    <span class="text-slate-400 text-[11px]">—</span>
                                @endif
                            </td>

                            <!-- Actions -->
                            <td class="px-5 py-3.5 text-right">
                                <div class="inline-flex items-center gap-1.5">
                                    <a href="{{ route('admin.redoublement.document', [$etudiant, 'releve_redoublement']) }}"
                                       title="Télécharger relevé officiel scellé SHA-256"
                                       class="inline-flex items-center justify-center h-8 w-8 rounded-lg border border-slate-200 bg-white text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                                        </svg>
                                    </a>

                                    <a href="{{ route('admin.redoublement.show', $etudiant) }}"
                                       class="inline-flex items-center gap-1.5 rounded-lg border border-primary-800 bg-primary-800 px-3 py-1.5 text-xs font-bold text-white hover:bg-primary-900 transition shadow-xs">
                                        <span>Auditer &amp; Plan</span>
                                        <span>→</span>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-12 text-center text-slate-500">
                                <div class="max-w-sm mx-auto space-y-2">
                                    <div class="text-3xl">🔍</div>
                                    <div class="font-bold text-slate-700">Aucun dossier étudiant trouvé</div>
                                    <div class="text-xs text-slate-400">Aucun étudiant ne correspond aux critères de filtre ou de statut sélectionnés.</div>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($etudiants->hasPages())
            <div class="p-4 border-t border-slate-200">
                {{ $etudiants->links() }}
            </div>
        @endif
    </div>

    <!-- Modale Simulation de Cohorte Interactive (Alpine.js) -->
    <div x-show="modalOuverte"
         x-cloak
         class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4"
         @keydown.escape.window="fermerModal()">
        <div class="w-full max-w-2xl rounded-3xl bg-white p-6 sm:p-8 shadow-2xl border border-slate-200 space-y-5"
             @click.outside="fermerModal()">

            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <div class="flex items-center gap-2.5">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-primary-100 text-primary-800 font-black">
                        ⚡
                    </span>
                    <div>
                        <h2 class="text-base sm:text-lg font-black text-slate-900">Simulation Analytique de Cohorte</h2>
                        <p class="text-xs text-slate-500">Calcul automatisé des taux de passage, de redoublement et des charges d'ECUEs</p>
                    </div>
                </div>
                <button type="button" @click="fermerModal()" class="text-slate-400 hover:text-slate-600 text-lg font-bold p-1">&times;</button>
            </div>

            <!-- Formulaire de ciblage -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="sim_programme_id" class="block text-xs font-bold text-slate-700 mb-1">Cibler un Programme</label>
                    <select id="sim_programme_id" x-model="programmeId" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-xs">
                        <option value="">Sélectionner un programme</option>
                        @foreach($programmes as $prog)
                            <option value="{{ $prog->id }}">{{ $prog->nom }} ({{ $prog->code }})</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="sim_groupe_id" class="block text-xs font-bold text-slate-700 mb-1">Ou cibler un Groupe</label>
                    <select id="sim_groupe_id" x-model="groupeId" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-xs">
                        <option value="">Sélectionner un groupe</option>
                        @foreach($groupes as $grp)
                            <option value="{{ $grp->id }}">{{ $grp->nom ?? $grp->code }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" @click="fermerModal()"
                        class="rounded-xl border border-slate-300 px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50">
                    Fermer
                </button>
                <button type="button"
                        @click="lancerSimulation()"
                        :disabled="enChargement || (!programmeId && !groupeId)"
                        class="inline-flex items-center gap-2 rounded-xl bg-primary-800 px-4 py-2 text-xs font-bold text-white hover:bg-primary-900 disabled:opacity-50 transition">
                    <span x-show="!enChargement">Lancer l'analyse cohorte</span>
                    <span x-show="enChargement">Calcul en cours...</span>
                </button>
            </div>

            <!-- Résultats de la simulation -->
            <div x-show="resultat !== null" x-cloak class="mt-4 p-5 rounded-2xl bg-slate-50 border border-slate-200 space-y-4">
                <div class="text-xs font-bold uppercase tracking-wider text-slate-600">Résultats de la Cohorte</div>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <div class="p-3 bg-white rounded-xl border border-slate-200">
                        <div class="text-[10px] text-slate-500 font-bold uppercase">Effectif total</div>
                        <div class="text-xl font-black text-slate-900" x-text="resultat?.effectif_total ?? 0"></div>
                    </div>
                    <div class="p-3 bg-white rounded-xl border border-emerald-200">
                        <div class="text-[10px] text-emerald-700 font-bold uppercase">Sans rattrapage</div>
                        <div class="text-xl font-black text-emerald-600" x-text="resultat?.synthese?.etudiants_validables ?? 0"></div>
                    </div>
                    <div class="p-3 bg-white rounded-xl border border-amber-200">
                        <div class="text-[10px] text-amber-700 font-bold uppercase">Avec matières à repasser</div>
                        <div class="text-xl font-black text-amber-600" x-text="resultat?.synthese?.etudiants_avec_blocages ?? 0"></div>
                    </div>
                    <div class="p-3 bg-white rounded-xl border border-blue-200">
                        <div class="text-[10px] text-blue-700 font-bold uppercase">Total ECUEs à rattraper</div>
                        <div class="text-xl font-black text-blue-600" x-text="resultat?.synthese?.total_elements_a_repasser ?? 0"></div>
                    </div>
                </div>

                <div class="text-[11px] text-slate-600 font-mono">
                    Moyenne des crédits ECTS conservés à vie par étudiant :
                    <span class="font-bold text-slate-900" x-text="resultat?.synthese?.moyenne_credits_conserves ?? 0"></span> ECTS
                </div>
            </div>

            <div x-show="erreur" x-cloak class="p-3 rounded-xl bg-rose-50 border border-rose-200 text-xs font-bold text-rose-800" x-text="erreur"></div>
        </div>
    </div>

</div>

<script>
function simulationCohorteModal() {
    return {
        modalOuverte: false,
        enChargement: false,
        programmeId: '',
        groupeId: '',
        resultat: null,
        erreur: null,

        ouvrirModal() {
            this.modalOuverte = true;
            this.resultat = null;
            this.erreur = null;
        },

        fermerModal() {
            this.modalOuverte = false;
        },

        async lancerSimulation() {
            this.enChargement = true;
            this.resultat = null;
            this.erreur = null;

            try {
                const response = await fetch('{{ route('admin.redoublement.simuler-cohorte') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        programme_id: this.programmeId || null,
                        groupe_id: this.groupeId || null
                    })
                });

                if (!response.ok) {
                    const errData = await response.json();
                    throw new Error(errData.message || 'Erreur lors du calcul de simulation.');
                }

                this.resultat = await response.json();
            } catch (e) {
                this.erreur = e.message;
            } finally {
                this.enChargement = false;
            }
        }
    }
}
</script>
@endsection
