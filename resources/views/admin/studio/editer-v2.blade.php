@extends('layouts.app')

@section('title', 'Studio e-mail — builder visuel')

@section('content')
<div class="mx-auto max-w-[110rem] px-4 sm:px-6 py-6" x-data="editeurStudioV2(@js($schema), {{ $baseVersion }}, '{{ route('admin.studio.enregistrer', $modele) }}', '{{ route('admin.studio.apercu', $modele) }}', @js($squelettes))" x-init="initEditeur()">
    {{-- Barre supérieure --}}
    <div class="rounded-2xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('admin.studio.voir', $modele) }}" class="text-base font-black text-slate-900 hover:underline">{{ $modele->code }}</a>
            <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-bold">{{ $modele->statut }}</span>
            <span class="text-xs text-slate-500">v<span x-text="baseVersion"></span></span>
            <span x-show="sale" class="text-xs font-bold text-amber-700" role="status">● Non enregistré</span>
            <span x-show="message" class="text-xs font-bold" :class="messageOk ? 'text-emerald-700' : 'text-rose-700'" role="status" x-text="message"></span>
            <span class="ml-auto flex flex-wrap gap-1.5" role="toolbar" aria-label="Édition">
                <button type="button" @click="annuler()" :disabled="!peutAnnuler" title="Annuler (Ctrl+Z)" class="rounded-xl border px-3 py-2 text-xs font-bold disabled:opacity-40">↩</button>
                <button type="button" @click="retablir()" :disabled="!peutRetablir" title="Rétablir (Ctrl+Y)" class="rounded-xl border px-3 py-2 text-xs font-bold disabled:opacity-40">↪</button>
                <button type="button" @click="rafraichirApercu(true)" class="rounded-xl border px-3 py-2 text-xs font-bold">Aperçu</button>
                <button type="button" @click="sauvegarder()" :disabled="!sale || sauvegarde" title="Sauvegarder (Ctrl+S)" class="rounded-xl bg-primary-600 px-4 py-2 text-xs font-bold text-white disabled:opacity-40">
                    <span x-show="!sauvegarde">Sauvegarder</span><span x-show="sauvegarde">…</span>
                </button>
            </span>
        </div>
        <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
            <label class="sr-only" for="note-v2">Note de version</label>
            <input id="note-v2" x-model="note" placeholder="Note de version (optionnel)" maxlength="255" class="rounded-xl border border-slate-200 px-3 py-1.5 text-xs">
            <span class="text-slate-400">Raccourcis : Ctrl+S sauver · Ctrl+Z/Y annuler/rétablir · Alt+↑/↓ déplacer la sélection</span>
        </div>
    </div>

    <div x-show="qualite.erreurs.length || qualite.avertissements.length" class="mt-3 rounded-2xl border p-4" :class="qualite.erreurs.length ? 'border-rose-300 bg-rose-50' : 'border-amber-300 bg-amber-50'">
        <p class="text-xs font-black uppercase tracking-wider" :class="qualite.erreurs.length ? 'text-rose-900' : 'text-amber-900'">Contrôle qualité avant publication</p>
        <ul class="mt-2 space-y-1 text-xs">
            <template x-for="e in qualite.erreurs" :key="'e'+e.code">
                <li class="text-rose-900"><strong x-text="e.code"></strong> — <span x-text="e.message"></span> <em x-text="e.correction"></em></li>
            </template>
            <template x-for="a in qualite.avertissements" :key="'a'+a.code">
                <li class="text-amber-900"><strong x-text="a.code"></strong> — <span x-text="a.message"></span> <em x-text="a.correction"></em></li>
            </template>
        </ul>
    </div>

    <div class="mt-3 grid grid-cols-1 lg:grid-cols-12 gap-3">
        {{-- Bibliothèque gauche --}}
        <aside class="lg:col-span-2 rounded-2xl border border-slate-200 bg-white p-3" aria-label="Bibliothèque de blocs">
            <h2 class="text-xs font-black uppercase tracking-wider text-slate-500">Blocs</h2>
            <div class="mt-2 grid grid-cols-2 lg:grid-cols-1 gap-1.5">
                <template x-for="[type, libelle, aide] in BIBLIOTHEQUE" :key="type">
                    <button type="button" @click="ajouterBloc(type)" :title="aide" class="rounded-xl border border-dashed border-slate-300 px-2 py-2 text-left text-xs font-bold hover:border-primary-500">
                        + <span x-text="libelle"></span>
                    </button>
                </template>
            </div>
            <h2 class="mt-4 text-xs font-black uppercase tracking-wider text-slate-500">Squelettes</h2>
            <div class="mt-2 space-y-1.5">
                <template x-for="nom in Object.keys(squelettes)" :key="nom">
                    <button type="button" @click="appliquerSquelette(nom)" class="w-full rounded-xl border border-slate-200 px-2 py-1.5 text-left text-xs font-bold hover:bg-slate-50">⧉ <span x-text="nom"></span></button>
                </template>
            </div>
            <h2 class="mt-4 text-xs font-black uppercase tracking-wider text-slate-500">Favoris (cet appareil)</h2>
            <button type="button" @click="memoriserFavori()" :disabled="selection === null" class="mt-2 w-full rounded-xl border border-slate-200 px-2 py-1.5 text-xs font-bold disabled:opacity-40">★ Mémoriser la sélection</button>
            <ul class="mt-2 space-y-1.5">
                <template x-for="(f, i) in favoris" :key="i">
                    <li class="flex items-center gap-1 rounded-xl bg-slate-50 border border-slate-200 px-2 py-1.5 text-xs">
                        <button type="button" @click="insererFavori(i)" class="flex-1 text-left font-bold" x-text="f.nom + ' (' + f.bloc.type + ')'"></button>
                        <button type="button" @click="retirerFavori(i)" aria-label="Retirer le favori" class="text-rose-600 font-bold">✕</button>
                    </li>
                </template>
            </ul>
        </aside>

        {{-- Canvas central --}}
        <main class="lg:col-span-6 rounded-2xl border border-slate-200 bg-slate-50 p-3" aria-label="Canevas">
            <div class="mb-2 rounded-xl bg-white border border-slate-200 p-3">
                <label for="v2-objet" class="block text-xs font-bold uppercase tracking-wider text-slate-500">Objet *</label>
                <input id="v2-objet" x-model="meta.objet" @input="toucher()" @focus="memoriserChamp($el)" maxlength="150" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm font-bold">
                <label for="v2-preheader" class="mt-2 block text-xs font-bold uppercase tracking-wider text-slate-500">Pré-en-tête</label>
                <input id="v2-preheader" x-model="meta.preheader" @input="toucher()" @focus="memoriserChamp($el)" maxlength="150" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-600">
            </div>
            <div class="rounded-xl bg-white border border-slate-200 p-3 space-y-2" @keydown.window="raccourcis($event)">
                <template x-for="(bloc, i) in blocs" :key="bloc._cle">
                    <div @click="selection = i"
                         :class="selection === i ? 'border-primary-600 ring-2 ring-primary-500/20' : 'border-slate-200'"
                         class="rounded-xl border-2 bg-white p-2 cursor-pointer"
                         draggable="true" @dragstart="glisser = i" @dragover.prevent="survole = i" @dragleave="survole = null"
                         @drop.prevent="deposer(i)" @dragend="glisser = null; survole = null"
                         :aria-label="'Bloc ' + (i + 1) + ' : ' + bloc.type" role="button" tabindex="0"
                         @keydown.enter="selection = i">
                        <div class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-wider text-slate-400">
                            <span x-text="(i + 1) + ' · ' + bloc.type"></span>
                            <span x-show="bloc.verrouille" class="text-amber-600">🔒</span>
                            <span class="ml-auto flex gap-1" @click.stop>
                                <button type="button" @click="monter(i)" :disabled="i === 0 || bloc.verrouille" aria-label="Monter" class="rounded border px-1.5 disabled:opacity-40">↑</button>
                                <button type="button" @click="descendre(i)" :disabled="i === blocs.length - 1 || bloc.verrouille" aria-label="Descendre" class="rounded border px-1.5 disabled:opacity-40">↓</button>
                                <button type="button" @click="dupliquerBloc(i)" aria-label="Dupliquer" class="rounded border px-1.5">⧉</button>
                                <button type="button" @click="demanderSuppression(i)" :disabled="bloc.verrouille" aria-label="Supprimer" class="rounded border border-rose-300 px-1.5 text-rose-600 disabled:opacity-40">✕</button>
                            </span>
                        </div>
                        <div class="mt-1 text-sm text-slate-700" x-html="miniature(bloc)"></div>
                    </div>
                </template>
                <p x-show="!blocs.length" class="py-8 text-center text-sm text-slate-400">Canvas vide — ajoutez des blocs depuis la bibliothèque ou un squelette.</p>
            </div>
        </main>

        {{-- Propriétés droite --}}
        <aside class="lg:col-span-4 rounded-2xl border border-slate-200 bg-white p-3" aria-label="Propriétés">
            <div x-show="selection === null" class="text-sm text-slate-400">Sélectionnez un bloc du canevas pour régler ses propriétés.</div>
            <div x-show="selection !== null" class="space-y-3 text-sm">
                <h2 class="text-xs font-black uppercase tracking-wider text-slate-500">Bloc <span x-text="selection !== null ? selection + 1 : ''"></span> — <span x-text="blocActif() ? blocActif().type : ''"></span></h2>
                <template x-if="blocActif()">
                    <div class="space-y-2" @focusin="if ($event.target.matches('input,textarea,select')) memoriserChamp($event.target)">
                        <template x-if="['titre','texte','bouton','image','liste'].includes(blocActif().type)">
                            <label class="block text-xs font-bold">Alignement
                                <select x-model="blocActif().alignement" @change="toucher()" :disabled="blocActif().verrouille" class="mt-1 block w-full rounded-xl border px-2 py-1.5">
                                    <option value="gauche">Gauche</option><option value="centre">Centre</option><option value="droite">Droite</option>
                                </select>
                            </label>
                        </template>
                        <template x-if="blocActif().type === 'titre'">
                            <div class="space-y-2">
                                <label class="block text-xs font-bold">Texte *<input x-model="blocActif().texte" @input="toucher()" :disabled="blocActif().verrouille" maxlength="200" class="mt-1 block w-full rounded-xl border px-3 py-2"></label>
                                <label class="block text-xs font-bold">Niveau
                                    <select x-model="blocActif().niveau" @change="toucher()" :disabled="blocActif().verrouille" class="mt-1 block w-full rounded-xl border px-2 py-1.5"><option value="h1">H1</option><option value="h2">H2</option><option value="h3">H3</option></select>
                                </label>
                            </div>
                        </template>
                        <template x-if="blocActif().type === 'texte'">
                            <label class="block text-xs font-bold">Contenu *<textarea x-model="blocActif().contenu" @input="toucher()" :disabled="blocActif().verrouille" rows="5" maxlength="5000" class="mt-1 block w-full rounded-xl border px-3 py-2"></textarea></label>
                        </template>
                        <template x-if="blocActif().type === 'bouton'">
                            <div class="grid grid-cols-1 gap-2">
                                <label class="block text-xs font-bold">Libellé *<input x-model="blocActif().libelle" @input="toucher()" :disabled="blocActif().verrouille" maxlength="80" class="mt-1 block w-full rounded-xl border px-3 py-2"></label>
                                <label class="block text-xs font-bold">URL (https/mailto/{{ '{' }}url_action{{ '}' }}) *<input x-model="blocActif().url" @input="toucher()" :disabled="blocActif().verrouille" maxlength="500" class="mt-1 block w-full rounded-xl border px-3 py-2 font-mono text-xs"></label>
                            </div>
                        </template>
                        <template x-if="blocActif().type === 'image'">
                            <div class="grid grid-cols-1 gap-2">
                                <label class="block text-xs font-bold">URL (https, jpg/png/gif/webp) *<input x-model="blocActif().url" @input="toucher()" :disabled="blocActif().verrouille" maxlength="500" class="mt-1 block w-full rounded-xl border px-3 py-2 font-mono text-xs"></label>
                                <label class="block text-xs font-bold">Texte alternatif *<input x-model="blocActif().texte_alt" @input="toucher()" :disabled="blocActif().verrouille" maxlength="200" class="mt-1 block w-full rounded-xl border px-3 py-2"></label>
                                <label class="block text-xs font-bold">Largeur (100–600)<input type="number" x-model.number="blocActif().largeur" @input="toucher()" :disabled="blocActif().verrouille" min="100" max="600" class="mt-1 block w-28 rounded-xl border px-2 py-1.5"></label>
                            </div>
                        </template>
                        <template x-if="blocActif().type === 'separateur'">
                            <label class="block text-xs font-bold">Style
                                <select x-model="blocActif().style" @change="toucher()" :disabled="blocActif().verrouille" class="mt-1 block w-full rounded-xl border px-2 py-1.5"><option value="fin">Fin</option><option value="epais">Épais</option><option value="pointille">Pointillé</option></select>
                            </label>
                        </template>
                        <template x-if="blocActif().type === 'liste'">
                            <div class="space-y-1">
                                <template x-for="(el, j) in blocActif().elements" :key="j">
                                    <div class="flex gap-1">
                                        <input x-model="blocActif().elements[j]" @input="toucher()" :disabled="blocActif().verrouille" maxlength="200" class="flex-1 rounded-xl border px-3 py-1.5">
                                        <button type="button" @click="blocActif().elements.splice(j, 1); toucher()" :disabled="blocActif().verrouille" aria-label="Retirer" class="rounded-lg border px-2 text-xs">✕</button>
                                    </div>
                                </template>
                                <button type="button" @click="if (blocActif().elements.length < 20) { blocActif().elements.push(''); toucher(); }" :disabled="blocActif().verrouille" class="text-xs font-bold text-primary-800">+ Élément</button>
                            </div>
                        </template>
                        <template x-if="blocActif().type === 'espaceur'">
                            <label class="block text-xs font-bold">Hauteur (8–80)<input type="number" x-model.number="blocActif().hauteur" @input="toucher()" :disabled="blocActif().verrouille" min="8" max="80" class="mt-1 block w-28 rounded-xl border px-2 py-1.5"></label>
                        </template>
                        <template x-if="blocActif().type === 'colonnes'">
                            <p class="text-xs text-slate-500">Deux colonnes de sous-blocs simples (titre, texte, bouton, image, liste, séparateur, espaceur). Gérez-les depuis le canevas ci-dessous.</p>
                            <template x-for="(col, c) in blocActif().colonnes" :key="c">
                                <div class="rounded-xl border border-slate-200 p-2">
                                    <p class="text-xs font-black">Colonne <span x-text="c + 1"></span></p>
                                    <template x-for="(sb, s) in col" :key="s">
                                        <p class="text-xs font-mono text-slate-600"><span x-text="(s + 1) + ' · ' + sb.type + ' — ' + (sb.texte || sb.contenu || sb.libelle || sb.url || '')"></span></p>
                                    </template>
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        <template x-for="t in ['titre','texte','bouton','image','liste','separateur','espaceur']" :key="t">
                                            <button type="button" @click="ajouterSousBloc(c, t)" :disabled="col.length >= 6 || blocActif().verrouille" class="rounded-lg border px-1.5 py-0.5 text-[11px] disabled:opacity-40">+ <span x-text="t"></span></button>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </template>
                        <template x-if="blocActif().type === 'carte'">
                            <div class="space-y-2">
                                <label class="block text-xs font-bold">Titre *<input x-model="blocActif().titre" @input="toucher()" :disabled="blocActif().verrouille" maxlength="120" class="mt-1 block w-full rounded-xl border px-3 py-2"></label>
                                <label class="block text-xs font-bold">Texte *<textarea x-model="blocActif().texte" @input="toucher()" :disabled="blocActif().verrouille" rows="3" maxlength="2000" class="mt-1 block w-full rounded-xl border px-3 py-2"></textarea></label>
                                <label class="block text-xs font-bold">Couleur de bordure (hex, vide = accent)<input x-model="blocActif().couleur_bordure" @input="toucher()" :disabled="blocActif().verrouille" maxlength="7" placeholder="#4f46e5" class="mt-1 block w-full rounded-xl border px-3 py-2 font-mono"></label>
                            </div>
                        </template>
                        <template x-if="blocActif().type === 'tableau'">
                            <p class="text-xs text-slate-500">Colonnes : <span x-text="blocActif().entetes.length"></span> · Lignes : <span x-text="blocActif().lignes.length"></span> — édition cellule par cellule ci-dessous (texte brut, variables autorisées).</p>
                            <div class="space-y-1">
                                <template x-for="(h, j) in blocActif().entetes" :key="'h'+j">
                                    <input x-model="blocActif().entetes[j]" @input="toucher()" :disabled="blocActif().verrouille" maxlength="60" :placeholder="'En-tête ' + (j + 1)" class="w-full rounded-xl border px-3 py-1.5 font-bold">
                                </template>
                                <template x-for="(ligne, r) in blocActif().lignes" :key="'r'+r">
                                    <div class="flex gap-1">
                                        <template x-for="(cellule, k) in ligne" :key="'c'+k">
                                            <input x-model="blocActif().lignes[r][k]" @input="toucher()" :disabled="blocActif().verrouille" maxlength="100" class="flex-1 min-w-0 rounded-xl border px-2 py-1.5 text-xs">
                                        </template>
                                        <button type="button" @click="blocActif().lignes.splice(r, 1); toucher()" :disabled="blocActif().verrouille" aria-label="Retirer la ligne" class="rounded-lg border px-2 text-xs">✕</button>
                                    </div>
                                </template>
                                <button type="button" @click="ajouterLigneTableau()" :disabled="blocActif().lignes.length >= 20 || blocActif().verrouille" class="text-xs font-bold text-primary-800">+ Ligne</button>
                            </div>
                        </template>
                        <template x-if="blocActif().type === 'signature'">
                            <label class="block text-xs font-bold">Texte (vide = signature du thème)<textarea x-model="blocActif().texte" @input="toucher()" :disabled="blocActif().verrouille" rows="3" maxlength="500" class="mt-1 block w-full rounded-xl border px-3 py-2"></textarea></label>
                        </template>
                        <div class="grid grid-cols-2 gap-2">
                            <label class="block text-xs font-bold">Identifiant
                                <input x-model="blocActif().id" @input="toucher()" maxlength="32" placeholder="optionnel" class="mt-1 block w-full rounded-xl border px-2 py-1.5 font-mono text-xs">
                            </label>
                            <label class="flex items-end gap-1.5 text-xs font-bold pb-2">
                                <input type="checkbox" x-model="blocActif().verrouille" @change="toucher()" class="h-4 w-4"> Verrouiller
                            </label>
                        </div>
                        <div>
                            <span class="block text-xs font-bold uppercase tracking-wider text-slate-500">Variables</span>
                            <div class="mt-1 flex flex-wrap gap-1">
                                @foreach($variables as $nom => $definition)
                                    <button type="button" @click="insererVariable('{{ $nom }}')" title="{{ $definition['description'] }}" class="rounded-lg bg-slate-100 border border-slate-200 px-1.5 py-0.5 font-mono text-[11px]">{!! '{{' . $nom . '}}' !!}</button>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </template>
            </div>
            <div class="mt-3 rounded-2xl border border-slate-200 p-3">
                <h2 class="text-xs font-black uppercase tracking-wider text-slate-500">Thème</h2>
                <label class="mt-2 block text-xs font-bold">Appliquer un thème existant
                    <select @change="appliquerTheme($event.target.value)" class="mt-1 block w-full rounded-xl border px-2 py-1.5">
                        <option value="">— Thème inline actuel —</option>
                        @foreach($themes as $theme)
                            <option value="{{ $theme->id }}">{{ $theme->nom }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="mt-2 grid grid-cols-2 gap-2 text-xs font-bold">
                    <template x-for="cle in ['couleur_principale','couleur_accent','fond','fond_contenu']" :key="cle">
                        <label><span x-text="cle"></span><input type="color" x-model="theme[cle]" @input="toucher()" class="mt-1 block h-8 w-full rounded-lg border"></label>
                    </template>
                </div>
                <div class="mt-2 grid grid-cols-2 gap-2 text-xs font-bold">
                    <label>Police
                        <select x-model="theme.police" @change="toucher()" class="mt-1 block w-full rounded-xl border px-2 py-1.5">
                            <option value="arial">Arial</option><option value="georgia">Georgia</option><option value="systeme">Système</option>
                        </select>
                    </label>
                    <label>Boutons
                        <select x-model="theme.style_bouton" @change="toucher()" class="mt-1 block w-full rounded-xl border px-2 py-1.5">
                            <option value="arrondi">Arrondis</option><option value="angle">Angles</option>
                        </select>
                    </label>
                    <label>Largeur (480–800)<input type="number" x-model.number="theme.largeur" @input="toucher()" min="480" max="800" class="mt-1 block w-full rounded-xl border px-2 py-1.5"></label>
                    <label>Logo (https)<input x-model="theme.logo_url" @input="toucher()" maxlength="500" class="mt-1 block w-full rounded-xl border px-2 py-1.5 font-mono text-[11px]"></label>
                </div>
                <label class="mt-2 block text-xs font-bold">Établissement<input x-model="theme.nom_etablissement" @input="toucher()" maxlength="120" class="mt-1 block w-full rounded-xl border px-2 py-1.5"></label>
                <label class="mt-2 block text-xs font-bold">Signature<textarea x-model="theme.signature" @input="toucher()" rows="2" maxlength="500" class="mt-1 block w-full rounded-xl border px-2 py-1.5"></textarea></label>
                <label class="mt-2 block text-xs font-bold">Mentions<textarea x-model="theme.mentions" @input="toucher()" rows="2" maxlength="500" class="mt-1 block w-full rounded-xl border px-2 py-1.5"></textarea></label>
            </div>
        </aside>
    </div>

    {{-- Aperçu bas de page (pleine largeur, responsive) --}}
    <div class="mt-3 rounded-2xl border border-slate-200 bg-white p-3">
        <div class="flex flex-wrap items-center gap-2">
            <h2 class="text-sm font-black">Aperçu isolé (données fictives)</h2>
            <span class="flex gap-1" role="group" aria-label="Largeur">
                <button type="button" @click="largeurApercu = '100%'" class="rounded-lg border px-2 py-1 text-xs font-bold" :class="largeurApercu === '100%' ? 'bg-slate-900 text-white' : ''">Bureau</button>
                <button type="button" @click="largeurApercu = '768px'" class="rounded-lg border px-2 py-1 text-xs font-bold" :class="largeurApercu === '768px' ? 'bg-slate-900 text-white' : ''">Tablette</button>
                <button type="button" @click="largeurApercu = '375px'" class="rounded-lg border px-2 py-1 text-xs font-bold" :class="largeurApercu === '375px' ? 'bg-slate-900 text-white' : ''">Mobile</button>
            </span>
            <span class="flex gap-1 text-xs font-bold" role="tablist" aria-label="Format">
                <button type="button" role="tab" :aria-selected="onglet === 'html'" @click="onglet = 'html'" :class="onglet === 'html' ? 'underline' : ''" class="px-2 py-1">HTML</button>
                <button type="button" role="tab" :aria-selected="onglet === 'texte'" @click="onglet = 'texte'" :class="onglet === 'texte' ? 'underline' : ''" class="px-2 py-1">Texte brut</button>
            </span>
            <button type="button" @click="rafraichirApercu(true)" :disabled="apercuCharge" class="ml-auto rounded-lg border px-2 py-1 text-xs font-bold disabled:opacity-40">Actualiser</button>
        </div>
        <p x-show="erreurApercu" class="mt-2 text-xs font-bold text-rose-700" role="alert" x-text="erreurApercu"></p>
        <div class="mt-2 overflow-auto rounded-xl border bg-slate-50">
            <iframe x-show="onglet === 'html'" x-ref="apercu" title="Aperçu isolé" sandbox="" :style="'width:' + largeurApercu + ';max-width:100%;height:560px;border:0;background:#fff'"></iframe>
            <pre x-show="onglet === 'texte'" x-text="texteApercu" class="p-3 text-xs whitespace-pre-wrap" style="height:560px;overflow:auto;"></pre>
        </div>
    </div>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('editeurStudioV2', (schemaInitial, baseInitial, urlEnregistrer, urlApercu, squelettes) => ({
        meta: { objet: '', preheader: '', categorie: '' },
        theme: { couleur_principale: '#3730a3', couleur_accent: '#4f46e5', fond: '#f1f5f9', fond_contenu: '#ffffff', largeur: 600, police: 'arial', style_bouton: 'arrondi', logo_url: '', nom_etablissement: '', signature: '', mentions: '' },
        blocs: [],
        baseVersion: baseInitial || 0,
        note: '',
        selection: null,
        sale: false,
        sauvegarde: false,
        message: '',
        messageOk: false,
        passe: [],
        futur: [],
        glisser: null,
        survole: null,
        champActif: null,
        onglet: 'html',
        largeurApercu: '100%',
        texteApercu: '',
        erreurApercu: '',
        apercuCharge: false,
        minuteur: null,
        compteurCle: 1,
        qualite: { erreurs: [], avertissements: [] },
        favoris: [],
        BIBLIOTHEQUE: [['titre', 'Titre', 'Titre court'], ['texte', 'Texte', 'Paragraphe brut'], ['bouton', 'Bouton', 'Libellé + URL https/mailto'], ['image', 'Image', 'URL https + extensions'], ['separateur', 'Séparateur', 'Filet'], ['liste', 'Liste', 'Puces, 20 max'], ['espaceur', 'Espace', 'Hauteur 8–80'], ['colonnes', 'Colonnes', '2 colonnes simples'], ['carte', 'Carte info', 'Encadré bordure'], ['tableau', 'Tableau', 'Données 6×20'], ['signature', 'Signature', 'Texte ou thème']],

        initEditeur() {
            this.meta = Object.assign(this.meta, schemaInitial.meta || {});
            this.theme = Object.assign(this.theme, schemaInitial.theme || {});
            this.blocs = (schemaInitial.blocs || []).map((b) => Object.assign({ _cle: this.cle() }, JSON.parse(JSON.stringify(b || {}))));
            try { this.favoris = JSON.parse(localStorage.getItem('studio-favoris-v1') || '[]'); } catch (e) { this.favoris = []; }
            this.instantane(false);
            const zone = this;
            window.addEventListener('beforeunload', (e) => { if (zone.sale) e.preventDefault(); });
            this.rafraichirApercu();
        },

        cle() { return 'b' + (this.compteurCle++) + '_' + Date.now().toString(36); },
        photo() { return JSON.stringify({ meta: this.meta, theme: this.theme, blocs: this.blocsSansCle() }); },
        blocsSansCle() {
            return this.blocs.map((b) => {
                const copie = JSON.parse(JSON.stringify(b));
                delete copie._cle;
                return copie;
            });
        },
        instantane(avecHistorique = true) {
            if (avecHistorique) {
                this.passe.push(this.photo());
                if (this.passe.length > 50) this.passe.shift();
                this.futur = [];
            }
            this.dernierEnregistre = this.dernierEnregistre || this.photo();
            this.sale = this.photo() !== this.dernierEnregistre;
        },
        toucher() { this.instantane(true); this.planifierApercu(); },
        get peutAnnuler() { return this.passe.length > 1; },
        get peutRetablir() { return this.futur.length > 0; },
        annuler() {
            if (!this.peutAnnuler) return;
            this.futur.push(this.passe.pop());
            this.appliquer(this.passe[this.passe.length - 1]);
        },
        retablir() {
            if (!this.peutRetablir) return;
            const etat = this.futur.pop();
            this.passe.push(etat);
            this.appliquer(etat);
        },
        appliquer(photo) {
            const etat = JSON.parse(photo);
            this.meta = etat.meta; this.theme = etat.theme;
            this.blocs = etat.blocs.map((b) => Object.assign({ _cle: this.cle() }, b));
            if (this.selection !== null && this.selection >= this.blocs.length) this.selection = null;
            this.sale = this.photo() !== this.dernierEnregistre;
            this.planifierApercu();
        },
        raccourcis(e) {
            const mod = e.ctrlKey || e.metaKey;
            const dansChamp = e.target && e.target.matches ? e.target.matches('input,textarea,select') : false;
            if (mod && e.key.toLowerCase() === 's') { e.preventDefault(); this.sauvegarder(); }
            else if (mod && e.key.toLowerCase() === 'z' && !e.shiftKey) { e.preventDefault(); this.annuler(); }
            else if ((mod && e.key.toLowerCase() === 'y') || (mod && e.shiftKey && e.key.toLowerCase() === 'z')) { e.preventDefault(); this.retablir(); }
            else if (!dansChamp && e.altKey && e.key === 'ArrowUp' && this.selection !== null) { e.preventDefault(); this.monter(this.selection); this.selection = Math.max(0, this.selection - 1); }
            else if (!dansChamp && e.key === 'ArrowDown' && e.altKey && this.selection !== null) { e.preventDefault(); this.descendre(this.selection); this.selection = Math.min(this.blocs.length - 1, this.selection + 1); }
        },

        gabaritBloc(type) {
            const base = { type: type, alignement: 'gauche', id: null, verrouille: false };
            if (type === 'titre') return Object.assign(base, { texte: '', niveau: 'h2' });
            if (type === 'texte') return Object.assign(base, { contenu: '' });
            if (type === 'bouton') return Object.assign(base, { libelle: '', url: '' });
            if (type === 'image') return Object.assign(base, { url: '', texte_alt: '', largeur: 560 });
            if (type === 'separateur') return Object.assign(base, { style: 'fin' });
            if (type === 'liste') return Object.assign(base, { elements: [''] });
            if (type === 'espaceur') return Object.assign(base, { hauteur: 24 });
            if (type === 'colonnes') return Object.assign(base, { colonnes: [[], []] });
            if (type === 'carte') return Object.assign(base, { titre: '', texte: '', couleur_bordure: null });
            if (type === 'tableau') return Object.assign(base, { entetes: ['Colonne 1'], lignes: [] });
            if (type === 'signature') return Object.assign(base, { texte: '' });
            return null;
        },
        ajouterBloc(type) {
            const gabarit = this.gabaritBloc(type);
            if (!gabarit || this.blocs.length >= 30) return;
            this.blocs.push(Object.assign({ _cle: this.cle() }, gabarit));
            this.selection = this.blocs.length - 1;
            this.toucher();
        },
        demanderSuppression(i) {
            if (this.blocs[i] && this.blocs[i].verrouille) return;
            if (!confirm('Supprimer ce bloc ?')) return;
            this.blocs.splice(i, 1);
            if (this.selection !== null && this.selection >= this.blocs.length) this.selection = this.blocs.length ? this.blocs.length - 1 : null;
            this.toucher();
        },
        dupliquerBloc(i) {
            if (this.blocs.length >= 30) return;
            const copie = JSON.parse(JSON.stringify(this.blocsSansCle()[i]));
            copie.id = null;
            this.blocs.splice(i + 1, 0, Object.assign({ _cle: this.cle() }, copie));
            this.toucher();
        },
        monter(i) { if (i <= 0 || this.blocs[i].verrouille) return; const t = this.blocs; const b = t.splice(i, 1)[0]; t.splice(i - 1, 0, b); this.toucher(); },
        descendre(i) { if (i >= this.blocs.length - 1 || this.blocs[i].verrouille) return; const t = this.blocs; const b = t.splice(i, 1)[0]; t.splice(i + 1, 0, b); this.toucher(); },
        deposer(i) {
            if (this.glisser === null || this.glisser === i || this.blocs[this.glisser].verrouille) return;
            const b = this.blocs.splice(this.glisser, 1)[0];
            this.blocs.splice(i, 0, b);
            this.selection = i;
            this.glisser = null; this.survole = null;
            this.toucher();
        },
        blocActif() { return this.selection !== null ? (this.blocs[this.selection] || null) : null; },
        ajouterSousBloc(c, type) {
            const actif = this.blocActif();
            if (!actif || actif.type !== 'colonnes' || actif.verrouille) return;
            const gabarits = { titre: { type: 'titre', texte: '', niveau: 'h3', alignement: 'gauche' }, texte: { type: 'texte', contenu: '', alignement: 'gauche' }, bouton: { type: 'bouton', libelle: '', url: '', alignement: 'gauche' }, image: { type: 'image', url: '', texte_alt: '', largeur: 280, alignement: 'gauche' }, liste: { type: 'liste', elements: [''], alignement: 'gauche' }, separateur: { type: 'separateur', style: 'fin', alignement: 'gauche' }, espaceur: { type: 'espaceur', hauteur: 16, alignement: 'gauche' } };
            if (!gabarits[type] || actif.colonnes[c].length >= 6) return;
            actif.colonnes[c].push(Object.assign({ _cle: this.cle(), id: null, verrouille: false }, gabarits[type]));
            this.toucher();
        },
        ajouterLigneTableau() {
            const actif = this.blocActif();
            if (!actif || actif.type !== 'tableau' || actif.lignes.length >= 20) return;
            actif.lignes.push(actif.entetes.map(() => ''));
            this.toucher();
        },
        appliquerSquelette(nom) {
            const squelette = squelettes && squelettes[nom] ? JSON.parse(JSON.stringify(squelettes[nom])) : null;
            if (!squelette) return;
            if (!this.meta.objet && squelette.meta) this.meta.objet = squelette.meta.objet || '';
            const reste = 30 - this.blocs.length;
            (squelette.blocs || []).slice(0, Math.max(0, reste)).forEach((b) => {
                b.id = null; b.verrouille = false;
                this.blocs.push(Object.assign({ _cle: this.cle() }, b));
            });
            this.toucher();
        },
        appliquerTheme(id) {
            const donnees = @js($themes->mapWithKeys(fn ($t) => [$t->id => $t->config]));
            if (!id || !donnees[id]) return;
            Object.assign(this.theme, JSON.parse(JSON.stringify(donnees[id])));
            this.toucher();
        },
        memoriserFavori() {
            const actif = this.blocActif();
            if (!actif) return;
            const copie = JSON.parse(JSON.stringify(actif));
            delete copie._cle; copie.id = null; copie.verrouille = false;
            this.favoris.push({ nom: actif.type + ' ' + (this.favoris.length + 1), bloc: copie });
            try { localStorage.setItem('studio-favoris-v1', JSON.stringify(this.favoris)); } catch (e) {}
        },
        insererFavori(i) {
            if (!this.favoris[i] || this.blocs.length >= 30) return;
            const copie = JSON.parse(JSON.stringify(this.favoris[i].bloc));
            this.blocs.splice(this.selection !== null ? this.selection + 1 : this.blocs.length, 0, Object.assign({ _cle: this.cle() }, copie));
            this.toucher();
        },
        retirerFavori(i) {
            this.favoris.splice(i, 1);
            try { localStorage.setItem('studio-favoris-v1', JSON.stringify(this.favoris)); } catch (e) {}
        },

        memoriserChamp(el) { this.champActif = el; },
        insererVariable(nom) {
            const cible = this.champActif && document.contains(this.champActif) ? this.champActif : null;
            if (!cible) return;
            const marqueur = '{' + '{' + nom + '}' + '}';
            const debut = cible.selectionStart ?? cible.value.length;
            const fin = cible.selectionEnd ?? cible.value.length;
            cible.value = cible.value.slice(0, debut) + marqueur + cible.value.slice(fin);
            cible.dispatchEvent(new Event('input', { bubbles: true }));
            cible.focus();
        },
        echapper(texte) {
            return String(texte == null ? '' : texte)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        },
        miniature(bloc) {
            const t = this.echapper;
            const extrait = (v, n) => { const s = String(v || ''); return t(s.length > n ? s.slice(0, n) + '…' : s); };
            if (bloc.type === 'titre') return '<strong>' + extrait(bloc.texte, 60) + '</strong>';
            if (bloc.type === 'texte') return extrait(bloc.contenu, 120);
            if (bloc.type === 'bouton') return '▣ ' + extrait(bloc.libelle, 40);
            if (bloc.type === 'image') return '🖼 ' + extrait(bloc.texte_alt || bloc.url, 60);
            if (bloc.type === 'separateur') return '<hr>';
            if (bloc.type === 'liste') return '• ' + (bloc.elements || []).map((e) => extrait(e, 40)).join('<br>• ');
            if (bloc.type === 'espaceur') return '<span class="text-slate-400">↕ ' + bloc.hauteur + 'px</span>';
            if (bloc.type === 'colonnes') return '▥ ' + (bloc.colonnes || []).map((c) => (c || []).length + ' bloc(s)').join(' | ');
            if (bloc.type === 'carte') return '▦ <strong>' + extrait(bloc.titre, 40) + '</strong> — ' + extrait(bloc.texte, 60);
            if (bloc.type === 'tableau') return '▦ tableau ' + (bloc.entetes || []).length + '×' + (bloc.lignes || []).length;
            if (bloc.type === 'signature') return '<em>' + extrait(bloc.texte || '(signature du thème)', 80) + '</em>';
            return '';
        },

        jeton() { const m = document.querySelector('meta[name="csrf-token"]'); return m ? m.content : ''; },
        async sauvegarder() {
            if (this.sauvegarde) return;
            this.sauvegarde = true;
            this.message = '';
            try {
                const reponse = await fetch(urlEnregistrer, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.jeton() },
                    body: JSON.stringify({ schema: { schema_version: 1, meta: this.meta, theme: this.theme, blocs: this.blocsSansCle() }, base_version: this.baseVersion, note: this.note || null }),
                });
                const corps = await reponse.json().catch(() => ({}));
                if (reponse.status === 409) {
                    this.messageOk = false;
                    this.message = corps.message || 'Conflit de version : rechargez la page.';
                    return;
                }
                if (!reponse.ok) {
                    this.messageOk = false;
                    this.message = (corps.errors && corps.errors.schema && corps.errors.schema[0]) || corps.message || 'Schéma invalide.';
                    return;
                }
                this.baseVersion = corps.numero;
                this.dernierEnregistre = this.photo();
                this.sale = false;
                this.passe = [this.photo()];
                this.futur = [];
                this.messageOk = true;
                this.message = 'Version ' + corps.numero + ' enregistrée.';
            } catch (e) {
                this.messageOk = false;
                this.message = 'Erreur réseau.';
            } finally {
                this.sauvegarde = false;
            }
        },

        planifierApercu() {
            clearTimeout(this.minuteur);
            this.minuteur = setTimeout(() => this.rafraichirApercu(), 800);
        },
        async rafraichirApercu(manuel) {
            if (this.apercuCharge && !manuel) return;
            this.apercuCharge = true;
            this.erreurApercu = '';
            try {
                const reponse = await fetch(urlApercu, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.jeton() },
                    body: JSON.stringify({ schema: { schema_version: 1, meta: this.meta, theme: this.theme, blocs: this.blocsSansCle() } }),
                });
                const corps = await reponse.json().catch(() => ({}));
                if (!reponse.ok) {
                    this.erreurApercu = corps.message || 'Aperçu indisponible (schéma invalide).';
                    this.qualite = { erreurs: [], avertissements: [] };
                    return;
                }
                this.$refs.apercu.srcdoc = corps.html || '';
                this.texteApercu = corps.texte || '';
                this.qualite = corps.qualite || { erreurs: [], avertissements: [] };
            } catch (e) {
                this.erreurApercu = 'Erreur réseau.';
            } finally {
                this.apercuCharge = false;
            }
        },
    }));
});
</script>
@endsection
