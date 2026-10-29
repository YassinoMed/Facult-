@extends('layouts.app')

@section('title', 'Data Mesh — Observabilité')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 py-8">
        <x-page-header titre="Data Mesh — Observabilité" description="État réel du maillage de données : lecture seule, aucun traitement déclenché."
            :fil="['Data Mesh' => '#']">
            <x-slot:actions>
                <a href="{{ route('admin.qualite.index') }}" class="rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Qualité des données</a>
                <a href="{{ route('admin.bi.index') }}" class="rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">BI</a>
            </x-slot:actions>
        </x-page-header>

        <form method="get" action="{{ route('admin.datamesh.index') }}" class="mt-4 flex flex-wrap gap-2" aria-label="Périmètre d'observation">
            @if($facultes->isNotEmpty())
                <select name="faculte_id" class="rounded-xl border border-slate-200 px-3 py-2 text-sm" aria-label="Faculté">
                    <option value="">Toutes facultés</option>
                    @foreach($facultes as $faculte)
                        <option value="{{ $faculte->id }}" @selected((int) ($filtres['faculte_id'] ?? 0) === $faculte->id)>{{ $faculte->nom }}</option>
                    @endforeach
                </select>
            @elseif($estRestreint)
                <p class="rounded-xl bg-slate-100 px-3 py-2 text-sm text-slate-600">Périmètre : votre faculté (forçage serveur).</p>
            @endif
            <select name="annee_id" class="rounded-xl border border-slate-200 px-3 py-2 text-sm" aria-label="Année universitaire">
                <option value="">Année active</option>
                @foreach($annees as $annee)
                    <option value="{{ $annee->id }}" @selected((int) ($filtres['annee_id'] ?? 0) === $annee->id)>{{ $annee->libelle }}</option>
                @endforeach
            </select>
            <x-btn size="sm" type="submit">Appliquer</x-btn>
        </form>

        {{-- Bandeau de synthèse (rendu serveur : visible sans JavaScript) --}}
        <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Synthèse">
            <div class="ds-card p-4">
                <p class="text-2xl font-black tabular-nums text-slate-900">{{ $apercuInitial['produits']['enregistres'] }}</p>
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Produits enregistrés</p>
                <p class="mt-1 text-[11px] text-slate-500">{{ $apercuInitial['produits']['operationnels'] }} opérationnels · {{ $apercuInitial['produits']['en_erreur'] }} en erreur · {{ $apercuInitial['produits']['degrades'] }} dégradés · {{ $apercuInitial['produits']['non_verifies'] }} non vérifiés · {{ $apercuInitial['produits']['inconnus'] }} inconnus (total = {{ $apercuInitial['produits']['enregistres'] }}, états exclusifs)</p>
                <p class="mt-1 text-[11px] text-slate-400">Mesures : {{ $apercuInitial['produits']['date_reference'] ?? 'aucune (non vérifié)' }} · calcul : {{ $apercuInitial['produits']['calcule_le'] }} (UTC)</p>
            </div>
            <div class="ds-card p-4">
                <p class="text-2xl font-black tabular-nums text-slate-900">{{ $apercuInitial['qualite']['disponible'] ? ($apercuInitial['qualite']['total_bloquants'] + $apercuInitial['qualite']['total_avertissements'] + $apercuInitial['qualite']['total_infos']) : '—' }}</p>
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Anomalies qualité</p>
                @if($apercuInitial['qualite']['disponible'])
                    <p class="mt-1 text-[11px] text-slate-500">{{ $apercuInitial['qualite']['total_bloquants'] }} bloquantes · {{ $apercuInitial['qualite']['total_avertissements'] }} avertissements · {{ $apercuInitial['qualite']['total_infos'] }} infos</p>
                    <p class="mt-1 text-[11px] text-slate-400">Contrôle du {{ $apercuInitial['qualite']['date_controle'] }}</p>
                @else
                    <p class="mt-1 text-[11px] font-semibold text-amber-600">Non mesuré — état inconnu, pas un état sain.</p>
                @endif
            </div>
            <div class="ds-card p-4">
                <p class="text-2xl font-black tabular-nums text-slate-900">{{ $apercuInitial['outbox']['disponible'] ? $apercuInitial['outbox']['en_attente'] : '—' }}</p>
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Outbox en attente</p>
                @if($apercuInitial['outbox']['disponible'])
                    <p class="mt-1 text-[11px] text-slate-500">{{ $apercuInitial['outbox']['echecs'] }} en échec · {{ $apercuInitial['outbox']['publies'] }} publiés</p>
                @else
                    <p class="mt-1 text-[11px] font-semibold text-amber-600">Non mesuré.</p>
                @endif
                <p class="mt-1 text-[11px] text-slate-400">Livraison au moins une fois (jamais exactement une fois).</p>
            </div>
            <div class="ds-card p-4">
                @if($apercuInitial['flux']['disponible'])
                    <p class="text-sm font-black text-slate-900">Dernière exécution connue</p>
                    <p class="mt-1 text-[11px] text-slate-500">{{ $apercuInitial['flux']['derniere_execution']['date'] }}</p>
                    <p class="mt-1 text-[11px] text-slate-400">Rôle acteur : {{ $apercuInitial['flux']['derniere_execution']['acteur_role'] ?? 'inconnu' }}{{ ($apercuInitial['flux']['derniere_execution']['acteur_compte_supprime'] ?? false) ? ' (compte supprimé)' : '' }}</p>
                @else
                    <p class="text-sm font-black text-slate-900">Flux vertical : inconnu</p>
                    <p class="mt-1 text-[11px] font-semibold text-amber-600">Aucune exécution mesurée — cette page ne déclenche aucun traitement.</p>
                @endif
            </div>
        </div>
        @if($apercuInitial['donnees_manquantes_signalees'])
            <p class="mt-3 rounded-xl bg-amber-50 px-4 py-2 text-sm text-amber-800" role="status">Certaines métriques sont indisponibles : l’absence de données n’est pas un état sain, elle est signalée comme telle.</p>
        @endif

        <div x-data="datameshObservabilite(@js(['graphe' => route('admin.datamesh.graphe'), 'apercu' => route('admin.datamesh.apercu'), 'flux' => route('admin.datamesh.flux'), 'qualite' => route('admin.datamesh.qualite'), 'outbox' => route('admin.datamesh.outbox'), 'noeud' => route('admin.datamesh.noeud', ['id' => '__ID__']), 'faculte_id' => $filtres['faculte_id'], 'annee_id' => $filtres['annee_id']]))" x-init="chargerGraphe()" class="mt-6">
            {{-- Barre supérieure : onglets, recherche, filtres, actualisation --}}
            <div class="flex flex-wrap items-center gap-2" role="tablist" aria-label="Vues d'observabilité">
                <template x-for="t in onglets" :key="t.id">
                    <button type="button" role="tab" :aria-selected="onglet === t.id" :aria-controls="'panneau-' + t.id"
                        @click="changerOnglet(t.id)"
                        :class="onglet === t.id ? 'bg-primary-800 text-white' : 'bg-white text-slate-700 hover:bg-slate-100'"
                        class="rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold" x-text="t.libelle"></button>
                </template>
                <span class="mx-1 hidden h-6 w-px bg-slate-200 sm:block" aria-hidden="true"></span>
                <input type="search" x-model="recherche" @input="appliquerFiltres()" placeholder="Rechercher (nom, domaine, version)…" aria-label="Rechercher un nœud" class="rounded-xl border border-slate-200 px-3 py-2 text-sm">
                <select x-model="filtreEtat" @change="appliquerFiltres()" aria-label="Filtrer par état" class="rounded-xl border border-slate-200 px-3 py-2 text-sm">
                    <option value="">Tous états</option>
                    <option value="operationnel">Opérationnel</option>
                    <option value="degrade">Dégradé</option>
                    <option value="erreur">Erreur</option>
                    <option value="non_verifie">Non vérifié</option>
                    <option value="inconnu">Inconnu</option>
                    <option value="non_mesure">Non mesuré</option>
                </select>
                <select x-model="filtreType" @change="appliquerFiltres()" aria-label="Filtrer par type" class="rounded-xl border border-slate-200 px-3 py-2 text-sm">
                    <option value="">Tous types</option>
                    <option value="domaine">Domaine</option>
                    <option value="produit">Produit</option>
                    <option value="controle">Contrôle</option>
                    <option value="flux">Flux</option>
                    <option value="outbox">Outbox</option>
                    <option value="consommateur">Consommateur</option>
                </select>
                <select x-model="rechargeAuto" @change="configurerRechargeAuto()" aria-label="Actualisation automatique" class="rounded-xl border border-slate-200 px-3 py-2 text-sm">
                    <option value="0">Actualisation : arrêt</option>
                    <option value="15">Toutes les 15 s</option>
                    <option value="30">Toutes les 30 s</option>
                    <option value="60">Toutes les 60 s</option>
                </select>
                <button type="button" @click="actualiser()" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" aria-label="Actualiser les données">Actualiser</button>
                <span x-show="perime" class="rounded-full bg-amber-100 px-2 py-1 text-[11px] font-bold text-amber-800" role="status">Données périmées (&gt; 5 min)</span>
            </div>

            {{-- Panneau Graphe --}}
            <div x-show="onglet === 'graphe'" id="panneau-graphe" role="tabpanel" class="mt-4 grid gap-4 lg:grid-cols-[1fr_320px]">
                <div class="ds-card overflow-hidden">
                    <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-4 py-2">
                        <button type="button" @click="zoom(1.25)" class="rounded-lg border px-2 py-1 text-sm" aria-label="Zoom avant">+</button>
                        <button type="button" @click="zoom(0.8)" class="rounded-lg border px-2 py-1 text-sm" aria-label="Zoom arrière">−</button>
                        <button type="button" @click="ajuster()" class="rounded-lg border px-2 py-1 text-sm">Ajuster à l’écran</button>
                        <button type="button" @click="reinitialiser()" class="rounded-lg border px-2 py-1 text-sm">Réinitialiser</button>
                        <span class="ml-auto text-[11px] text-slate-400" x-text="statutChargement"></span>
                    </div>
                    <div x-show="chargement" class="px-4 py-8 text-sm text-slate-500" role="status">Chargement du graphe…</div>
                    <div x-show="erreur" class="px-4 py-8 text-sm font-semibold text-red-700" role="alert">Échec du chargement (<span x-text="erreur"></span>). Aucun faux état sain affiché. <button type="button" @click="actualiser()" class="underline">Réessayer</button></div>
                    <div x-show="!chargement && !erreur && noeudsVisibles.length === 0" class="px-4 py-8 text-sm text-slate-500" role="status">Aucun nœud ne correspond aux filtres.</div>
                    <svg x-show="!chargement && !erreur" id="graphe-svg" viewBox="0 0 1000 560" class="h-[480px] w-full cursor-grab touch-none select-none" role="img" aria-label="Graphe du Data Mesh : nœuds et relations orientées"
                        @wheel.prevent="zoom($event.deltaY < 0 ? 1.1 : 0.9)" @pointerdown="debutDeplacement($event)" @pointermove="deplacer($event)" @pointerup="finDeplacement()" @pointerleave="finDeplacement()">
                        <defs>
                            <marker id="fleche" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                                <path d="M 0 1 L 9 5 L 0 9" fill="none" stroke="#64748b" stroke-width="1.5" />
                            </marker>
                            <marker id="fleche-inconnue" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                                <path d="M 0 1 L 9 5 L 0 9" fill="none" stroke="#94a3b8" stroke-width="1.5" stroke-dasharray="2 2" />
                            </marker>
                        </defs>
                        <g :transform="`translate(${position.x} ${position.y}) scale(${position.echelle})`">
                            <template x-for="r in relationsVisibles" :key="r.id">
                                <line :x1="positions[r.de]?.x" :y1="positions[r.de]?.y" :x2="positions[r.vers]?.x" :y2="positions[r.vers]?.y"
                                    :stroke="r.confirmation === 'confirmee' ? '#64748b' : '#cbd5e1'" :stroke-dasharray="r.confirmation === 'confirmee' ? 'none' : '6 4'"
                                    stroke-width="1.6" :marker-end="r.confirmation === 'confirmee' ? 'url(#fleche)' : 'url(#fleche-inconnue)'">
                                    <title x-text="r.libelle + ' — ' + r.confirmation + ' (' + r.source + ')'"></title>
                                </line>
                            </template>
                            <template x-for="n in noeudsVisibles" :key="n.id">
                                <g :transform="`translate(${positions[n.id]?.x || 0} ${(positions[n.id]?.y || 0) - 24})`" tabindex="0" role="button"
                                    :aria-label="n.libelle + ' — ' + n.etat" @click="selectionner(n.id)" @keydown.enter.prevent="selectionner(n.id)" @keydown.space.prevent="selectionner(n.id)"
                                    :opacity="n.correspond ? 1 : 0.25" class="cursor-pointer focus:outline-none">
                                    <rect x="-90" y="0" width="180" height="52" rx="10" :fill="couleurFond(n.etat)" :stroke="n.id === selection ? '#1e40af' : couleurBordure(n.etat)" :stroke-width="n.id === selection ? 3 : 1.6" :stroke-dasharray="etatPointille(n.etat) ? '5 4' : 'none'" />
                                    <text x="0" y="18" text-anchor="middle" font-size="11" font-weight="700" fill="#0f172a" x-text="n.libelle.length > 24 ? n.libelle.slice(0, 23) + '…' : n.libelle"><title x-text="n.libelle"></title></text>
                                    <text x="0" y="33" text-anchor="middle" font-size="10" fill="#475569" x-text="etiquetteNoeud(n)"></text>
                                    <text x="0" y="46" text-anchor="middle" font-size="9" font-weight="700" :fill="couleurTexte(n.etat)" x-text="n.etat.replace('_', ' ')"></text>
                                </g>
                            </template>
                        </g>
                    </svg>
                    <div class="flex flex-wrap gap-3 border-t border-slate-100 px-4 py-2 text-[11px] text-slate-600" aria-label="Légende">
                        <span><i class="mr-1 inline-block h-2.5 w-2.5 rounded-full bg-emerald-500"></i>Opérationnel</span>
                        <span><i class="mr-1 inline-block h-2.5 w-2.5 rounded-full bg-amber-500"></i>Dégradé</span>
                        <span><i class="mr-1 inline-block h-2.5 w-2.5 rounded-full bg-red-500"></i>Erreur</span>
                        <span><i class="mr-1 inline-block h-2.5 w-2.5 rounded-full border border-dashed border-slate-500 bg-slate-100"></i>Non vérifié / inconnu (jamais sain)</span>
                        <span><i class="mr-1 inline-block h-5 w-0 border-t-2 border-slate-500 align-middle"></i>Relation confirmée</span>
                        <span><i class="mr-1 inline-block h-0 w-5 border-t-2 border-dashed border-slate-300 align-middle"></i>Relation déclarée (non confirmée)</span>
                    </div>
                </div>
                <aside class="ds-card p-4" aria-label="Détails du nœud" aria-live="polite">
                    <div x-show="!selection" class="text-sm text-slate-500">Sélectionnez un nœud du graphe pour afficher le détail (contrat, dépendances, fraîcheur, accès).</div>
                    <div x-show="selection && chargementDetail" class="text-sm text-slate-500" role="status">Chargement du détail…</div>
                    <div x-show="detail">
                        <h3 class="text-base font-black text-slate-900" x-text="detail?.noeud?.libelle"></h3>
                        <p class="text-[11px] uppercase tracking-wider text-slate-500" x-text="(detail?.noeud?.type ?? '') + (detail?.contrat ? ' · ' + detail.contrat.code + ' · v' + detail.contrat.version + ' · ' + detail.contrat.domaine : '')"></p>
                        <p class="mt-2 text-sm text-slate-700" x-text="detail?.contrat?.description ?? detail?.noeud?.detail"></p>
                        <template x-if="detail?.contrat">
                            <dl class="mt-3 space-y-1 text-[12px]">
                                <div class="flex gap-2"><dt class="w-32 shrink-0 font-bold text-slate-500">Propriétaire</dt><dd x-text="detail.contrat.proprietaire_metier ?? '—'"></dd></div>
                                <div class="flex gap-2"><dt class="w-32 shrink-0 font-bold text-slate-500">Source vérité</dt><dd x-text="detail.contrat.source_verite ?? '—'"></dd></div>
                                <div class="flex gap-2"><dt class="w-32 shrink-0 font-bold text-slate-500">Classification</dt><dd x-text="detail.contrat.classification"></dd></div>
                                <div class="flex gap-2"><dt class="w-32 shrink-0 font-bold text-slate-500">Fraîcheur attendue</dt><dd x-text="detail.contrat.fraicheur_attendue ?? '—'"></dd></div>
                                <div class="flex gap-2"><dt class="w-32 shrink-0 font-bold text-slate-500">Dernière mesure</dt><dd x-text="detail.noeud.fraicheur ?? 'inconnue (non mesurée)'"></dd></div>
                                <div class="flex gap-2"><dt class="w-32 shrink-0 font-bold text-slate-500">État / origine</dt><dd x-text="(detail.noeud.etat ?? '—') + ' (origine : ' + (detail.noeud.origine_etat ?? '—') + ')'"></dd></div>
                                <div class="flex gap-2"><dt class="w-32 shrink-0 font-bold text-slate-500">Diagnostic</dt><dd x-text="detail.noeud.diagnostic ?? '—'"></dd></div>
                                <div class="flex gap-2"><dt class="w-32 shrink-0 font-bold text-slate-500">Dernier contrôle</dt><dd x-text="detail.noeud.dernier_controle ?? '—'"></dd></div>
                                <div class="flex gap-2"><dt class="w-32 shrink-0 font-bold text-slate-500">Sensibles / masquage</dt><dd x-text="(detail.noeud.donnees_sensibles ? 'champs sensibles présents, PII masquée' : 'aucun champ sensible')"></dd></div>
                                <div class="flex gap-2"><dt class="w-32 shrink-0 font-bold text-slate-500">Accès</dt><dd x-text="detail.regles_acces"></dd></div>
                            </dl>
                        </template>
                        <template x-if="detail?.contrat?.schema">
                            <details class="mt-3">
                                <summary class="cursor-pointer text-sm font-bold text-slate-700">Schéma du contrat (<span x-text="detail.contrat.schema.length"></span> champs)</summary>
                                <table class="mt-2 w-full text-[12px]">
                                    <thead><tr class="text-left text-slate-500"><th class="py-1">Champ</th><th>Type</th><th>Sensible</th></tr></thead>
                                    <tbody>
                                        <template x-for="c in detail.contrat.schema" :key="c.champ">
                                            <tr class="border-t border-slate-100"><td class="py-1 font-mono" x-text="c.champ" :title="c.signification"></td><td x-text="c.type"></td><td x-text="c.sensible ? 'oui (masqué)' : 'non'"></td></tr>
                                        </template>
                                    </tbody>
                                </table>
                            </details>
                        </template>
                        <div class="mt-3 text-[12px]">
                            <p class="font-bold text-slate-500">Dépendances entrantes (<span x-text="detail?.dependances_entrantes?.length ?? 0"></span>)</p>
                            <ul class="list-disc pl-5">
                                <template x-for="r in (detail?.dependances_entrantes ?? [])" :key="r.id">
                                    <li><span x-text="r.de"></span> — <span x-text="r.libelle"></span> (<span x-text="r.confirmation"></span>)</li>
                                </template>
                            </ul>
                            <p class="mt-2 font-bold text-slate-500">Dépendances sortantes (<span x-text="detail?.dependances_sortantes?.length ?? 0"></span>)</p>
                            <ul class="list-disc pl-5">
                                <template x-for="r in (detail?.dependances_sortantes ?? [])" :key="r.id">
                                    <li><span x-text="r.vers"></span> — <span x-text="r.libelle"></span> (<span x-text="r.confirmation"></span>)</li>
                                </template>
                            </ul>
                        </div>
                    </div>
                </aside>
            </div>

            {{-- Panneau Flux --}}
            <div x-show="onglet === 'flux'" id="panneau-flux" role="tabpanel" class="ds-card mt-4 p-5">
                <p class="text-[12px] text-slate-500">Lecture seule : aucun traitement métier déclenché par cette page. Un résultat vide n’est jamais un succès.</p>
                <div x-show="fluxDonnees === null" class="mt-2 text-sm text-slate-500" role="status">Chargement…</div>
                <ol x-show="fluxDonnees" class="mt-3 space-y-2">
                    <template x-for="e in (fluxDonnees?.etapes ?? [])" :key="e.ordre">
                        <li class="flex items-start gap-3 rounded-xl border border-slate-100 p-3">
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-[12px] font-black" :class="pastilleEtat(e.etat)" x-text="e.ordre"></span>
                            <div>
                                <p class="text-sm font-bold text-slate-900"><span x-text="e.libelle"></span> <span class="font-normal text-slate-500" x-show="e.produit" x-text="'· ' + e.produit + (e.version ? ' v' + e.version : '')"></span></p>
                                <p class="text-[12px]" :class="e.disponible ? 'text-slate-600' : 'text-amber-700 font-semibold'"><span x-text="e.etat"></span> — <span x-text="e.note"></span></p>
                                <p class="text-[11px] text-slate-400" x-show="e.date_reference" x-text="'Réf. ' + e.date_reference"></p>
                            </div>
                        </li>
                    </template>
                </ol>
            </div>

            {{-- Panneau Qualité --}}
            <div x-show="onglet === 'qualite'" id="panneau-qualite" role="tabpanel" class="ds-card mt-4 p-5">
                <div class="flex flex-wrap gap-2">
                    <select x-model="filtreGravite" aria-label="Filtrer par gravité" class="rounded-xl border border-slate-200 px-3 py-2 text-sm">
                        <option value="">Toutes gravités</option>
                        <option value="bloquant">Bloquant</option>
                        <option value="avertissement">Avertissement</option>
                        <option value="info">Info</option>
                    </select>
                </div>
                <div x-show="qualiteDonnees === null" class="mt-2 text-sm text-slate-500" role="status">Chargement…</div>
                <table x-show="qualiteDonnees" class="mt-3 w-full text-sm">
                    <thead><tr class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500"><th class="px-3 py-2">Règle</th><th class="px-3 py-2">Gravité</th><th class="px-3 py-2 text-right">Violations</th><th class="px-3 py-2">Unité</th><th class="px-3 py-2">Exemples anonymisés</th></tr></thead>
                    <tbody>
                        <template x-for="r in qualiteFiltree()" :key="r.regle">
                            <tr class="border-t border-slate-100">
                                <td class="px-3 py-2 font-mono text-[12px]" x-text="r.regle"></td>
                                <td class="px-3 py-2"><span class="rounded-full px-2 py-0.5 text-[11px] font-bold" :class="pastilleGravite(r.gravite)" x-text="r.gravite"></span></td>
                                <td class="px-3 py-2 text-right tabular-nums" x-text="r.violations ?? r.anomalies"></td>
                                <td class="px-3 py-2 text-[12px] text-slate-500" x-text="r.unite_comptage ?? 'cas'"></td>
                                <td class="px-3 py-2 text-[12px] text-slate-600" x-text="(r.exemples || []).slice(0, 3).join(' · ') || '—'"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <p class="mt-2 text-[11px] text-slate-400" x-show="qualiteDonnees">Contrôle du <span x-text="qualiteDonnees?.date_controle"></span> — total <span x-text="qualiteDonnees?.total_violations"></span> violation(s), sans déduplication transverse (<span x-text="qualiteDonnees?.note_deduplication"></span>). Exemples anonymisés ou agrégés — aucune donnée individuelle. Aucune correction automatique.</p>
            </div>

            {{-- Panneau Outbox --}}
            <div x-show="onglet === 'outbox'" id="panneau-outbox" role="tabpanel" class="ds-card mt-4 p-5">
                <p class="text-[12px] text-slate-500">Lecture seule : republication, purge et relance non exposées dans cette version (autorisation dédiée + confirmation + audit requis).</p>
                <div x-show="outboxDonnees === null" class="mt-2 text-sm text-slate-500" role="status">Chargement…</div>
                <div x-show="outboxDonnees">
                    <p class="mt-2 text-sm text-slate-700"><span class="font-black tabular-nums" x-text="outboxDonnees?.compteurs?.en_attente"></span> en attente · <span class="font-black tabular-nums" x-text="outboxDonnees?.compteurs?.echecs"></span> en échec · <span class="font-black tabular-nums" x-text="outboxDonnees?.compteurs?.publies"></span> publiés. <span x-text="outboxDonnees?.note_livraison"></span></p>
                    <p class="mt-1 rounded-xl bg-slate-100 px-3 py-2 text-[12px] font-semibold text-slate-700">Automatisation : <span x-text="outboxDonnees?.automatisation?.etat"></span> — <span x-text="outboxDonnees?.automatisation?.note"></span></p>
                    <p class="mt-1 text-[12px] text-slate-500">Dernière exécution publisher : <span x-text="outboxDonnees?.automatisation?.derniere_execution_publisher ?? 'non mesurée (aucun stockage)'"></span> · Dernier succès : <span x-text="outboxDonnees?.automatisation?.dernier_succes_confirme ?? 'non mesuré'"></span> · Prochain : <span x-text="outboxDonnees?.automatisation?.prochain_declenchement ?? 'aucun'"></span></p>
                    <p class="text-[12px] text-slate-500">Rétention : <span x-text="outboxDonnees?.retention?.jours"></span> jours. <span x-text="outboxDonnees?.retention?.note"></span></p>
                    <table class="mt-3 w-full text-sm">
                        <thead><tr class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500"><th class="px-3 py-2">Événement</th><th class="px-3 py-2">Statut</th><th class="px-3 py-2 text-right">Tentatives</th><th class="px-3 py-2 text-right">Ancienneté (h)</th><th class="px-3 py-2">Dernière erreur (expurgée)</th></tr></thead>
                        <tbody>
                            <template x-for="m in (outboxDonnees?.messages ?? [])" :key="m.id">
                                <tr class="border-t border-slate-100">
                                    <td class="px-3 py-2 font-mono text-[12px]" x-text="m.type_evenement + ' / ' + m.type_agregat + '#' + m.agregat_id"></td>
                                    <td class="px-3 py-2" x-text="m.statut"></td>
                                    <td class="px-3 py-2 text-right tabular-nums" x-text="m.tentatives"></td>
                                    <td class="px-3 py-2 text-right tabular-nums" x-text="m.anciennete_heures"></td>
                                    <td class="px-3 py-2 text-[12px] text-slate-600" x-text="m.derniere_erreur ?? '—'"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
    function datameshObservabilite(config) {
        return {
            onglets: [
                { id: 'graphe', libelle: 'Graphe' },
                { id: 'flux', libelle: 'Flux' },
                { id: 'qualite', libelle: 'Qualité' },
                { id: 'outbox', libelle: 'Outbox' },
            ],
            onglet: 'graphe',
            graphe: null, noeudsVisibles: [], relationsVisibles: [], positions: {},
            position: { x: 0, y: 0, echelle: 1 },
            selection: null, detail: null, chargementDetail: false,
            fluxDonnees: null, qualiteDonnees: null, outboxDonnees: null,
            chargement: false, erreur: null, statutChargement: '', perime: false,
            recherche: '', filtreEtat: '', filtreType: '', filtreGravite: '',
            rechargeAuto: '0', minuteur: null, deplacement: null,
            parametres() {
                const p = new URLSearchParams();
                if (config.faculte_id) p.set('faculte_id', config.faculte_id);
                if (config.annee_id) p.set('annee_id', config.annee_id);
                const q = p.toString();
                return q ? '?' + q : '';
            },
            async chargerGraphe() {
                this.chargement = true; this.erreur = null; this.statutChargement = 'Chargement…';
                try {
                    const r = await fetch(config.graphe + this.parametres(), { headers: { 'Accept': 'application/json' } });
                    if (!r.ok) throw new Error(r.status === 403 ? 'accès refusé (hors périmètre)' : 'HTTP ' + r.status);
                    this.graphe = await r.json();
                    this.verifierFraicheur();
                    this.mettreEnPage();
                    this.appliquerFiltres();
                    this.statutChargement = (this.graphe.noeuds?.length || 0) + ' nœuds · ' + (this.graphe.relations?.length || 0) + ' relations';
                } catch (e) { this.erreur = e.message; this.statutChargement = ''; }
                finally { this.chargement = false; }
            },
            verifierFraicheur() {
                if (!this.graphe?.genere_le) { this.perime = true; return; }
                this.perime = (Date.now() - new Date(this.graphe.genere_le).getTime()) > 5 * 60 * 1000;
            },
            mettreEnPage() {
                const colonnes = { domaine: 90, produit: 330, controle: 620, flux: 620, outbox: 620, consommateur: 870 };
                const compteurs = {};
                this.positions = {};
                for (const n of (this.graphe.noeuds || [])) {
                    const col = colonnes[n.type] ?? 500;
                    const rang = compteurs[n.type] = (compteurs[n.type] || 0) + 1;
                    this.positions[n.id] = { x: col, y: 50 + (rang - 1) * 78 };
                }
            },
            appliquerFiltres() {
                if (!this.graphe) return;
                const q = this.recherche.trim().toLowerCase();
                const ids = new Set();
                this.noeudsVisibles = (this.graphe.noeuds || []).map((n) => {
                    const texte = (n.libelle + ' ' + (n.code || '') + ' ' + (n.domaine || '') + ' ' + (n.version || '')).toLowerCase();
                    const ok = (!q || texte.includes(q)) && (!this.filtreEtat || n.etat === this.filtreEtat) && (!this.filtreType || n.type === this.filtreType);
                    if (ok) ids.add(n.id);
                    return { ...n, correspond: ok };
                }).filter((n) => n.correspond);
                this.relationsVisibles = (this.graphe.relations || []).filter((r) => ids.has(r.de) && ids.has(r.vers));
            },
            async selectionner(id) {
                this.selection = id; this.chargementDetail = true; this.detail = null;
                try {
                    const r = await fetch(config.noeud.replace('__ID__', encodeURIComponent(id)) + this.parametres(), { headers: { 'Accept': 'application/json' } });
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    this.detail = await r.json();
                } catch (e) { this.detail = { noeud: { libelle: id, type: '?', etat: 'inconnu', detail: 'Détail indisponible (' + e.message + ').' }, dependances_entrantes: [], dependances_sortantes: [] }; }
                finally { this.chargementDetail = false; }
            },
            async chargerOnglet() {
                const q = this.parametres();
                const appel = async (url) => { const r = await fetch(url + q, { headers: { 'Accept': 'application/json' } }); if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); };
                try {
                    if (this.onglet === 'flux' && !this.fluxDonnees) this.fluxDonnees = await appel(config.flux);
                    if (this.onglet === 'qualite' && !this.qualiteDonnees) this.qualiteDonnees = await appel(config.qualite);
                    if (this.onglet === 'outbox' && !this.outboxDonnees) this.outboxDonnees = await appel(config.outbox);
                } catch (e) { /* onglet en erreur : aucun faux sain, tableau vide */ }
            },
            changerOnglet(id) { this.onglet = id; this.chargerOnglet(); },
            async actualiser() {
                this.fluxDonnees = null; this.qualiteDonnees = null; this.outboxDonnees = null;
                await this.chargerGraphe();
                await this.chargerOnglet();
                if (this.selection) await this.selectionner(this.selection);
            },
            configurerRechargeAuto() {
                if (this.minuteur) { clearInterval(this.minuteur); this.minuteur = null; }
                const s = parseInt(this.rechargeAuto, 10);
                if (s > 0) this.minuteur = setInterval(() => this.actualiser(), s * 1000);
            },
            zoom(f) { this.position.echelle = Math.min(2.5, Math.max(0.4, this.position.echelle * f)); },
            ajuster() { this.position = { x: 0, y: 0, echelle: 1 }; },
            reinitialiser() { this.position = { x: 0, y: 0, echelle: 1 }; this.recherche = ''; this.filtreEtat = ''; this.filtreType = ''; this.appliquerFiltres(); },
            debutDeplacement(e) { this.deplacement = { x: e.clientX - this.position.x, y: e.clientY - this.position.y }; e.currentTarget.setPointerCapture(e.pointerId); },
            deplacer(e) { if (this.deplacement) { this.position.x = e.clientX - this.deplacement.x; this.position.y = e.clientY - this.deplacement.y; } },
            finDeplacement() { this.deplacement = null; },
            couleurFond(etat) { return { operationnel: '#ecfdf5', degrade: '#fffbeb', erreur: '#fef2f2', non_verifie: '#f8fafc', inconnu: '#f8fafc', non_mesure: '#f1f5f9' }[etat] || '#f8fafc'; },
            couleurBordure(etat) { return { operationnel: '#10b981', degrade: '#f59e0b', erreur: '#ef4444', non_verifie: '#94a3b8', inconnu: '#94a3b8', non_mesure: '#cbd5e1' }[etat] || '#94a3b8'; },
            couleurTexte(etat) { return { operationnel: '#047857', degrade: '#b45309', erreur: '#b91c1c' }[etat] || '#64748b'; },
            etatPointille(etat) { return ['non_verifie', 'inconnu', 'non_mesure'].includes(etat); },
            etiquetteNoeud(n) { if (n.type === 'produit') return n.domaine + ' · v' + (n.version || '?'); return n.type; },
            pastilleEtat(etat) { return { terminee: 'bg-emerald-100 text-emerald-800', terminee_sans_resultat: 'bg-amber-100 text-amber-800', inconnue: 'bg-slate-200 text-slate-600', non_executee: 'bg-slate-200 text-slate-600' }[etat] || 'bg-slate-200 text-slate-600'; },
            pastilleGravite(g) { return { bloquant: 'bg-red-100 text-red-800', avertissement: 'bg-amber-100 text-amber-800', info: 'bg-slate-200 text-slate-600' }[g] || 'bg-slate-200 text-slate-600'; },
            qualiteFiltree() { return (this.qualiteDonnees?.resultats || []).filter((r) => !this.filtreGravite || r.gravite === this.filtreGravite); },
        };
    }
    </script>
@endsection
