@extends('layouts.app')

@section('title', 'Simulateur temporel LMD')

@section('content')
<div class="mx-auto max-w-6xl space-y-6" x-data="simulateur({
    urlsNiveaux: '{{ $urls['niveaux'] }}',
    urlsGroupes: '{{ $urls['groupes'] }}',
    urlCreer: '{{ $urls['creer'] }}',
    urlExecuter: '{{ $urls['executer'] }}',
    urlExportCsv: '{{ $urls['exportCsv'] ?? '' }}',
    urlExportPdf: '{{ $urls['exportPdf'] ?? '' }}',
    urlExpliquerIa: '{{ $urls['expliquerIa'] ?? '' }}',
    urlProposerGov: '{{ $urls['proposerGov'] ?? '' }}',
    urlDeciderGov: '{{ $urls['deciderGov'] ?? '' }}',
    urlDupliquer: '{{ $urls['dupliquer'] ?? '' }}',
    urlNouvelleVersion: '{{ $urls['nouvelleVersion'] ?? '' }}',
    urlAuditQualite: '{{ $urls['auditQualite'] ?? '' }}',
    horizonMax: {{ $limites['horizonMax'] }},
    anneeId: {{ $anneeId ?? 'null' }},
    anneeLibelle: '{{ $anneeLibelle }}',
    faculteIdParDefaut: {{ $faculteIdParDefaut ? (int)$faculteIdParDefaut : 'null' }},
    variantesMax: {{ $limites['variantesMax'] }},
})" x-init="initialiser()">

  <style>
    /* Révélation sobre des résultats — CSS local, sans CDN. */
    .st-resultats tbody tr { animation: st-entree-ligne .25s ease-out backwards; animation-delay: calc(var(--st-i, 0) * 35ms); }
    @keyframes st-entree-ligne { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: none; } }
    @media (prefers-reduced-motion: reduce) {
      .st-resultats tbody tr, .st-chart-line { animation: none !important; transition: none !important; }
    }
  </style>

  <x-page-header
      titre="Simulateur temporel — effectifs et cohortes LMD"
      description="Projetez l'évolution des effectifs par cohorte sur plusieurs années : admissions (delta annuel borné), progression par niveaux, contraintes dures et classement multicritère. Chaque exécution est reproductible (graine + version du moteur) et strictement idempotente."
      :fil="['Tableau de bord' => route('admin.dashboard'), 'Simulation temporelle' => '#']" />

  {{-- Bannière permanente obligatoire d'avertissement institutionnel --}}
  <x-alert type="warning" titre="Simulation uniquement — aucune décision réelle n'est appliquée.">
      Aucune inscription, affectation, note ou paiement n'est modifié par une exécution : les résultats
      servent uniquement à comparer des scénarios hypothétiques d'aide à la décision.
  </x-alert>

  {{-- Indicateur d'étape (Stepper interactif accessible) --}}
  <nav aria-label="Étapes de la simulation" class="rounded-2xl border border-slate-200/80 bg-white p-3 shadow-xs">
    <ol class="grid grid-cols-2 gap-2 sm:grid-cols-4">
      {{-- Étape 1 --}}
      <li>
        <button type="button" @click="allerAEtape(1)"
                class="flex w-full items-center gap-2.5 rounded-xl p-2 text-left transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                :class="etape === 1 ? 'bg-primary-50 text-primary-950 font-bold' : (etape > 1 ? 'hover:bg-slate-50 text-slate-700' : 'text-slate-400')">
          <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-xs font-bold"
                :class="etape === 1 ? 'bg-primary-800 text-white shadow-xs' : (etape > 1 ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-500')">
            <template x-if="etape > 1">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
            </template>
            <template x-if="etape <= 1"><span>1</span></template>
          </span>
          <span class="min-w-0">
            <span class="block text-[10px] uppercase tracking-wider font-semibold opacity-75">Étape 1</span>
            <span class="block text-xs font-semibold truncate">Périmètre</span>
          </span>
        </button>
      </li>

      {{-- Étape 2 --}}
      <li>
        <button type="button" @click="allerAEtape(2)"
                class="flex w-full items-center gap-2.5 rounded-xl p-2 text-left transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                :class="etape === 2 ? 'bg-primary-50 text-primary-950 font-bold' : (etape > 2 ? 'hover:bg-slate-50 text-slate-700' : (maxEtapeAtteinte >= 2 ? 'text-slate-700 hover:bg-slate-50' : 'text-slate-400 cursor-not-allowed'))"
                :disabled="maxEtapeAtteinte < 2">
          <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-xs font-bold"
                :class="etape === 2 ? 'bg-primary-800 text-white shadow-xs' : (etape > 2 ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-500')">
            <template x-if="etape > 2">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
            </template>
            <template x-if="etape <= 2"><span>2</span></template>
          </span>
          <span class="min-w-0">
            <span class="block text-[10px] uppercase tracking-wider font-semibold opacity-75">Étape 2</span>
            <span class="block text-xs font-semibold truncate">Paramètres</span>
          </span>
        </button>
      </li>

      {{-- Étape 3 --}}
      <li>
        <button type="button" @click="allerAEtape(3)"
                class="flex w-full items-center gap-2.5 rounded-xl p-2 text-left transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                :class="etape === 3 ? 'bg-primary-50 text-primary-950 font-bold' : (etape > 3 ? 'hover:bg-slate-50 text-slate-700' : (maxEtapeAtteinte >= 3 ? 'text-slate-700 hover:bg-slate-50' : 'text-slate-400 cursor-not-allowed'))"
                :disabled="maxEtapeAtteinte < 3">
          <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-xs font-bold"
                :class="etape === 3 ? 'bg-primary-800 text-white shadow-xs' : (etape > 3 ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-500')">
            <template x-if="etape > 3">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
            </template>
            <template x-if="etape <= 3"><span>3</span></template>
          </span>
          <span class="min-w-0">
            <span class="block text-[10px] uppercase tracking-wider font-semibold opacity-75">Étape 3</span>
            <span class="block text-xs font-semibold truncate">Validation</span>
          </span>
        </button>
      </li>

      {{-- Étape 4 --}}
      <li>
        <button type="button" @click="allerAEtape(4)"
                class="flex w-full items-center gap-2.5 rounded-xl p-2 text-left transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                :class="etape === 4 ? 'bg-primary-50 text-primary-950 font-bold' : (resultat ? 'hover:bg-slate-50 text-slate-700' : 'text-slate-400 cursor-not-allowed')"
                :disabled="!resultat">
          <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-xs font-bold"
                :class="etape === 4 ? 'bg-primary-800 text-white shadow-xs' : (resultat ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-500')">
            4
          </span>
          <span class="min-w-0">
            <span class="block text-[10px] uppercase tracking-wider font-semibold opacity-75">Étape 4</span>
            <span class="block text-xs font-semibold truncate">Résultats</span>
          </span>
        </button>
      </li>
    </ol>
  </nav>

  {{-- Message d'erreur global --}}
  <div x-show="erreur" x-cloak role="alert" class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
    <div class="flex items-start gap-3">
      <svg class="h-5 w-5 shrink-0 text-rose-600 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
      <div class="flex-1">
        <p class="font-bold">Erreur de traitement</p>
        <p class="mt-0.5 text-xs" x-text="erreur"></p>
      </div>
      <button type="button" @click="erreur = ''" class="text-rose-500 hover:text-rose-700 text-xs font-semibold">Fermer</button>
    </div>
  </div>

  {{-- ========================================================================= --}}
  {{-- ÉTAPE 1 : PÉRIMÈTRE ACADÉMIQUE                                             --}}
  {{-- ========================================================================= --}}
  <div x-show="etape === 1" x-cloak class="ds-card p-5 sm:p-6 space-y-6">
    <div class="border-b border-slate-100 pb-4">
      <span class="text-[10.5px] font-bold uppercase tracking-wider text-primary-800">Étape 1 sur 3</span>
      <h2 class="text-lg font-black text-slate-900 mt-0.5">Périmètre académique et données sources</h2>
      <p class="text-xs text-slate-500 mt-1">Sélectionnez la faculté, l'année de référence, ainsi que le niveau et les groupes à inclure dans la simulation.</p>
    </div>

    <div class="grid gap-5 sm:grid-cols-2">
      {{-- Faculté --}}
      <div>
        <label for="st-faculte" class="block text-sm font-bold text-slate-700 mb-1">Faculté / Composante</label>
        @if($facultes->count() > 1)
          <select id="st-faculte" x-model="form.faculteId" @change="changerFaculte()" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px] w-full focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20">
            <template x-for="f in facultes" :key="f.id">
              <option :value="f.id" x-text="f.code + ' — ' + f.nom" :selected="f.id === form.faculteId"></option>
            </template>
          </select>
        @else
          <div class="flex items-center justify-between rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm min-h-[44px]">
            <span class="font-semibold text-slate-800">{{ $facultes->first()?->nom ?? 'Faculté active' }}</span>
            <span class="rounded bg-slate-200 px-2 py-0.5 text-[11px] font-bold text-slate-700">{{ $facultes->first()?->code ?? 'Périmètre' }}</span>
          </div>
        @endif
        <p class="text-xs text-slate-500 mt-1">Restreint aux prérogatives académiques de votre compte.</p>
      </div>

      {{-- Année universitaire source --}}
      <div>
        <label class="block text-sm font-bold text-slate-700 mb-1">Année universitaire de référence</label>
        <div class="flex items-center justify-between rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm min-h-[44px]">
          <span class="font-bold text-slate-800" x-text="anneeLibelle"></span>
          <span class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-[11px] font-bold text-emerald-800">Données réelles</span>
        </div>
        <p class="text-xs text-slate-500 mt-1">Les effectifs validés de cette année forment l'état initial.</p>
      </div>

      {{-- Niveau LMD (cascade) --}}
      <div>
        <label for="st-niveau" class="block text-sm font-bold text-slate-700 mb-1">Niveau d'études LMD</label>
        <select id="st-niveau" x-model="form.niveauId" @change="changerNiveau()" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px] w-full focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20">
          <option value="">Tous les niveaux (cohorte globale de la faculté)</option>
          <template x-for="n in niveaux" :key="n.id">
            <option :value="n.id" x-text="`${n.code} — ${n.nom}`"></option>
          </template>
        </select>
        <div class="mt-1 flex items-center justify-between text-xs text-slate-500">
          <span x-show="chargementNiveaux" class="inline-flex items-center gap-1.5 text-primary-700">
            <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
            Chargement des niveaux…
          </span>
          <span x-show="!chargementNiveaux && niveaux.length > 0" x-text="niveaux.length + ' niveaux disponibles'"></span>
          <button type="button" x-show="erreurNiveaux" @click="chargerNiveaux()" class="text-primary-700 underline font-semibold">Réessayer</button>
        </div>
      </div>

      {{-- Groupes compatibles (cascade) --}}
      <div>
        <div class="flex items-center justify-between mb-1">
          <p id="st-groupes-libelle" class="text-sm font-bold text-slate-700">Groupes compatibles</p>
          <template x-if="form.niveauId !== '' && groupes.length > 0">
            <div class="flex items-center gap-2">
              <button type="button" @click="toutSelectionnerGroupes()" class="text-[11px] font-semibold text-primary-700 hover:underline">Tout cocher</button>
              <span class="text-slate-300">·</span>
              <button type="button" @click="toutDeselectionnerGroupes()" class="text-[11px] font-semibold text-slate-500 hover:underline">Tout décocher</button>
            </div>
          </template>
        </div>

        <div role="group" aria-labelledby="st-groupes-libelle"
             class="min-h-[120px] max-h-48 overflow-y-auto rounded-xl border border-slate-200 bg-white p-2.5 transition"
             :class="form.niveauId === '' ? 'bg-slate-50/70 opacity-80' : ''">

          <template x-if="form.niveauId === ''">
            <div class="flex h-24 flex-col items-center justify-center text-center text-xs text-slate-500 p-2">
              <svg class="h-6 w-6 text-slate-400 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
              <span>Sélectionnez un niveau spécifique pour filtrer par groupes.</span>
            </div>
          </template>

          <template x-if="chargementGroupes">
            <div class="flex h-24 items-center justify-center gap-2 text-xs text-slate-500">
              <svg class="h-4 w-4 animate-spin text-primary-700" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
              Chargement des groupes actifs…
            </div>
          </template>

          <template x-if="!chargementGroupes && form.niveauId !== '' && groupes.length === 0">
            <div class="flex h-24 items-center justify-center text-center text-xs text-slate-500 p-2">
              Aucun groupe actif rattaché à ce niveau.
            </div>
          </template>

          <template x-if="!chargementGroupes && form.niveauId !== '' && groupes.length > 0">
            <div class="space-y-1">
              <template x-for="g in groupes" :key="g.id">
                <label class="flex items-center justify-between gap-2 rounded-lg px-2.5 py-1.5 hover:bg-slate-50 cursor-pointer text-sm">
                  <span class="flex items-center gap-2 min-w-0">
                    <input type="checkbox" :value="g.id" x-model.number="form.groupes" class="h-4 w-4 rounded border-slate-300 text-primary-800 focus:ring-primary-500">
                    <span class="font-medium text-slate-800 truncate" x-text="g.code"></span>
                    <span class="text-xs text-slate-500 truncate" x-text="g.nom"></span>
                  </span>
                  <span class="text-[11px] tabular-nums text-slate-600 shrink-0" x-text="g.capacite !== null ? 'Cap. ' + g.capacite : ''"></span>
                </label>
              </template>
            </div>
          </template>
        </div>

        <div class="mt-1 flex items-center justify-between text-xs text-slate-500">
          <span x-text="form.groupes.length > 0 ? form.groupes.length + ' groupe(s) sélectionné(s)' : 'Tous les groupes du niveau si aucun coché'"></span>
          <span class="text-[10px] text-slate-600">Relation native groups.niveau_id</span>
        </div>
      </div>
    </div>

    <div class="flex justify-end pt-2 border-t border-slate-100">
      <x-btn type="button" @click="validerEtape1EtSuivant()">Étape suivante : Paramètres & Hypothèses →</x-btn>
    </div>
  </div>

  {{-- ========================================================================= --}}
  {{-- ÉTAPE 2 : PARAMÈTRES ET HYPOTHÈSES                                         --}}
  {{-- ========================================================================= --}}
  <div x-show="etape === 2" x-cloak class="ds-card p-5 sm:p-6 space-y-6">
    <div class="border-b border-slate-100 pb-4">
      <span class="text-[10.5px] font-bold uppercase tracking-wider text-primary-800">Étape 2 sur 3</span>
      <h2 class="text-lg font-black text-slate-900 mt-0.5">Paramètres de projection et hypothèses</h2>
      <p class="text-xs text-slate-500 mt-1">Configurez l'horizon temporel, le delta d'admissions, les objectifs et contraintes dures.</p>
    </div>

    <div class="grid gap-5 sm:grid-cols-2">
      {{-- Code du scénario --}}
      <div>
        <label for="st-code" class="block text-sm font-bold text-slate-700 mb-1">Code identifiant du scénario</label>
        <input id="st-code" type="text" x-model="form.code" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px] w-full focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20" required maxlength="40">
        <p class="text-xs text-slate-500 mt-1">Doit être unique dans le registre des scénarios.</p>
      </div>

      {{-- Horizon --}}
      <div>
        <label for="st-horizon" class="block text-sm font-bold text-slate-700 mb-1">Horizon de projection (années)</label>
        <div class="flex items-center gap-3">
          <input id="st-horizon" type="range" min="1" :max="horizonMax" x-model.number="form.horizon" class="w-full accent-primary-800">
          <span class="w-12 text-center rounded-lg bg-slate-100 py-1.5 text-sm font-bold tabular-nums text-primary-900" x-text="form.horizon + ' ans'"></span>
        </div>
        <p class="text-xs text-slate-500 mt-1">Plafond garanti par le moteur : <span x-text="horizonMax"></span> années.</p>
      </div>

      {{-- Delta d'admissions --}}
      <div>
        <label for="st-delta" class="block text-sm font-bold text-slate-700 mb-1">Delta annuel d'admissions (référence)</label>
        <div class="flex items-center gap-3">
          <input id="st-delta" type="number" step="0.05" min="-0.5" max="2.0" x-model.number="form.delta" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px] w-32 focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20">
          <span class="rounded-lg bg-primary-50 px-2.5 py-2 text-xs font-bold text-primary-900 tabular-nums" x-text="(form.delta >= 0 ? '+' : '') + (form.delta * 100).toFixed(0) + ' % par an'"></span>
        </div>
        <p class="text-xs text-slate-500 mt-1">Bornes strictes du moteur : −0,5 (−50 %) à +2,0 (+200 %).</p>
      </div>

      {{-- Variantes simulées --}}
      <div>
        <label for="st-variantes" class="block text-sm font-bold text-slate-700 mb-1">Nombre de variantes générées</label>
        <div class="flex items-center gap-3">
          <input id="st-variantes" type="range" min="1" :max="variantesMax" x-model.number="form.nbVariantes" class="w-full accent-primary-800">
          <span class="w-12 text-center rounded-lg bg-slate-100 py-1.5 text-sm font-bold tabular-nums text-primary-900" x-text="form.nbVariantes"></span>
        </div>
        <p class="text-xs text-slate-500 mt-1">Maximum : <span x-text="variantesMax"></span> variantes évaluées et classées.</p>
      </div>

      {{-- Graine de reproductibilité --}}
      <div>
        <label for="st-graine" class="block text-sm font-bold text-slate-700 mb-1">Graine déterministe (reproductibilité)</label>
        <div class="flex items-center gap-2">
          <input id="st-graine" type="text" x-model="form.graine" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px] w-full focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20" required maxlength="64">
          <button type="button" @click="genererGraineAleatoire()" class="shrink-0 rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 active:bg-slate-100 min-h-[44px]" title="Générer une nouvelle graine aléatoire">🎲 Aléatoire</button>
        </div>
        <p class="text-xs text-slate-500 mt-1">Même graine + mêmes paramètres = exécution strictement identique.</p>
      </div>

      {{-- Objectif : Effectif total visé --}}
      <div>
        <label for="st-cible" class="block text-sm font-bold text-slate-700 mb-1">Objectif principal : effectif total cible</label>
        <input id="st-cible" type="number" x-model.number="form.cible" min="1" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px] w-full focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20" required>
        <p class="text-xs text-slate-500 mt-1">Pondération 1.0 : le classement optimise la proximité à cette cible.</p>
      </div>

      {{-- Contraintes dures --}}
      <div class="sm:col-span-2 rounded-xl border border-slate-200 bg-slate-50/60 p-4 space-y-3">
        <div class="flex items-center justify-between">
          <span class="text-xs font-bold uppercase tracking-wider text-slate-700">Contraintes dures (éliminatoires si violées)</span>
          <span class="text-[11px] text-slate-500">Optionnelles</span>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label for="st-plafond" class="block text-xs font-semibold text-slate-700 mb-1">Plafond total d'étudiants (capacité max)</label>
            <input id="st-plafond" type="number" min="1" x-model.number="form.plafond" placeholder="Ex: 5000 (optionnel)" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm w-full">
            <p class="text-[11px] text-slate-500 mt-1">Variantes dépassant ce seuil = non conformes.</p>
          </div>
          <div>
            <label for="st-plancher" class="block text-xs font-semibold text-slate-700 mb-1">Plancher total d'étudiants (seuil min)</label>
            <input id="st-plancher" type="number" min="1" x-model.number="form.plancher" placeholder="Ex: 800 (optionnel)" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm w-full">
            <p class="text-[11px] text-slate-500 mt-1">Variantes inférieures à ce seuil = non conformes.</p>
          </div>
        </div>
      </div>

      {{-- Taux de progression des cohortes --}}
      <div class="sm:col-span-2 rounded-xl border border-slate-200 bg-white p-4 space-y-3">
        <div class="flex items-center justify-between">
          <label for="st-taux-passage" class="text-sm font-bold text-slate-700">Taux de transition de cohortes (optionnels)</label>
          <span class="text-xs font-bold tabular-nums"
                :class="sommeTaux > 1 ? 'text-rose-700' : 'text-slate-600'"
                x-text="'Somme des taux : ' + (sommeTaux * 100).toFixed(1) + ' %'"></span>
        </div>
        <div class="grid grid-cols-3 gap-3">
          <div>
            <label for="st-taux-passage" class="block text-xs font-semibold text-slate-600 mb-1">Taux de passage</label>
            <input id="st-taux-passage" type="number" step="0.05" min="0" max="1" x-model.number="form.tauxPassage" class="rounded-xl border border-slate-300 px-3 py-2 text-sm w-full" placeholder="Ex: 0.70 (70 %)">
          </div>
          <div>
            <label for="st-taux-redoub" class="block text-xs font-semibold text-slate-600 mb-1">Taux de redoublement</label>
            <input id="st-taux-redoub" type="number" step="0.05" min="0" max="1" x-model.number="form.tauxRedoublement" class="rounded-xl border border-slate-300 px-3 py-2 text-sm w-full" placeholder="Ex: 0.20 (20 %)">
          </div>
          <div>
            <label for="st-taux-abandon" class="block text-xs font-semibold text-slate-600 mb-1">Taux d'abandon</label>
            <input id="st-taux-abandon" type="number" step="0.05" min="0" max="1" x-model.number="form.tauxAbandon" class="rounded-xl border border-slate-300 px-3 py-2 text-sm w-full" placeholder="Ex: 0.05 (5 %)">
          </div>
        </div>

        <template x-if="sommeTaux > 1">
          <p class="text-xs font-bold text-rose-700 mt-1">Attention : la somme passage + redoublement + abandon dépasse 100 %. Elle doit être ≤ 1,00.</p>
        </template>
        <template x-if="sommeTaux === 0">
          <p class="text-xs text-slate-500 mt-1">Champs vides = hypothèse méthodologique « cohortes gelées » (seules les admissions progressent selon le delta). Aucune donnée n'est mise à zéro silencieusement.</p>
        </template>
      </div>
    </div>

    <div class="flex items-center justify-between pt-2 border-t border-slate-100">
      <x-btn type="button" variant="secondary" @click="etape = 1">← Précédent (Périmètre)</x-btn>
      <x-btn type="button" @click="validerEtape2EtSuivant()" ::disabled="sommeTaux > 1">Étape suivante : Résumé & Validation →</x-btn>
    </div>
  </div>

  {{-- ========================================================================= --}}
  {{-- ÉTAPE 3 : SYNTHÈSE ET VALIDATION AVANT LANCEMENT                            --}}
  {{-- ========================================================================= --}}
  <div x-show="etape === 3" x-cloak class="ds-card p-5 sm:p-6 space-y-6">
    <div class="border-b border-slate-100 pb-4">
      <span class="text-[10.5px] font-bold uppercase tracking-wider text-primary-800">Étape 3 sur 3</span>
      <h2 class="text-lg font-black text-slate-900 mt-0.5">Synthèse et validation avant exécution</h2>
      <p class="text-xs text-slate-500 mt-1">Vérifiez vos paramètres avant de déclencher le calcul déterministe.</p>
    </div>

    {{-- Grille récapitulative --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-3.5 space-y-1">
        <p class="text-[10.5px] font-bold uppercase tracking-wider text-slate-500">Périmètre</p>
        <p class="text-sm font-bold text-slate-900" x-text="nomFaculteSelectionnee()"></p>
        <p class="text-xs text-slate-600" x-text="form.niveauId ? nomNiveauSelectionne() : 'Tous niveaux'"></p>
        <p class="text-[11px] text-slate-500" x-text="form.groupes.length ? form.groupes.length + ' groupe(s) ciblé(s)' : 'Ensemble des groupes'"></p>
      </div>

      <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-3.5 space-y-1">
        <p class="text-[10.5px] font-bold uppercase tracking-wider text-slate-500">Projection</p>
        <p class="text-sm font-bold text-slate-900" x-text="form.horizon + ' ans d\'horizon'"></p>
        <p class="text-xs text-slate-600" x-text="'Delta réf. : ' + (form.delta >= 0 ? '+' : '') + (form.delta * 100).toFixed(0) + ' % / an'"></p>
        <p class="text-[11px] text-slate-500" x-text="form.nbVariantes + ' variantes générées'"></p>
      </div>

      <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-3.5 space-y-1">
        <p class="text-[10.5px] font-bold uppercase tracking-wider text-slate-500">Objectifs & Contraintes</p>
        <p class="text-sm font-bold text-slate-900" x-text="'Cible : ' + Number(form.cible).toLocaleString('fr-FR') + ' étudiants'"></p>
        <p class="text-xs text-slate-600" x-text="form.plafond ? 'Plafond : ' + form.plafond : 'Plafond : Aucun'"></p>
        <p class="text-[11px] text-slate-500" x-text="form.plancher ? 'Plancher : ' + form.plancher : 'Plancher : Aucun'"></p>
      </div>

      <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-3.5 space-y-1">
        <p class="text-[10.5px] font-bold uppercase tracking-wider text-slate-500">Reproductibilité</p>
        <p class="text-sm font-mono font-bold text-slate-900 truncate" x-text="form.graine"></p>
        <p class="text-xs text-slate-600">Moteur v1.0 déterministe</p>
        <p class="text-[11px] text-slate-500" x-text="sommeTaux > 0 ? 'Taux : ' + (sommeTaux * 100).toFixed(0) + ' %' : 'Cohortes gelées'"></p>
      </div>
    </div>

    <div class="rounded-xl border border-primary-100 bg-primary-50/50 p-4 text-xs text-primary-950 flex items-start gap-3">
      <svg class="h-5 w-5 text-primary-800 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
      <div>
        <span class="font-bold">Garantie d'innocuité et d'isolation :</span>
        L'exécution est purement calculatoire. Aucune écriture n'est effectuée sur les tables académiques (inscriptions, étudiants, affectations, notes, finances).
      </div>
    </div>

    <div class="flex items-center justify-between pt-2 border-t border-slate-100">
      <x-btn type="button" variant="secondary" @click="etape = 2">← Modifier les paramètres</x-btn>
      <div class="flex items-center gap-3">
        <span x-show="enCours" x-cloak class="text-sm font-semibold text-primary-800 inline-flex items-center gap-2">
          <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
          Simulation en cours…
        </span>
        <x-btn type="button" @click="executer()" ::loading="enCours" ::disabled="enCours">
          <span>🚀 Lancer la simulation</span>
        </x-btn>
      </div>
    </div>
  </div>

  {{-- ========================================================================= --}}
  {{-- ÉTAPE 4 : RÉSULTATS, COMPARAISON ET TRAJECTOIRES                          --}}
  {{-- ========================================================================= --}}
  <div x-show="etape === 4 && resultat" x-cloak class="ds-card p-5 sm:p-6 space-y-6" x-transition.opacity.duration.300ms>
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 pb-4">
      <div>
        <span class="text-[10.5px] font-bold uppercase tracking-wider text-emerald-700">Simulation calculée</span>
        <h2 class="text-lg font-black text-slate-900 mt-0.5">Plateforme de planification & analyse prospective</h2>
        <p x-show="idempotente" x-text="idempotente" class="text-xs text-slate-500 mt-0.5 font-medium"></p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <template x-if="executionId">
          <div class="flex items-center gap-2">
            <a :href="urlExportPdf()" target="_blank" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition shadow-xs">
              <svg class="h-3.5 w-3.5 text-rose-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M6 2a2 2 0 00-2 2v12a2 2 0 002 2h8a2 2 0 002-2V7.414A2 2 0 0015.414 6L12 2.586A2 2 0 0010.586 2H6zm5 2l3 3h-3V4zM4 16V4a1 1 0 011-1h5v4a1 1 0 001 1h4v8a1 1 0 01-1 1H5a1 1 0 01-1-1z" clip-rule="evenodd" /></svg>
              Rapport PDF
            </a>
            <a :href="urlExportCsv()" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition shadow-xs">
              <svg class="h-3.5 w-3.5 text-emerald-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
              Export CSV
            </a>
          </div>
        </template>
        <x-btn type="button" variant="secondary" size="sm" @click="reconfigurer()">🔄 Nouvelle simulation</x-btn>
      </div>
    </div>

    {{-- Avertissements méthodologiques --}}
    <template x-if="resultat && resultat.avertissements && resultat.avertissements.length">
      <div class="rounded-xl border border-amber-200 bg-amber-50 p-3.5 text-xs text-amber-900 space-y-1">
        <div class="font-bold flex items-center gap-2">
          <svg class="h-4 w-4 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
          Note méthodologique sur les données sources
        </div>
        <template x-for="a in resultat.avertissements" :key="a">
          <p x-text="a"></p>
        </template>
      </div>
    </template>

    {{-- KPI Cards --}}
    <div class="grid gap-3.5 grid-cols-2 lg:grid-cols-4">
      <div class="rounded-xl border border-slate-200/80 bg-slate-50/60 p-4">
        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Effectif initial réel</p>
        <p class="mt-1 text-2xl font-black text-slate-900 tabular-nums" x-text="resultat ? Number(resultat.etat_initial.total_initial).toLocaleString('fr-FR') : '0'"></p>
        <p class="text-xs text-slate-500 mt-0.5" x-text="resultat ? resultat.etat_initial.annee_source : ''"></p>
      </div>

      <div class="rounded-xl border border-primary-200/80 bg-primary-50/40 p-4">
        <p class="text-[11px] font-bold uppercase tracking-wider text-primary-800">Scénario N°1 projeté</p>
        <p class="mt-1 text-2xl font-black text-primary-950 tabular-nums" x-text="meilleureVariante() ? Number(meilleureVariante().total_final).toLocaleString('fr-FR') : 'N/A'"></p>
        <p class="text-xs text-primary-800 mt-0.5" x-text="meilleureVariante() ? 'Delta : ' + (meilleureVariante().delta >= 0 ? '+' : '') + (meilleureVariante().delta * 100).toFixed(1) + ' %' : ''"></p>
      </div>

      <div class="rounded-xl border border-emerald-200/80 bg-emerald-50/40 p-4">
        <p class="text-[11px] font-bold uppercase tracking-wider text-emerald-800">Meilleur score d'adéquation</p>
        <p class="mt-1 text-2xl font-black text-emerald-950 tabular-nums" x-text="meilleureVariante() ? (meilleureVariante().score * 100).toFixed(1) + ' %' : '0 %'"></p>
        <p class="text-xs text-emerald-700 mt-0.5" x-text="meilleureVariante() ? 'Score : ' + meilleureVariante().score.toFixed(3) + ' / 1.000' : ''"></p>
      </div>

      <div class="rounded-xl border border-slate-200/80 bg-slate-50/60 p-4">
        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Conformité aux règles dures</p>
        <p class="mt-1 text-2xl font-black tabular-nums"
           :class="resultat && resultat.classement.length > 0 ? 'text-slate-900' : 'text-rose-700'"
           x-text="resultat ? resultat.classement.length + ' / ' + (resultat.classement.length + resultat.non_conformes.length) : '0'"></p>
        <p class="text-xs mt-0.5"
           :class="resultat && resultat.non_conformes.length > 0 ? 'text-rose-600 font-semibold' : 'text-slate-500'"
           x-text="resultat && resultat.non_conformes.length > 0 ? resultat.non_conformes.length + ' rejeté(s)' : '100 % conformes'"></p>
      </div>
    </div>

    {{-- Barre de navigation par onglets thématiques --}}
    <div class="border-b border-slate-200">
      <nav class="flex flex-wrap gap-4 text-xs font-semibold" aria-label="Analyses avancées">
        <button type="button" @click="ongletActif = 'trajectoire'"
          class="pb-2.5 transition border-b-2"
          :class="ongletActif === 'trajectoire' ? 'border-primary-800 text-primary-900 font-bold' : 'border-transparent text-slate-500 hover:text-slate-700'">
          📊 Trajectoire & Comparatif
        </button>
        <button type="button" @click="ongletActif = 'capacites'"
          class="pb-2.5 transition border-b-2"
          :class="ongletActif === 'capacites' ? 'border-primary-800 text-primary-900 font-bold' : 'border-transparent text-slate-500 hover:text-slate-700'">
          🏫 Capacités & Groupes
        </button>
        <button type="button" @click="ongletActif = 'sensibilite'"
          class="pb-2.5 transition border-b-2"
          :class="ongletActif === 'sensibilite' ? 'border-primary-800 text-primary-900 font-bold' : 'border-transparent text-slate-500 hover:text-slate-700'">
          🎯 Sensibilité & Pareto
        </button>
        <button type="button" @click="ongletActif = 'montecarlo'"
          class="pb-2.5 transition border-b-2"
          :class="ongletActif === 'montecarlo' ? 'border-primary-800 text-primary-900 font-bold' : 'border-transparent text-slate-500 hover:text-slate-700'">
          🎲 Monte-Carlo & Budget
        </button>
        <button type="button" @click="ongletActif = 'gouvernance'"
          class="pb-2.5 transition border-b-2"
          :class="ongletActif === 'gouvernance' ? 'border-primary-800 text-primary-900 font-bold' : 'border-transparent text-slate-500 hover:text-slate-700'">
          ⚖️ Gouvernance, Qualité & IA
        </button>
      </nav>
    </div>

    {{-- ONGLET 1 : TRAJECTOIRE ET COMPARATIF ANNUEL --}}
    <div x-show="ongletActif === 'trajectoire'" class="space-y-6">
      {{-- Graphique SVG d'évolution temporelle --}}
      <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5 space-y-3">
        <div class="flex items-center justify-between">
          <div>
            <h3 class="text-sm font-bold text-slate-900">Trajectoires temporelles des effectifs</h3>
            <p class="text-xs text-slate-500">Comparaison de la référence et des scénarios simulés sur <span x-text="form.horizon"></span> ans.</p>
          </div>
          <div class="flex items-center gap-3 text-xs">
            <span class="inline-flex items-center gap-1.5 font-semibold text-primary-800">
              <span class="h-2.5 w-2.5 rounded-full bg-primary-800"></span> Référence
            </span>
            <span class="inline-flex items-center gap-1.5 font-semibold text-emerald-700">
              <span class="h-2.5 w-2.5 rounded-full bg-emerald-600"></span> Rang 1 (optimal)
            </span>
          </div>
        </div>

        <div class="w-full overflow-hidden">
          <template x-if="donneesGraphique().points && donneesGraphique().points.length > 0">
            <svg viewBox="0 0 600 200" class="w-full h-48 select-none" aria-label="Courbe de projection des effectifs par année">
              <line x1="50" y1="20" x2="580" y2="20" stroke="#f1f5f9" stroke-width="1" />
              <line x1="50" y1="65" x2="580" y2="65" stroke="#f1f5f9" stroke-width="1" />
              <line x1="50" y1="110" x2="580" y2="110" stroke="#f1f5f9" stroke-width="1" />
              <line x1="50" y1="155" x2="580" y2="155" stroke="#e2e8f0" stroke-width="1" />

              <text x="42" y="24" text-anchor="end" font-size="9" fill="#94a3b8" class="tabular-nums" x-text="donneesGraphique().valMax"></text>
              <text x="42" y="90" text-anchor="end" font-size="9" fill="#94a3b8" class="tabular-nums" x-text="Math.round((donneesGraphique().valMax + donneesGraphique().valMin) / 2)"></text>
              <text x="42" y="159" text-anchor="end" font-size="9" fill="#94a3b8" class="tabular-nums" x-text="donneesGraphique().valMin"></text>

              <path :d="donneesGraphique().pathRef" fill="none" stroke="#1e1b4b" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="st-chart-line" />
              <path :d="donneesGraphique().pathOpt" fill="none" stroke="#059669" stroke-width="2.5" stroke-dasharray="4 3" stroke-linecap="round" stroke-linejoin="round" class="st-chart-line" />

              <template x-for="(pt, idx) in donneesGraphique().points" :key="'pt' + idx">
                <g>
                  <line :x1="pt.x" y1="20" :x2="pt.x" y2="155" stroke="#f8fafc" stroke-width="1" />
                  <text :x="pt.x" y="172" text-anchor="middle" font-size="10" fill="#64748b" font-weight="600" x-text="pt.label"></text>
                  <circle :cx="pt.x" :cy="pt.yRef" r="4" fill="#1e1b4b" stroke="#ffffff" stroke-width="1.5" />
                  <circle :cx="pt.x" :cy="pt.yOpt" r="3.5" fill="#059669" stroke="#ffffff" stroke-width="1.5" />
                </g>
              </template>
            </svg>
          </template>
        </div>
      </div>

      {{-- Tableau Comparatif Annuel (Lot 2) --}}
      <template x-if="resultat && resultat.comparaison">
        <div class="space-y-3">
          <div class="flex items-center justify-between">
            <h3 class="text-sm font-bold text-slate-900">Tableau comparatif annuel détaillé (Référence vs Projection N°1)</h3>
            <span class="text-xs text-slate-500">Flux et écarts certifiés par pas simulé</span>
          </div>
          <div class="overflow-x-auto rounded-xl border border-slate-200">
            <table class="w-full text-left text-sm whitespace-nowrap">
              <thead class="bg-slate-50 text-[11px] uppercase tracking-wider text-slate-500">
                <tr>
                  <th class="px-4 py-2.5">Année</th>
                  <th class="px-4 py-2.5">Réf. (source)</th>
                  <th class="px-4 py-2.5">Projeté</th>
                  <th class="px-4 py-2.5">Écart Absolu</th>
                  <th class="px-4 py-2.5">Écart (%)</th>
                  <th class="px-4 py-2.5">Cible Métier</th>
                  <th class="px-4 py-2.5">Statut Contraintes</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <template x-for="l in resultat.comparaison.lignes" :key="l.pas">
                  <tr class="hover:bg-slate-50/80 transition">
                    <td class="px-4 py-2.5 font-bold text-slate-900" x-text="l.annee"></td>
                    <td class="px-4 py-2.5 tabular-nums text-slate-600" x-text="Number(l.effectif_reference).toLocaleString('fr-FR')"></td>
                    <td class="px-4 py-2.5 tabular-nums font-bold text-primary-950" x-text="Number(l.effectif_projete).toLocaleString('fr-FR')"></td>
                    <td class="px-4 py-2.5 tabular-nums font-semibold"
                        :class="l.ecart_absolu > 0 ? 'text-primary-800' : (l.ecart_absolu < 0 ? 'text-amber-800' : 'text-slate-500')"
                        x-text="(l.ecart_absolu >= 0 ? '+' : '') + l.ecart_absolu"></td>
                    <td class="px-4 py-2.5 tabular-nums"
                        :class="l.ecart_pourcentage > 0 ? 'text-primary-800' : (l.ecart_pourcentage < 0 ? 'text-amber-800' : 'text-slate-500')"
                        x-text="(l.ecart_pourcentage >= 0 ? '+' : '') + l.ecart_pourcentage + ' %'"></td>
                    <td class="px-4 py-2.5 text-xs text-slate-600" x-text="l.cible_metier ? Number(l.cible_metier).toLocaleString('fr-FR') : 'N/A'"></td>
                    <td class="px-4 py-2.5">
                      <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold"
                            :class="l.conforme_contraintes ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'"
                            x-text="l.conforme_contraintes ? 'Conforme' : 'Violation'"></span>
                    </td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>
        </div>
      </template>

      {{-- Tableau des variantes conformes classées (Lot 3) --}}
      <div class="space-y-3">
        <div class="flex items-center justify-between">
          <h3 class="text-sm font-bold text-slate-900">Classement multicritère des scénarios conformes</h3>
          <span class="text-xs text-slate-500" x-text="resultat.classement.length + ' variante(s) recevable(s)'"></span>
        </div>

        <template x-if="resultat && resultat.classement.length === 0">
          <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-center text-sm font-bold text-rose-800">
            Aucun scénario conforme aux contraintes dures configurées.
          </div>
        </template>

        <div class="overflow-x-auto rounded-xl border border-slate-200" x-show="resultat && resultat.classement.length > 0">
          <table class="st-resultats w-full text-left text-sm whitespace-nowrap">
            <thead class="bg-slate-50 text-[11px] uppercase tracking-wider text-slate-500">
              <tr>
                <th class="px-4 py-2.5">Rang</th>
                <th class="px-4 py-2.5">Variante</th>
                <th class="px-4 py-2.5">Delta annuel</th>
                <th class="px-4 py-2.5">Total final</th>
                <th class="px-4 py-2.5">Écart vs réf.</th>
                <th class="px-4 py-2.5">Score d'objectif</th>
                <th class="px-4 py-2.5">Contributions</th>
                <th class="px-4 py-2.5">Trajectoire</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <template x-for="(v, i) in resultat.classement" :key="v.index">
                <tr class="hover:bg-slate-50/80 transition" :class="v.index === 0 ? 'bg-primary-50/40 font-semibold' : ''" :style="'--st-i:' + i">
                  <td class="px-4 py-2.5">
                    <span class="inline-flex items-center gap-1.5">
                      <span class="h-5 w-5 rounded-full inline-flex items-center justify-center text-xs font-bold"
                            :class="i === 0 ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-700'"
                            x-text="i + 1"></span>
                      <template x-if="v.index === 0">
                        <span class="rounded bg-primary-100 px-1.5 py-0.5 text-[10px] font-bold text-primary-900">Réf.</span>
                      </template>
                    </span>
                  </td>
                  <td class="px-4 py-2.5 font-medium text-slate-900" x-text="'Variante #' + v.index"></td>
                  <td class="px-4 py-2.5 tabular-nums text-slate-800" x-text="(v.delta >= 0 ? '+' : '') + (v.delta * 100).toFixed(1) + ' %'"></td>
                  <td class="px-4 py-2.5 font-bold tabular-nums text-slate-900" x-text="Number(v.total_final).toLocaleString('fr-FR')"></td>
                  <td class="px-4 py-2.5 tabular-nums font-semibold"
                      :class="ecartReference(v).startsWith('+') ? 'text-primary-800' : (ecartReference(v).startsWith('-') ? 'text-amber-800' : 'text-slate-500')"
                      x-text="ecartReference(v) || '0'"></td>
                  <td class="px-4 py-2.5">
                    <span class="inline-flex items-center justify-center rounded-full px-2.5 py-0.5 text-xs font-bold tabular-nums"
                          :class="v.score >= .8 ? 'bg-emerald-100 text-emerald-800' : (v.score >= .5 ? 'bg-amber-100 text-amber-800' : 'bg-rose-100 text-rose-800')"
                          x-text="v.score.toFixed(3)"></span>
                  </td>
                  <td class="px-4 py-2.5 text-xs text-slate-600" x-text="Object.entries(v.contributions).map(([k, c]) => k.replace(/_/g, ' ') + ' : ' + c).join(' · ')"></td>
                  <td class="px-4 py-2.5 text-xs tabular-nums text-slate-500" x-text="v.totaux ? v.totaux.join(' → ') : ''"></td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </div>

      {{-- Variantes rejetées --}}
      <template x-if="resultat && resultat.non_conformes && resultat.non_conformes.length > 0">
        <div class="rounded-xl border border-rose-200 bg-rose-50/70 p-4 space-y-2">
          <h3 class="text-sm font-bold text-rose-900 flex items-center gap-2">
            <svg class="h-4 w-4 text-rose-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
            Scénarios rejetés (contraintes dures violées)
          </h3>
          <ul class="list-disc pl-5 space-y-1 text-xs text-rose-900">
            <template x-for="v in resultat.non_conformes" :key="'nc' + v.index">
              <li>
                <span class="font-bold" x-text="'Variante #' + v.index + ' (Delta ' + (v.delta >= 0 ? '+' : '') + (v.delta * 100).toFixed(1) + ' %) : '"></span>
                <span x-text="v.violations.join(' — ')"></span>
              </li>
            </template>
          </ul>
        </div>
      </template>
    </div>

    {{-- ONGLET 2 : CAPACITÉS & GROUPES (LOT 5) --}}
    <div x-show="ongletActif === 'capacites'" class="space-y-4">
      <template x-if="resultat && resultat.capacites">
        <div class="space-y-4">
          <div class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-xl border border-slate-200 bg-white p-4">
              <p class="text-xs text-slate-500 font-semibold">Groupes théoriques requis</p>
              <p class="text-2xl font-black text-slate-900 mt-1" x-text="resultat.capacites.groupes_theoriques_requis_total"></p>
              <p class="text-xs text-slate-500 mt-0.5" x-text="'Base ' + resultat.capacites.capacite_groupe_reference + ' étud./groupe'"></p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4">
              <p class="text-xs text-slate-500 font-semibold">Taux d'occupation des capacités</p>
              <p class="text-2xl font-black mt-1"
                 :class="resultat.capacites.taux_saturation_global_pct > 95 ? 'text-rose-600' : 'text-slate-900'"
                 x-text="(resultat.capacites.taux_saturation_global_pct || 'N/A') + (resultat.capacites.taux_saturation_global_pct ? ' %' : '')"></p>
              <p class="text-xs text-slate-500 mt-0.5" x-text="resultat.capacites.depassement_capacite ? 'Alerte : saturation dépassée' : 'Capacités respectées'"></p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4">
              <p class="text-xs text-slate-500 font-semibold">Déficit estimé en groupes</p>
              <p class="text-2xl font-black text-primary-950 mt-1" x-text="resultat.capacites.deficit_groupes"></p>
              <p class="text-xs text-slate-500 mt-0.5" x-text="resultat.capacites.deficit_groupes > 0 ? 'Besoins d\'ouvertures supplémentaires' : 'Groupes actuels suffisants'"></p>
            </div>
          </div>

          <div class="overflow-x-auto rounded-xl border border-slate-200">
            <table class="w-full text-left text-sm whitespace-nowrap">
              <thead class="bg-slate-50 text-[11px] uppercase tracking-wider text-slate-500">
                <tr>
                  <th class="px-4 py-2.5">Niveau</th>
                  <th class="px-4 py-2.5">Effectif Projeté</th>
                  <th class="px-4 py-2.5">Capacité Réf. Groupe</th>
                  <th class="px-4 py-2.5">Groupes Requis</th>
                  <th class="px-4 py-2.5">Taux de Remplissage</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <template x-for="(d, niv) in resultat.capacites.detail_par_niveau" :key="niv">
                  <tr class="hover:bg-slate-50 transition">
                    <td class="px-4 py-2.5 font-bold text-slate-900" x-text="niv"></td>
                    <td class="px-4 py-2.5 tabular-nums font-semibold" x-text="d.effectif"></td>
                    <td class="px-4 py-2.5 tabular-nums text-slate-600" x-text="d.capacite_groupe_reference + ' places'"></td>
                    <td class="px-4 py-2.5 tabular-nums font-bold text-primary-900" x-text="d.groupes_theoriques_requis"></td>
                    <td class="px-4 py-2.5 tabular-nums font-bold"
                        :class="d.taux_saturation_groupe > 90 ? 'text-amber-700' : 'text-slate-800'"
                        x-text="d.taux_saturation_groupe + ' %'"></td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>
        </div>
      </template>
    </div>

    {{-- ONGLET 3 : SENSIBILITÉ & PARETO (LOTS 7 & 9) --}}
    <div x-show="ongletActif === 'sensibilite'" class="space-y-6">
      <template x-if="resultat && resultat.sensibilite">
        <div class="space-y-3">
          <div class="flex items-center justify-between">
            <h3 class="text-sm font-bold text-slate-900">Analyse de sensibilité univariée (OAT)</h3>
            <span class="text-xs text-slate-500" x-text="resultat.sensibilite.synthese"></span>
          </div>
          <div class="overflow-x-auto rounded-xl border border-slate-200">
            <table class="w-full text-left text-sm whitespace-nowrap">
              <thead class="bg-slate-50 text-[11px] uppercase tracking-wider text-slate-500">
                <tr>
                  <th class="px-4 py-2.5">Paramètre</th>
                  <th class="px-4 py-2.5">Variation Testée</th>
                  <th class="px-4 py-2.5">Sensibilité Relative</th>
                  <th class="px-4 py-2.5">Impact Absolu Estimé</th>
                  <th class="px-4 py-2.5">Interprétation</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <template x-for="p in resultat.sensibilite.parametres_classes" :key="p.parametre">
                  <tr class="hover:bg-slate-50 transition">
                    <td class="px-4 py-2.5 font-bold text-slate-900" x-text="p.libelle"></td>
                    <td class="px-4 py-2.5 tabular-nums text-slate-600" x-text="p.variation_testee"></td>
                    <td class="px-4 py-2.5 tabular-nums font-black text-primary-900" x-text="p.sensibilite_pct + ' %'"></td>
                    <td class="px-4 py-2.5 tabular-nums font-semibold text-slate-800" x-text="'± ' + p.impact_absolu_eleves + ' étud.'"></td>
                    <td class="px-4 py-2.5 text-xs text-slate-500" x-text="p.interpretation"></td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>
        </div>
      </template>

      {{-- Frontière de Pareto --}}
      <template x-if="resultat && resultat.pareto">
        <div class="space-y-3">
          <div class="flex items-center justify-between">
            <h3 class="text-sm font-bold text-slate-900">Frontière de Pareto (Solutions Non Dominées)</h3>
            <span class="text-xs text-slate-500" x-text="resultat.pareto.compromis"></span>
          </div>
          <div class="overflow-x-auto rounded-xl border border-slate-200">
            <table class="w-full text-left text-sm whitespace-nowrap">
              <thead class="bg-slate-50 text-[11px] uppercase tracking-wider text-slate-500">
                <tr>
                  <th class="px-4 py-2.5">Variante Pareto</th>
                  <th class="px-4 py-2.5">Delta Admissions</th>
                  <th class="px-4 py-2.5">Total Final</th>
                  <th class="px-4 py-2.5">Score Global</th>
                  <th class="px-4 py-2.5">Stabilité (Poids 0.5)</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <template x-for="par in resultat.pareto.frontiere_pareto" :key="'par' + par.index">
                  <tr class="hover:bg-emerald-50/40 transition">
                    <td class="px-4 py-2.5 font-bold text-emerald-900" x-text="'Variante #' + par.index"></td>
                    <td class="px-4 py-2.5 tabular-nums font-semibold" x-text="(par.delta >= 0 ? '+' : '') + (par.delta * 100).toFixed(1) + ' %'"></td>
                    <td class="px-4 py-2.5 tabular-nums font-bold text-slate-900" x-text="Number(par.total_final).toLocaleString('fr-FR')"></td>
                    <td class="px-4 py-2.5 tabular-nums font-black text-emerald-700" x-text="(par.score * 100).toFixed(1) + ' %'"></td>
                    <td class="px-4 py-2.5 tabular-nums text-slate-600" x-text="par.contributions.stabilite || '0'"></td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>
        </div>
      </template>
    </div>

    {{-- ONGLET 4 : MONTE-CARLO & BUDGET (LOTS 10 & 11) --}}
    <div x-show="ongletActif === 'montecarlo'" class="space-y-6">
      <template x-if="resultat && resultat.monte_carlo">
        <div class="space-y-3">
          <div class="flex items-center justify-between">
            <h3 class="text-sm font-bold text-slate-900">Simulation Stochastique Déterministe Monte-Carlo</h3>
            <span class="text-xs text-slate-500" x-text="resultat.monte_carlo.nb_iterations + ' itérations · Graine : ' + resultat.monte_carlo.graine"></span>
          </div>
          <div class="grid gap-3 sm:grid-cols-4">
            <div class="rounded-xl border border-slate-200 bg-white p-4">
              <p class="text-xs text-slate-500 font-semibold">Médiane (P50)</p>
              <p class="text-2xl font-black text-slate-900 mt-1" x-text="Number(resultat.monte_carlo.mediane).toLocaleString('fr-FR')"></p>
              <p class="text-xs text-slate-500 mt-0.5">Valeur centrale simulée</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4">
              <p class="text-xs text-slate-500 font-semibold">Intervalle [P10 - P90]</p>
              <p class="text-lg font-black text-primary-950 mt-1" x-text="'[' + resultat.monte_carlo.p10 + ' - ' + resultat.monte_carlo.p90 + ']'"></p>
              <p class="text-xs text-slate-500 mt-0.5">80% des trajectoires</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4">
              <p class="text-xs text-slate-500 font-semibold">Moyenne ± Écart-type</p>
              <p class="text-lg font-black text-slate-900 mt-1" x-text="resultat.monte_carlo.moyenne + ' ± ' + resultat.monte_carlo.ecart_type"></p>
              <p class="text-xs text-slate-500 mt-0.5">Dispersion stochastique</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4">
              <p class="text-xs text-slate-500 font-semibold">Fréquence dépassement plafond</p>
              <p class="text-2xl font-black text-amber-700 mt-1" x-text="resultat.monte_carlo.frequence_depassement_plafond_pct + ' %'"></p>
              <p class="text-xs text-slate-500 mt-0.5">Risque de violation simulé</p>
            </div>
          </div>
        </div>
      </template>

      {{-- Projection budgétaire prospective --}}
      <template x-if="resultat && resultat.budget">
        <div class="space-y-3">
          <div class="flex items-center justify-between">
            <h3 class="text-sm font-bold text-slate-900">Projection budgétaire prospective d'appui</h3>
            <span class="text-xs text-slate-500">Modélisation hypothétique sans écriture comptable</span>
          </div>
          <div class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-xl border border-slate-200 bg-white p-4">
              <p class="text-xs text-slate-500 font-semibold">Coût total projeté</p>
              <p class="text-2xl font-black text-slate-900 mt-1" x-text="Number(resultat.budget.cout_total_projete).toLocaleString('fr-FR') + ' DT'"></p>
              <p class="text-xs text-slate-500 mt-0.5" x-text="'Écart : ' + (resultat.budget.ecart_cout_absolu >= 0 ? '+' : '') + Number(resultat.budget.ecart_cout_absolu).toLocaleString('fr-FR') + ' DT'"></p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4">
              <p class="text-xs text-slate-500 font-semibold">Recettes projetées théoriques</p>
              <p class="text-2xl font-black text-emerald-700 mt-1" x-text="Number(resultat.budget.recettes_projetees).toLocaleString('fr-FR') + ' DT'"></p>
              <p class="text-xs text-slate-500 mt-0.5" x-text="'Base ' + resultat.budget.hypothese_recette_etudiant + ' DT/étudiant'"></p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4">
              <p class="text-xs text-slate-500 font-semibold">Solde d'exploitation simulé</p>
              <p class="text-2xl font-black mt-1"
                 :class="resultat.budget.solde_projete >= 0 ? 'text-emerald-700' : 'text-rose-700'"
                 x-text="Number(resultat.budget.solde_projete).toLocaleString('fr-FR') + ' DT'"></p>
              <p class="text-xs text-slate-500 mt-0.5" x-text="resultat.budget.solde_projete >= 0 ? 'Excédent théorique' : 'Déficit théorique'"></p>
            </div>
          </div>
        </div>
      </template>
    </div>

    {{-- ONGLET 5 : QUALITÉ, GOUVERNANCE & ASSISTANT IA (LOTS 1, 12, 13, 14) --}}
    <div x-show="ongletActif === 'gouvernance'" class="space-y-6">
      {{-- Contrôle qualité & Alertes de seuils --}}
      <div class="grid gap-4 md:grid-cols-2">
        {{-- Qualité des données --}}
        <div class="rounded-xl border border-slate-200 bg-white p-4 space-y-2">
          <div class="flex items-center justify-between">
            <h4 class="text-xs font-bold uppercase tracking-wider text-slate-700">Audit de Qualité des Données (Lot 1)</h4>
            <span class="rounded px-2 py-0.5 text-xs font-bold"
                  :class="resultat.audit_qualite && resultat.audit_qualite.conforme ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'"
                  x-text="resultat.audit_qualite ? 'Score : ' + (resultat.audit_qualite.score_qualite * 100) + ' %' : 'N/A'"></span>
          </div>
          <template x-if="resultat.audit_qualite && resultat.audit_qualite.anomalies.length > 0">
            <ul class="space-y-1.5 text-xs">
              <template x-for="an in resultat.audit_qualite.anomalies" :key="an.code">
                <li class="rounded-lg border p-2 text-xs"
                    :class="an.gravite === 'critique' ? 'border-rose-200 bg-rose-50 text-rose-900' : 'border-amber-200 bg-amber-50 text-amber-900'">
                  <div class="font-bold" x-text="an.code + ' : ' + an.description"></div>
                  <div class="text-[11px] opacity-80" x-text="'Action : ' + an.action_recommandee"></div>
                </li>
              </template>
            </ul>
          </template>
          <template x-if="!resultat.audit_qualite || resultat.audit_qualite.anomalies.length === 0">
            <p class="text-xs text-emerald-700 font-semibold">Aucune anomalie détectée sur ce périmètre académique.</p>
          </template>
        </div>

        {{-- Alertes de seuils configurables --}}
        <div class="rounded-xl border border-slate-200 bg-white p-4 space-y-2">
          <h4 class="text-xs font-bold uppercase tracking-wider text-slate-700">Alertes de Seuils Actives (Lot 13)</h4>
          <template x-if="resultat.alertes_seuils && resultat.alertes_seuils.length > 0">
            <ul class="space-y-1.5 text-xs">
              <template x-for="al in resultat.alertes_seuils" :key="al.code">
                <li class="rounded-lg border p-2 text-xs"
                    :class="al.gravite === 'critique' ? 'border-rose-200 bg-rose-50 text-rose-900' : 'border-amber-200 bg-amber-50 text-amber-900'">
                  <span class="font-bold" x-text="al.indicateur + ' : '"></span>
                  <span x-text="al.message"></span>
                </li>
              </template>
            </ul>
          </template>
          <template x-if="!resultat.alertes_seuils || resultat.alertes_seuils.length === 0">
            <p class="text-xs text-slate-500">Aucun seuil critique ou de vigilance franchi.</p>
          </template>
        </div>
      </div>

      {{-- Circuit de Gouvernance à Quatre Yeux (Lot 14) --}}
      <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5 space-y-4">
        <div class="flex items-center justify-between">
          <div>
            <h3 class="text-sm font-bold text-slate-900">Validation humaine de planification à quatre yeux (Lot 14)</h3>
            <p class="text-xs text-slate-500">Fige la proposition de planification et empêche l'auto-approbation (2-person rule).</p>
          </div>
          <template x-if="resultat.gouvernance_proposition">
            <span class="rounded-full px-3 py-1 text-xs font-bold uppercase tracking-wider"
                  :class="resultat.gouvernance_proposition.statut === 'approuvee' ? 'bg-emerald-100 text-emerald-800' : (resultat.gouvernance_proposition.statut === 'rejetee' ? 'bg-rose-100 text-rose-800' : 'bg-amber-100 text-amber-800')"
                  x-text="'Statut : ' + resultat.gouvernance_proposition.statut"></span>
          </template>
        </div>

        <template x-if="!resultat.gouvernance_proposition">
          <div class="space-y-3">
            <div class="grid gap-3 sm:grid-cols-2">
              <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Titre de la proposition</label>
                <input type="text" x-model="govTitre" placeholder="Ex: Planification admissions 2027-2030" class="w-full rounded-lg border border-slate-300 p-2 text-xs">
              </div>
              <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Justification stratégique</label>
                <input type="text" x-model="govJustification" placeholder="Ex: Adéquation avec l'objectif de 300 étudiants" class="w-full rounded-lg border border-slate-300 p-2 text-xs">
              </div>
            </div>
            <div class="flex justify-end">
              <button type="button" @click="soumettreGov()" :disabled="chargementGov" class="inline-flex items-center gap-2 rounded-lg bg-primary-800 px-3 py-1.5 text-xs font-bold text-white hover:bg-primary-900">
                <span x-text="chargementGov ? 'Soumission...' : 'Soumettre pour examen à 4 yeux'"></span>
              </button>
            </div>
          </div>
        </template>

        <template x-if="resultat.gouvernance_proposition">
          <div class="rounded-xl border border-slate-200 bg-slate-50 p-3.5 space-y-2 text-xs">
            <div class="flex items-center justify-between">
              <span class="font-bold text-slate-800" x-text="resultat.gouvernance_proposition.titre"></span>
              <span class="text-slate-500 font-mono text-[11px]" x-text="resultat.gouvernance_proposition.id"></span>
            </div>
            <p class="text-slate-600" x-text="'Proposé par : ' + resultat.gouvernance_proposition.propose_par_nom + ' le ' + resultat.gouvernance_proposition.date_soumission"></p>
            <p class="text-slate-700 font-medium" x-text="'Justification : ' + resultat.gouvernance_proposition.justification"></p>

            {{-- Formulaire d'approbation si en attente --}}
            <template x-if="resultat.gouvernance_proposition.statut === 'soumise'">
              <div class="pt-2 border-t border-slate-200 space-y-2">
                <input type="text" x-model="govMotif" placeholder="Motif de décision d'approbation ou de rejet..." class="w-full rounded-lg border border-slate-300 p-2 text-xs">
                <div class="flex items-center justify-end gap-2">
                  <button type="button" @click="deciderGov('rejetee')" class="rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-rose-700">Rejeter</button>
                  <button type="button" @click="deciderGov('approuvee')" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-emerald-700">Approuver (4-yeux)</button>
                </div>
              </div>
            </template>
          </div>
        </template>

        <template x-if="messageGov">
          <p class="text-xs font-bold text-emerald-700" x-text="messageGov"></p>
        </template>
        <template x-if="erreurGov">
          <p class="text-xs font-bold text-rose-700" x-text="erreurGov"></p>
        </template>
      </div>

      {{-- Assistant IA Explicatif avec Preuves (Lot 12) --}}
      <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5 space-y-3">
        <div class="flex items-center justify-between">
          <h3 class="text-sm font-bold text-slate-900 flex items-center gap-2">
            <svg class="h-4 w-4 text-primary-700" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
            Assistant IA Explicatif Fondé sur Preuves (Lot 12)
          </h3>
          <span class="text-xs text-slate-500">Zéro chiffre inventé · Preuves formelles citées</span>
        </div>
        <div class="flex gap-2">
          <input type="text" x-model="questionIa" @keydown.enter.prevent="poserQuestionIa()" placeholder="Ex: Pourquoi ce scénario est-il performant ? Quelles hypothèses expliquent l'augmentation ?" class="flex-1 rounded-lg border border-slate-300 p-2 text-xs">
          <button type="button" @click="poserQuestionIa()" :disabled="chargementIa" class="rounded-lg bg-slate-900 px-4 py-2 text-xs font-bold text-white hover:bg-slate-800">
            <span x-text="chargementIa ? 'Analyse...' : 'Interroger l\'IA'"></span>
          </button>
        </div>

        <template x-if="reponseIa">
          <div class="rounded-xl border border-primary-200 bg-primary-50/50 p-3.5 space-y-2 text-xs text-primary-950">
            <div class="font-bold flex items-center justify-between">
              <span>Réponse certifiée de l'assistant IA :</span>
              <span class="text-[10px] text-primary-700 font-mono" x-text="reponseIa.fiabilite"></span>
            </div>
            <p class="leading-relaxed" x-text="reponseIa.reponse"></p>
            <div class="text-[10.5px] text-primary-800 font-mono pt-1 border-t border-primary-100" x-text="'Preuve cryptographique : ' + reponseIa.empreinte_preuve"></div>
          </div>
        </template>
        <template x-if="erreurIa">
          <p class="text-xs font-bold text-rose-700" x-text="erreurIa"></p>
        </template>
      </div>
    </div>

    {{-- Bloc d'audit et de traçabilité --}}
    <div class="rounded-xl border border-slate-200 bg-slate-50/80 p-4 text-xs text-slate-600 space-y-2">
      <p class="font-bold text-slate-700 uppercase tracking-wider text-[10.5px]">Traçabilité et audit de reproductibilité</p>
      <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4 font-mono text-[11px]">
        <div><span class="text-slate-500">Version moteur :</span> <span class="font-semibold text-slate-800">1.0 déterministe</span></div>
        <div><span class="text-slate-500">Graine :</span> <span class="font-semibold text-slate-800" x-text="form.graine"></span></div>
        <div class="truncate"><span class="text-slate-500">Hash entrées :</span> <span class="font-semibold text-slate-800" x-text="hashEntrees || 'Calculé'"></span></div>
        <div class="truncate"><span class="text-slate-500">Hash résultat :</span> <span class="font-semibold text-slate-800" x-text="hashResultat || 'Calculé'"></span></div>
      </div>
      <p class="text-[11px] text-slate-500 pt-1" x-text="resultat ? resultat.rappel : ''"></p>
    </div>
  </div>

</div>
@endsection

@push('scripts')
<script>
function simulateur(cfg) {
  return {
    etape: 1,
    maxEtapeAtteinte: 1,
    niveaux: [],
    groupes: [],
    chargementNiveaux: false,
    chargementGroupes: false,
    erreurNiveaux: false,
    enCours: false,
    erreur: '',
    resultat: null,
    idempotente: '',
    hashEntrees: '',
    hashResultat: '',
    executionId: null,
    scenarioId: null,
    ongletActif: 'trajectoire',
    questionIa: '',
    chargementIa: false,
    reponseIa: null,
    erreurIa: '',
    govTitre: '',
    govJustification: '',
    govMotif: '',
    chargementGov: false,
    messageGov: '',
    erreurGov: '',
    horizonMax: cfg.horizonMax,
    variantesMax: cfg.variantesMax,
    anneeLibelle: cfg.anneeLibelle,
    facultes: @json($facultes ?? []),
    form: {
      code: 'SIM-' + new Date().toISOString().slice(0, 10).replace(/-/g, '') + '-' + Math.floor(1000 + Math.random() * 9000),
      faculteId: cfg.faculteIdParDefaut,
      horizon: 3,
      delta: 0.1,
      nbVariantes: 5,
      graine: 'graine-1',
      tauxPassage: null,
      tauxRedoublement: null,
      tauxAbandon: null,
      niveauId: '',
      groupes: [],
      cible: 1500,
      plafond: null,
      plancher: null,
    },

    get sommeTaux() {
      const p = Number(this.form.tauxPassage || 0);
      const r = Number(this.form.tauxRedoublement || 0);
      const a = Number(this.form.tauxAbandon || 0);
      return Math.round((p + r + a) * 1000) / 1000;
    },

    initialiser() {
      this.chargerNiveaux();
    },

    allerAEtape(n) {
      if (n > this.maxEtapeAtteinte && n !== 4) return;
      if (n === 4 && !this.resultat) return;
      this.etape = n;
      this.erreur = '';
    },

    validerEtape1EtSuivant() {
      this.erreur = '';
      this.etape = 2;
      if (this.maxEtapeAtteinte < 2) this.maxEtapeAtteinte = 2;
    },

    validerEtape2EtSuivant() {
      this.erreur = '';
      if (!this.form.code.trim()) {
        this.erreur = 'Le code du scénario est obligatoire.';
        return;
      }
      if (this.sommeTaux > 1.0001) {
        this.erreur = 'La somme des taux de transition (passage + redoublement + abandon) ne peut pas excéder 100 %.';
        return;
      }
      if (this.form.plafond && this.form.plancher && Number(this.form.plancher) > Number(this.form.plafond)) {
        this.erreur = 'Le plancher ne peut pas être supérieur au plafond.';
        return;
      }
      this.etape = 3;
      if (this.maxEtapeAtteinte < 3) this.maxEtapeAtteinte = 3;
    },

    reconfigurer() {
      this.etape = 2;
    },

    genererGraineAleatoire() {
      this.form.graine = 'graine-' + Math.random().toString(36).substring(2, 10);
    },

    async changerFaculte() {
      this.form.niveauId = '';
      this.form.groupes = [];
      this.groupes = [];
      await this.chargerNiveaux();
    },

    async chargerNiveaux() {
      this.chargementNiveaux = true;
      this.erreurNiveaux = false;
      try {
        let url = cfg.urlsNiveaux;
        if (this.form.faculteId) {
          url += (url.includes('?') ? '&' : '?') + 'faculte_id=' + encodeURIComponent(this.form.faculteId);
        }
        const res = await fetch(url, { headers: { Accept: 'application/json' } });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        this.niveaux = data.niveaux || [];
      } catch (e) {
        this.erreurNiveaux = true;
        this.erreur = 'Impossible de charger la liste des niveaux académiques.';
      } finally {
        this.chargementNiveaux = false;
      }
    },

    async changerNiveau() {
      // Cascade : changement du parent → réinitialisation des enfants invalides.
      this.form.groupes = [];
      this.groupes = [];
      this.erreur = '';
      if (this.form.niveauId === '') return;
      this.chargementGroupes = true;
      try {
        let url = cfg.urlsGroupes.replace('__NIVEAU__', encodeURIComponent(this.form.niveauId));
        if (this.form.faculteId) {
          url += (url.includes('?') ? '&' : '?') + 'faculte_id=' + encodeURIComponent(this.form.faculteId);
        }
        const res = await fetch(url, { headers: { Accept: 'application/json' } });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        this.groupes = data.groupes || [];
      } catch (e) {
        this.erreur = 'Impossible de charger les groupes pour ce niveau.';
      } finally {
        this.chargementGroupes = false;
      }
    },

    toutSelectionnerGroupes() {
      this.form.groupes = this.groupes.map(g => g.id);
    },

    toutDeselectionnerGroupes() {
      this.form.groupes = [];
    },

    nomFaculteSelectionnee() {
      const f = this.facultes.find(item => item.id == this.form.faculteId);
      return f ? f.nom : (this.facultes[0]?.nom || 'Faculté par défaut');
    },

    nomNiveauSelectionne() {
      const n = this.niveaux.find(item => item.id == this.form.niveauId);
      return n ? n.code + ' — ' + n.nom : 'Tous les niveaux';
    },

    meilleureVariante() {
      if (!this.resultat || !this.resultat.classement || this.resultat.classement.length === 0) return null;
      return this.resultat.classement[0];
    },

    ecartReference(v) {
      if (!this.resultat || !this.resultat.classement || this.resultat.classement.length === 0) return '';
      const ref = this.resultat.classement.find(c => c.index === 0);
      if (!ref) return '';
      const ecart = v.total_final - ref.total_final;
      return (ecart >= 0 ? '+' : '') + Number(ecart).toLocaleString('fr-FR');
    },

    donneesGraphique() {
      if (!this.resultat || !this.resultat.classement || this.resultat.classement.length === 0) {
        return { points: [], pathRef: '', pathOpt: '', valMin: 0, valMax: 0 };
      }
      const ref = this.resultat.classement.find(c => c.index === 0) || this.resultat.classement[0];
      const opt = this.resultat.classement[0];
      const totauxRef = ref.totaux || [ref.total_final];
      const totauxOpt = opt.totaux || [opt.total_final];
      const nbPas = totauxRef.length;
      if (nbPas === 0) return { points: [], pathRef: '', pathOpt: '', valMin: 0, valMax: 0 };

      const tousTotaux = [...totauxRef, ...totauxOpt];
      const valMin = Math.max(0, Math.floor(Math.min(...tousTotaux) * 0.95));
      const valMax = Math.ceil(Math.max(...tousTotaux) * 1.05) || 100;
      const ecartY = valMax - valMin || 1;

      const xDebut = 70;
      const xFin = 560;
      const yHaut = 25;
      const yBas = 150;
      const ecartX = nbPas > 1 ? (xFin - xDebut) / (nbPas - 1) : 0;

      const points = [];
      let pathRef = '';
      let pathOpt = '';

      for (let p = 0; p < nbPas; p++) {
        const x = xDebut + p * ecartX;
        const valR = totauxRef[p];
        const valO = totauxOpt[p];
        const yR = yBas - ((valR - valMin) / ecartY) * (yBas - yHaut);
        const yO = yBas - ((valO - valMin) / ecartY) * (yBas - yHaut);

        points.push({
          x: Math.round(x),
          yRef: Math.round(yR),
          yOpt: Math.round(yO),
          label: p === 0 ? 'Init.' : 'An ' + p,
          valRef: valR,
          valOpt: valO,
        });

        pathRef += (p === 0 ? 'M ' : ' L ') + Math.round(x) + ' ' + Math.round(yR);
        pathOpt += (p === 0 ? 'M ' : ' L ') + Math.round(x) + ' ' + Math.round(yO);
      }

      return { points, pathRef, pathOpt, valMin, valMax };
    },

    async executer() {
      this.erreur = '';
      this.enCours = true;
      try {
        const criteres = {
          delta_admissions: Number(this.form.delta),
          bornes: { min: -0.5, max: 2.0 },
          horizon_annees: Number(this.form.horizon),
        };

        if (this.form.tauxPassage !== null || this.form.tauxRedoublement !== null || this.form.tauxAbandon !== null) {
          criteres.taux = {
            passage: Number(this.form.tauxPassage || 0),
            redoublement: Number(this.form.tauxRedoublement || 0),
            abandon: Number(this.form.tauxAbandon || 0),
          };
        }

        if (this.form.niveauId !== '') {
          criteres.niveau_id = Number(this.form.niveauId);
        }
        if (this.form.groupes.length > 0) {
          criteres.groupes = this.form.groupes.map(Number);
        }

        const contraintes = [];
        if (this.form.plafond !== null && this.form.plafond !== '') {
          contraintes.push({ type: 'plafond_total', valeur: Number(this.form.plafond) });
        }
        if (this.form.plancher !== null && this.form.plancher !== '') {
          contraintes.push({ type: 'plancher_total', valeur: Number(this.form.plancher) });
        }

        const corps = {
          code: this.form.code.trim(),
          libelle: 'Scénario ' + this.form.code.trim(),
          faculte_id: this.form.faculteId ? Number(this.form.faculteId) : null,
          criteres,
          contraintes,
          objectifs: [
            { type: 'cible_total', cible: Number(this.form.cible), poids: 1.0 },
            { type: 'stabilite', poids: 0.5 },
          ],
        };

        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;

        // 1. Définition / création du scénario
        const creation = await fetch(cfg.urlCreer, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrf,
          },
          body: JSON.stringify(corps),
        });

        const cree = await creation.json();
        if (!creation.ok) {
          throw new Error(cree.erreur || (cree.errors ? Object.values(cree.errors).flat().join(' ') : 'Création du scénario refusée.'));
        }

        // 2. Exécution déterministe
        const exUrl = cfg.urlExecuter.replace('__ID__', cree.scenario.id);
        const exec = await fetch(exUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrf,
          },
          body: JSON.stringify({
            annee_universitaire_id: cfg.anneeId,
            graine: this.form.graine.trim(),
            nb_variantes: Number(this.form.nbVariantes),
          }),
        });

        const data = await exec.json();
        if (!exec.ok) {
          throw new Error(data.erreur || (data.errors ? Object.values(data.errors).flat().join(' ') : 'Exécution du scénario refusée.'));
        }

        this.resultat = data.execution.resultat;
        this.executionId = data.execution.id;
        this.scenarioId = data.execution.scenario_temporel_id;
        this.hashEntrees = data.execution.hash_entrees;
        this.hashResultat = data.execution.hash_resultat;
        this.idempotente = data.idempotente === 'nouvelle'
          ? 'Nouvelle exécution calculée et enregistrée.'
          : 'Exécution existante retournée par idempotence (résultats et auteur d\'origine figés).';

        this.etape = 4;
        this.maxEtapeAtteinte = 4;
      } catch (e) {
        this.erreur = e.message || 'Une erreur est survenue lors de la simulation.';
      } finally {
        this.enCours = false;
      }
    },

    urlExportCsv() {
      return this.executionId && cfg.urlExportCsv ? cfg.urlExportCsv.replace('__ID__', this.executionId) : '#';
    },

    urlExportPdf() {
      return this.executionId && cfg.urlExportPdf ? cfg.urlExportPdf.replace('__ID__', this.executionId) : '#';
    },

    async poserQuestionIa() {
      if (!this.questionIa.trim() || !this.executionId) return;
      this.chargementIa = true;
      this.erreurIa = '';
      this.reponseIa = null;
      try {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        const res = await fetch(cfg.urlExpliquerIa.replace('__ID__', this.executionId), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
          body: JSON.stringify({ question: this.questionIa.trim() }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.erreur || 'Erreur lors de l\'interrogation de l\'assistant IA.');
        this.reponseIa = data;
      } catch (e) {
        this.erreurIa = e.message;
      } finally {
        this.chargementIa = false;
      }
    },

    async soumettreGov() {
      if (!this.govTitre.trim() || !this.govJustification.trim() || !this.executionId) return;
      this.chargementGov = true;
      this.erreurGov = '';
      this.messageGov = '';
      try {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        const res = await fetch(cfg.urlProposerGov.replace('__ID__', this.executionId), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
          body: JSON.stringify({ titre: this.govTitre.trim(), justification: this.govJustification.trim() }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.erreur || 'Erreur lors de la soumission de la proposition.');
        this.resultat.gouvernance_proposition = data.proposition;
        this.messageGov = 'Proposition formelle soumise avec succès pour examen à quatre yeux.';
      } catch (e) {
        this.erreurGov = e.message;
      } finally {
        this.chargementGov = false;
      }
    },

    async deciderGov(decision) {
      if (!this.govMotif.trim() || !this.executionId) return;
      this.chargementGov = true;
      this.erreurGov = '';
      this.messageGov = '';
      try {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        const res = await fetch(cfg.urlDeciderGov.replace('__ID__', this.executionId), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
          body: JSON.stringify({ decision, motif: this.govMotif.trim() }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.erreur || 'Erreur lors de la décision.');
        this.resultat.gouvernance_proposition = data.proposition;
        this.messageGov = 'Décision de gouvernance enregistrée avec succès.';
      } catch (e) {
        this.erreurGov = e.message;
      } finally {
        this.chargementGov = false;
      }
    },
  };
}
</script>
@endpush
