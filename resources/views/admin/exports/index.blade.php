@extends('layouts.app')

@section('title', 'Exports Académiques — Scolarité')
@section('meta_description', 'Export multicritère : périmètre, filtres combinés, colonnes personnalisées, prévisualisation et génération.')

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">

    {{-- En-tête --}}
    <div class="mb-8 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div class="flex items-center gap-2.5">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary-100 text-primary-700">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                </svg>
            </div>
            <div>
                <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">Exports Académiques</h1>
                <p class="text-xs sm:text-sm text-slate-500">Moteur multicritère : périmètre → filtres → colonnes → format → prévisualisation → génération.</p>
            </div>
        </div>
        <div class="flex gap-2.5">
            <a href="{{ route('admin.integrations.index') }}" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-bold text-slate-600 hover:bg-slate-50 shadow-xs transition-smooth">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
                Intégrations
            </a>
        </div>
    </div>

    {{-- Alerte permission (§85) --}}
    @cannot('exports.personal')
        <div class="mb-6 rounded-2xl border border-amber-200 bg-amber-50/60 px-5 py-3.5 text-xs text-amber-900">
            <strong>Mode restreint :</strong> votre rôle n'inclut pas la permission <code>exports.personal</code> — les colonnes
            CIN, email et téléphone sont retirées des exports (principe du moindre privilège §85).
        </div>
    @endcan

    <div class="grid grid-cols-1 xl:grid-cols-4 gap-6">

        {{-- ── Formulaire principal (colonne 1-3) ── --}}
        <div class="xl:col-span-3 rounded-3xl border border-slate-200/80 bg-white p-6 shadow-xs"
             x-data="exportBuilder()" x-init="chargerOptions()">

            {{-- Étape 1 : type de données (§71) --}}
            <div class="mb-6">
                <label class="mb-2 block text-[11px] font-bold uppercase tracking-[0.18em] text-slate-400">1 · Données à exporter</label>
                <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-2">
                    <template x-for="t in types" :key="t.id">
                        <button type="button" @click="type = t.id; granularite = ''"
                                :class="type === t.id ? 'bg-primary-600 text-white shadow-md border-primary-600' : 'bg-white text-slate-600 hover:border-primary-300'"
                                class="rounded-xl border border-slate-200 px-3 py-2.5 text-[11px] font-bold transition-smooth"
                                x-text="t.label"></button>
                    </template>
                </div>
                <p class="mt-2 text-[11px] text-slate-400" x-text="types.find(t => t.id === type)?.aide"></p>
            </div>

            {{-- Étape 2 : périmètre (listes dépendantes §72) --}}
            <div class="mb-6">
                <label class="mb-2 block text-[11px] font-bold uppercase tracking-[0.18em] text-slate-400">2 · Périmètre académique</label>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <div>
                        <label class="mb-1 block text-[10px] font-bold text-slate-500 uppercase">Faculté</label>
                        <select x-model="filtres.faculte" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                            <option value="">Toutes</option>
                            <template x-for="f in facultes" :key="f.code"><option :value="f.code" x-text="f.code + ' — ' + f.nom"></option></template>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-[10px] font-bold text-slate-500 uppercase">Filière</label>
                        <select x-model="filtres.filiere" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                            <option value="">Toutes</option>
                            <template x-for="fi in filieresDisponibles" :key="fi"><option :value="fi" x-text="fi"></option></template>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-[10px] font-bold text-slate-500 uppercase">Niveau</label>
                        <select x-model="filtres.niveau" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                            <option value="">Tous</option>
                            <template x-for="n in niveauxDisponibles" :key="n"><option :value="n" x-text="n"></option></template>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-[10px] font-bold text-slate-500 uppercase">Groupe</label>
                        <select x-model="filtres.groupe" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                            <option value="">Tous</option>
                            <template x-for="g in groupesDisponibles" :key="g"><option :value="g" x-text="g"></option></template>
                        </select>
                    </div>
                </div>
            </div>

            {{-- Étape 3 : filtres complémentaires (§70) --}}
            <div class="mb-6" x-show="true">
                <label class="mb-2 block text-[11px] font-bold uppercase tracking-[0.18em] text-slate-400">3 · Filtres complémentaires</label>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <div>
                        <label class="mb-1 block text-[10px] font-bold text-slate-500 uppercase">Année universitaire</label>
                        <select x-model="filtres.annee" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-primary-600 focus:outline-none">
                            <option value="">Toutes</option>
                            <template x-for="a in annees" :key="a"><option :value="a" x-text="a"></option></template>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-[10px] font-bold text-slate-500 uppercase">Semestre</label>
                        <select x-model="filtres.semestre" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-primary-600 focus:outline-none">
                            <option value="">Tous</option><option value="1">S1</option><option value="2">S2</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-[10px] font-bold text-slate-500 uppercase">Matière</label>
                        <select x-model="filtres.matiere" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-primary-600 focus:outline-none">
                            <option value="">Toutes</option>
                            <template x-for="m in matieresFiltrees" :key="m.code"><option :value="m.code" x-text="m.code + ' — ' + m.nom"></option></template>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-[10px] font-bold text-slate-500 uppercase">Session</label>
                        <select x-model="filtres.session" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-primary-600 focus:outline-none">
                            <option value="">Toutes</option><option value="normale">Principale</option><option value="rattrapage">Rattrapage</option>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="mb-1 block text-[10px] font-bold text-slate-500 uppercase">Moyenne min</label>
                            <input type="number" step="0.25" min="0" max="20" x-model="filtres.moyenne_min" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-primary-600 focus:outline-none">
                        </div>
                        <div>
                            <label class="mb-1 block text-[10px] font-bold text-slate-500 uppercase">Moyenne max</label>
                            <input type="number" step="0.25" min="0" max="20" x-model="filtres.moyenne_max" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-primary-600 focus:outline-none">
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-[10px] font-bold text-slate-500 uppercase">Statut étudiant</label>
                        <select x-model="filtres.statut" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-primary-600 focus:outline-none">
                            <option value="">Tous</option><option value="valide">Validé</option><option value="en_attente">En attente</option><option value="rejete">Rejeté</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-[10px] font-bold text-slate-500 uppercase">Statut académique</label>
                        <select x-model="filtres.statut_academique" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-primary-600 focus:outline-none">
                            <option value="">Tous</option><option value="valide">Validé</option><option value="en_cours">En cours</option><option value="ajourne">Ajourné</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-[10px] font-bold text-slate-500 uppercase">Décision</label>
                        <select x-model="filtres.decision" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-primary-600 focus:outline-none">
                            <option value="">Toutes</option><option value="admis">Admis</option><option value="rattrapage">Rattrapage</option><option value="ajourne">Ajourné</option>
                        </select>
                    </div>
                </div>
                <div class="mt-3 grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div><label class="mb-1 block text-[10px] font-bold text-slate-500 uppercase">CIN</label><input type="text" x-model="filtres.cin" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-primary-600 focus:outline-none"></div>
                    <div><label class="mb-1 block text-[10px] font-bold text-slate-500 uppercase">Nom contient</label><input type="text" x-model="filtres.nom" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-primary-600 focus:outline-none"></div>
                    <div><label class="mb-1 block text-[10px] font-bold text-slate-500 uppercase">Matricule</label><input type="text" x-model="filtres.matricule" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-primary-600 focus:outline-none"></div>
                </div>
            </div>

            {{-- Étape 4 : granularité (moyennes §73) + colonnes (§78) + profil students --}}
            <div class="mb-6 grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div x-show="type === 'averages'">
                    <label class="mb-2 block text-[11px] font-bold uppercase tracking-[0.18em] text-slate-400">4 · Granularité des moyennes</label>
                    <div class="grid grid-cols-2 gap-2">
                        <template x-for="g in granularites" :key="g.id">
                            <button type="button" @click="granularite = g.id"
                                    :class="granularite === g.id ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-600'"
                                    class="rounded-xl border border-slate-200 px-3 py-2 text-[11px] font-bold transition-smooth" x-text="g.label"></button>
                        </template>
                    </div>
                </div>
                <div x-show="type === 'students'">
                    <label class="mb-2 block text-[11px] font-bold uppercase tracking-[0.18em] text-slate-400">4 · Profil de protection (§43)</label>
                    <select x-model="profil" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-primary-600 focus:outline-none">
                        <option value="administratif">Administratif (identité + contact)</option>
                        <option value="academique">Académique (parcours + résultats)</option>
                        <option value="statistique">Statistique (agrégats anonymes)</option>
                    </select>
                </div>
            </div>

            <div class="mb-6">
                <label class="mb-2 block text-[11px] font-bold uppercase tracking-[0.18em] text-slate-400">5 · Colonnes personnalisées (§78)</label>
                <div class="flex flex-wrap gap-2">
                    <template x-for="c in colonnesDuType" :key="c">
                        <button type="button" @click="basculerColonne(c)"
                                :class="colonnes.includes(c) ? 'bg-primary-50 border-primary-500 text-primary-700 font-bold' : 'bg-white border-slate-200 text-slate-500'"
                                class="rounded-lg border px-2.5 py-1.5 text-[11px] transition-smooth" x-text="c"></button>
                    </template>
                </div>
                <p class="mt-1.5 text-[11px] text-slate-400">Aucune sélection = toutes les colonnes autorisées par votre rôle. Les colonnes sensibles dépendent des permissions (§85).</p>
            </div>

            {{-- Étape 6 : format + prévisualisation + génération --}}
            <div class="flex flex-col lg:flex-row gap-4 items-start lg:items-end justify-between border-t border-slate-100 pt-5">
                <div>
                    <label class="mb-2 block text-[11px] font-bold uppercase tracking-[0.18em] text-slate-400">6 · Format</label>
                    <div class="flex gap-2">
                        <template x-for="fo in ['csv', 'xlsx', 'json', 'xml']" :key="fo">
                            <button type="button" @click="format = fo"
                                    :class="format === fo ? 'bg-slate-900 text-white border-slate-900 shadow-md' : 'bg-white text-slate-600 border-slate-200'"
                                    class="rounded-xl border px-4 py-2 text-xs font-bold uppercase transition-smooth" x-text="fo"></button>
                        </template>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2.5">
                    <button type="button" @click="previsualiser()" :disabled="chargement"
                            class="rounded-xl border border-slate-300 px-5 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-smooth disabled:opacity-50">
                        <span x-show="!chargement">🔍 Prévisualiser</span>
                        <span x-show="chargement" x-cloak>Analyse…</span>
                    </button>
                    <form method="POST" action="{{ route('admin.exports.generate') }}" class="inline">
                        @csrf
                        <input type="hidden" name="type" :value="type">
                        <input type="hidden" name="profil" :value="profil">
                        <input type="hidden" name="format" :value="format">
                        <input type="hidden" name="granularite" :value="granularite">
                        <template x-for="(valeur, cle) in filtresNonVides" :key="cle">
                            <input type="hidden" :name="'filters[' + cle + ']'" :value="valeur">
                        </template>
                        <template x-for="c in colonnes" :key="c">
                            <input type="hidden" name="columns[]" :value="c">
                        </template>
                        @can('configuration.manage')
                            <button type="submit" class="rounded-xl bg-gradient-to-r from-primary-600 to-accent-600 px-5 py-3 text-sm font-extrabold text-white shadow-lg shadow-primary-500/25 hover:-translate-y-0.5 transition-smooth">
                                Générer l'export
                            </button>
                        @endcan
                    </form>
                </div>
            </div>

            {{-- Zone de prévisualisation (§87 étape 6) --}}
            <div x-show="apercu !== null" x-cloak x-transition class="mt-6 rounded-2xl border border-primary-200 bg-primary-50/40 p-5">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-sm font-bold text-primary-900">
                        Prévisualisation — <span x-text="apercu?.total"></span> ligne(s) dans le périmètre
                    </h3>
                    <span class="text-[11px] text-slate-500">Échantillon : 10 premières lignes</span>
                </div>
                <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-50 border-b border-slate-200 text-[10px] uppercase tracking-wider text-slate-400">
                            <tr>
                                <template x-for="e in apercu?.entetes ?? []" :key="e">
                                    <th class="px-3 py-2 font-bold" x-text="e"></th>
                                </template>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <template x-for="(ligne, i) in apercu?.echantillon ?? []" :key="i">
                                <tr>
                                    <template x-for="e in apercu?.entetes ?? []" :key="e">
                                        <td class="px-3 py-2 text-slate-700" x-text="ligne[e] ?? ''"></td>
                                    </template>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- ── Colonne latérale : modèles (§79) + derniers rapports ── --}}
        <div class="xl:col-span-1 space-y-6">
            <div class="rounded-3xl border border-slate-200/80 bg-white p-5 shadow-xs">
                <h3 class="mb-3 flex items-center gap-2 border-b border-slate-800/80 pb-2 text-[11px] font-bold uppercase tracking-[0.18em] text-slate-700">
                    <span class="font-serif text-sm font-bold text-slate-900">I.</span> Modèles d'export (§79)
                </h3>

                @forelse($modeles as $modele)
                    <form method="POST" action="{{ route('admin.exports.generate') }}" class="mb-2 rounded-xl border border-slate-200 p-3 hover:border-primary-300 transition-smooth">
                        @csrf
                        <input type="hidden" name="type" value="{{ $modele->type }}">
                        <input type="hidden" name="profil" value="{{ $modele->profil ?? '' }}">
                        <input type="hidden" name="format" value="{{ $modele->format }}">
                        @foreach(($modele->filters ?? []) as $cle => $valeur)
                            <input type="hidden" name="filters[{{ $cle }}]" value="{{ $valeur }}">
                        @endforeach
                        @foreach(($modele->columns ?? []) as $colonne)
                            <input type="hidden" name="columns[]" value="{{ $colonne }}">
                        @endforeach
                        <div class="flex items-center justify-between gap-2">
                            <div class="min-w-0">
                                <p class="truncate text-xs font-bold text-slate-900">{{ $modele->name }}</p>
                                <p class="text-[10px] text-slate-400">{{ $modele->type }} · {{ strtoupper($modele->format) }}</p>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <button type="submit" class="rounded-lg bg-slate-900 px-2.5 py-1.5 text-[10px] font-bold text-white hover:bg-slate-800 transition-smooth">Utiliser</button>
                                <a href="{{ route('admin.exports.templates.destroy', $modele) }}" onclick="event.preventDefault(); this.closest('form').action='{{ route('admin.exports.templates.destroy', $modele) }}'; this.closest('form').requestSubmit();" class="text-rose-400 hover:text-rose-600 text-xs font-bold">✕</a>
                            </div>
                        </div>
                    </form>
                @empty
                    <p class="text-xs text-slate-400 py-3 text-center">Aucun modèle enregistré.<br>Configurez un export puis cliquez « Enregistrer comme modèle » après génération.</p>
                @endforelse

                <details class="mt-3">
                    <summary class="cursor-pointer text-[11px] font-bold text-primary-600 hover:text-primary-700">＋ Enregistrer la configuration actuelle</summary>
                    <form method="POST" action="{{ route('admin.exports.templates.store') }}" class="mt-2 space-y-2">
                        @csrf
                        <input type="text" name="name" placeholder="ex : Notes L3 Informatique S2" required
                               class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-primary-600 focus:outline-none">
                        <input type="hidden" name="type" value="students" id="modele-type">
                        <input type="hidden" name="format" value="xlsx" id="modele-format">
                        <button type="submit" class="w-full rounded-xl bg-slate-900 px-3 py-2 text-xs font-bold text-white hover:bg-slate-800 transition-smooth">Enregistrer</button>
                        <p class="text-[10px] text-slate-400">Astuce : le modèle capture le type, le format, les filtres et colonnes choisis — utilisez « Prévisualiser » d'abord pour valider.</p>
                    </form>
                </details>
            </div>

            <div class="rounded-3xl border border-slate-200/80 bg-white p-5 shadow-xs">
                <h3 class="mb-3 flex items-center gap-2 border-b border-slate-800/80 pb-2 text-[11px] font-bold uppercase tracking-[0.18em] text-slate-700">
                    <span class="font-serif text-sm font-bold text-slate-900">II.</span> Derniers exports
                </h3>
                <div class="space-y-2">
                    @forelse($rapports as $rapport)
                        <div class="flex items-center justify-between gap-2 rounded-xl border border-slate-200 px-3 py-2">
                            <div class="min-w-0">
                                <p class="text-[11px] font-bold text-slate-800">{{ $rapport->reference }}</p>
                                <p class="text-[10px] text-slate-400">{{ $rapport->type }} · {{ strtoupper($rapport->format) }} · {{ $rapport->total_rows }} l. · {{ $rapport->statut_libelle }}</p>
                            </div>
                            @if($rapport->status === 'completed')
                                <a href="{{ route('admin.exports.download', $rapport) }}" class="flex-shrink-0 rounded-lg bg-slate-900 px-2.5 py-1.5 text-[10px] font-bold text-white hover:bg-slate-800 transition-smooth">Télécharger</a>
                            @endif
                        </div>
                    @empty
                        <p class="text-xs text-slate-400 py-3 text-center">Aucun export généré.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    /**
     * Composant Alpine §72 : listes dépendantes + collecte des filtres.
     * La logique métier (validation, filtrage, permissions) reste
     * intégralement côté serveur — Alpine n'assure que l'expérience.
     */
    function exportBuilder() {
        return {
            types: [
                { id: 'students', label: 'Étudiants', aide: 'Liste d\'étudiants (profils administratif / académique / statistique) — §71' },
                { id: 'subject', label: 'Notes matière', aide: 'Liste de notes d\'une matière : session principale, rattrapage ou toutes — §75' },
                { id: 'group', label: 'Relevé groupe', aide: 'Relevé collectif : matières en colonnes dynamiques + moyenne/crédits/décision — §74' },
                { id: 'averages', label: 'Moyennes', aide: 'Moyennes par matière, semestre, année ou générale — §73' },
                { id: 'filiere', label: 'Synthèse filière', aide: 'Effectifs, moyennes, admis/ajournés par niveau et groupe — §76' },
                { id: 'stats', label: 'Statistiques', aide: 'Moyennes et taux de validation par matière (agrégats) — §86' },
                { id: 'grades', label: 'Notes brutes', aide: 'Toutes les notes détaillées du périmètre' },
            ],
            granularites: [
                { id: 'matiere', label: 'Par matière' },
                { id: 'semestre', label: 'Par semestre' },
                { id: 'annee', label: 'Par année' },
                { id: 'generale', label: 'Générale' },
            ],
            facultes: [], annees: [], matieres: [],
            catalogues: {},
            type: 'students', granularite: '', profil: 'administratif', format: 'xlsx',
            filtres: { faculte: '', filiere: '', niveau: '', groupe: '', annee: '', semestre: '', matiere: '', session: '',
                      statut: '', statut_academique: '', decision: '', moyenne_min: '', moyenne_max: '',
                      cin: '', nom: '', matricule: '' },
            colonnes: [],
            apercu: null, chargement: false,

            async chargerOptions() {
                const reponse = await fetch('{{ route('admin.exports.options') }}', { headers: { 'Accept': 'application/json' } });
                const donnees = await reponse.json();
                this.facultes = donnees.facultes;
                this.annees = donnees.annees;
                this.matieres = donnees.matieres;
                this.catalogues = donnees.catalogues_colonnes;
            },

            get filieresDisponibles() {
                const f = this.facultes.find(x => x.code === this.filtres.faculte);
                return f ? f.filieres : [...new Set(this.facultes.flatMap(x => x.filieres))];
            },
            get niveauxDisponibles() {
                const f = this.facultes.find(x => x.code === this.filtres.faculte);
                return f ? f.niveaux : ['L1', 'L2', 'L3', 'M1', 'M2'];
            },
            get groupesDisponibles() {
                const f = this.facultes.find(x => x.code === this.filtres.faculte);
                return f ? f.groupes : [...new Set(this.facultes.flatMap(x => x.groupes))];
            },
            get matieresFiltrees() {
                return this.matieres.filter(m =>
                    (!this.filtres.niveau || m.niveau === this.filtres.niveau) &&
                    (!this.filtres.semestre || String(m.semestre) === String(this.filtres.semestre)));
            },
            get colonnesDuType() {
                return this.catalogues[this.type] ?? [];
            },
            get filtresNonVides() {
                return Object.fromEntries(Object.entries(this.filtres).filter(([, v]) => v !== '' && v !== null));
            },

            basculerColonne(c) {
                this.colonnes = this.colonnes.includes(c)
                    ? this.colonnes.filter(x => x !== c)
                    : [...this.colonnes, c];
            },

            async previsualiser() {
                this.chargement = true;
                this.apercu = null;
                try {
                    const corps = new URLSearchParams();
                    corps.set('type', this.type);
                    corps.set('profil', this.profil);
                    if (this.granularite) corps.set('granularite', this.granularite);
                    Object.entries(this.filtresNonVides).forEach(([k, v]) => corps.set(`filters[${k}]`, v));
                    this.colonnes.forEach(c => corps.append('columns[]', c));

                    const reponse = await fetch('{{ route('admin.exports.preview') }}', {
                        method: 'POST',
                        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '{{ csrf_token() }}', 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: corps,
                    });
                    this.apercu = await reponse.json();
                } finally {
                    this.chargement = false;
                }
            },
        };
    }
</script>
@endsection
