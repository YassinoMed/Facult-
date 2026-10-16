@extends('layouts.app')

@section('title', 'Studio e-mail — éditer')

@section('content')
<div class="mx-auto max-w-[88rem] px-4 sm:px-6 py-8" x-data="editeurStudio(@js($schema), {{ $baseVersion }}, '{{ route('admin.studio.enregistrer', $modele) }}', '{{ route('admin.studio.apercu', $modele) }}')" x-init="initEditeur()">
    <x-page-header titre="Éditer — {{ $modele->code }}" description="Brouillon v{{ $baseVersion }} · sauvegarde explicite, aperçu isolé, cycle via les actions ci-dessous."
        :fil="['Studio' => route('admin.studio.voir', $modele)]" />

    @if(session('success'))
        <div class="mt-4"><x-alert type="success">{{ session('success') }}</x-alert></div>
    @endif
    @if(session('error'))
        <div class="mt-4"><x-alert type="error">{{ session('error') }}</x-alert></div>
    @endif

    <div class="mt-4 flex flex-wrap items-center gap-2" role="toolbar" aria-label="Actions d’édition">
        <button type="button" @click="annuler()" :disabled="!peutAnnuler" class="rounded-xl border border-slate-300 px-4 py-2 text-xs font-bold disabled:opacity-40">Annuler</button>
        <button type="button" @click="retablir()" :disabled="!peutRetablir" class="rounded-xl border border-slate-300 px-4 py-2 text-xs font-bold disabled:opacity-40">Rétablir</button>
        <button type="button" @click="sauvegarder()" :disabled="!sale || sauvegarde" class="rounded-xl bg-primary-600 px-4 py-2 text-xs font-bold text-white disabled:opacity-40">
            <span x-show="!sauvegarde">Sauvegarder (v<span x-text="baseVersion + 1"></span>)</span><span x-show="sauvegarde">Sauvegarde…</span>
        </button>
        <span x-show="sale" class="text-xs font-bold text-amber-700" role="status">Modifications non enregistrées</span>
        <span x-show="message" class="text-xs font-bold" :class="messageOk ? 'text-emerald-700' : 'text-rose-700'" role="status" x-text="message"></span>
        <span class="ml-auto flex gap-2">
            <label class="sr-only" for="note-version">Note de version</label>
            <input id="note-version" x-model="note" placeholder="Note (optionnel)" maxlength="255" class="rounded-xl border border-slate-200 px-3 py-2 text-xs">
        </span>
    </div>

    <div class="mt-4 grid grid-cols-1 xl:grid-cols-5 gap-4">
        {{-- Colonne édition --}}
        <div class="xl:col-span-3 space-y-4">
            <details class="rounded-2xl border border-slate-200 bg-white" open>
                <summary class="cursor-pointer px-5 py-3 text-sm font-black">Objet, pré-en-tête et catégorie</summary>
                <div class="px-5 pb-5 space-y-3 text-sm">
                    <div>
                        <label for="ed-objet" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Objet *</label>
                        <input id="ed-objet" x-model="meta.objet" @focus="memoriserChamp($el)" maxlength="150" class="w-full rounded-xl border border-slate-200 px-3 py-2">
                    </div>
                    <div>
                        <label for="ed-preheader" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Pré-en-tête</label>
                        <input id="ed-preheader" x-model="meta.preheader" @focus="memoriserChamp($el)" maxlength="150" class="w-full rounded-xl border border-slate-200 px-3 py-2">
                    </div>
                    <div>
                        <span class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Variables autorisées (cliquer pour insérer)</span>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach($variables as $nom => $definition)
                                <button type="button" @click="insererVariable('{{ $nom }}')" title="{{ $definition['description'] }}" class="rounded-lg bg-slate-100 border border-slate-200 px-2 py-1 font-mono text-[11px] hover:bg-slate-200">{!! '{{' . $nom . '}}' !!}</button>
                            @endforeach
                        </div>
                    </div>
                </div>
            </details>

            <details class="rounded-2xl border border-slate-200 bg-white" open>
                <summary class="cursor-pointer px-5 py-3 text-sm font-black">Blocs du corps (<span x-text="blocs.length"></span>/30)</summary>
                <div class="px-5 pb-5 space-y-3">
                    <div class="flex flex-wrap gap-1.5" role="group" aria-label="Ajouter un bloc">
                        <template x-for="[type, libelle] in [['titre','Titre'],['texte','Texte'],['bouton','Bouton'],['image','Image'],['separateur','Séparateur'],['liste','Liste'],['espaceur','Espaceur']]" :key="type">
                            <button type="button" @click="ajouterBloc(type)" class="rounded-xl border border-dashed border-slate-300 px-3 py-1.5 text-xs font-bold hover:border-primary-500">+ <span x-text="libelle"></span></button>
                        </template>
                    </div>
                    <template x-for="(bloc, i) in blocs" :key="bloc._cle">
                        <div class="rounded-xl border border-slate-200 p-3"
                             :class="survole === i ? 'border-primary-400' : ''"
                             draggable="true"
                             @dragstart="glisser = i" @dragover.prevent="survole = i" @dragleave="survole = null"
                             @drop.prevent="deposer(i)" @dragend="glisser = null; survole = null">
                            <div class="flex items-center justify-between gap-2">
                                <strong class="text-xs uppercase tracking-wider text-slate-500"><span x-text="i + 1"></span> · <span x-text="bloc.type"></span></strong>
                                <span class="flex gap-1">
                                    <button type="button" @click="monter(i)" :disabled="i === 0" aria-label="Monter" class="rounded-lg border px-2 py-1 text-xs disabled:opacity-40">↑</button>
                                    <button type="button" @click="descendre(i)" :disabled="i === blocs.length - 1" aria-label="Descendre" class="rounded-lg border px-2 py-1 text-xs disabled:opacity-40">↓</button>
                                    <button type="button" @click="dupliquerBloc(i)" aria-label="Dupliquer" class="rounded-lg border px-2 py-1 text-xs">⧉</button>
                                    <button type="button" @click="supprimerBloc(i)" aria-label="Supprimer" class="rounded-lg border border-rose-300 px-2 py-1 text-xs text-rose-700">✕</button>
                                </span>
                            </div>
                            <div class="mt-2 space-y-2 text-sm" @focusin="if ($event.target.matches('input,textarea')) memoriserChamp($event.target)">
                                <template x-if="['titre','texte','bouton','image','liste'].includes(bloc.type)">
                                    <div class="flex gap-2">
                                        <label class="text-xs font-bold text-slate-500">Alignement
                                            <select x-model="bloc.alignement" @change="toucher()" class="ml-1 rounded-lg border px-1 py-0.5">
                                                <option value="gauche">Gauche</option><option value="centre">Centre</option><option value="droite">Droite</option>
                                            </select>
                                        </label>
                                    </div>
                                </template>
                                <template x-if="bloc.type === 'titre'">
                                    <div class="space-y-2">
                                        <input x-model="bloc.texte" @input="toucher()" maxlength="200" placeholder="Titre (@{{variable}} autorisée)" class="w-full rounded-xl border px-3 py-2 font-bold">
                                        <label class="text-xs">Niveau <select x-model="bloc.niveau" @change="toucher()" class="rounded-lg border px-1 py-0.5"><option value="h1">H1</option><option value="h2">H2</option><option value="h3">H3</option></select></label>
                                    </div>
                                </template>
                                <template x-if="bloc.type === 'texte'">
                                    <textarea x-model="bloc.contenu" @input="toucher()" rows="3" maxlength="5000" placeholder="Texte brut (paragraphes séparés par une ligne vide)" class="w-full rounded-xl border px-3 py-2"></textarea>
                                </template>
                                <template x-if="bloc.type === 'bouton'">
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                        <input x-model="bloc.libelle" @input="toucher()" maxlength="80" placeholder="Libellé" class="rounded-xl border px-3 py-2">
                                        <input x-model="bloc.url" @input="toucher()" maxlength="500" placeholder="https://… ou mailto:…" class="rounded-xl border px-3 py-2 font-mono text-xs">
                                    </div>
                                </template>
                                <template x-if="bloc.type === 'image'">
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                        <input x-model="bloc.url" @input="toucher()" maxlength="500" placeholder="https://…/image.png" class="rounded-xl border px-3 py-2 font-mono text-xs">
                                        <input x-model="bloc.texte_alt" @input="toucher()" maxlength="200" placeholder="Texte alternatif *" class="rounded-xl border px-3 py-2">
                                    </div>
                                    <label class="text-xs">Largeur (100–600) <input type="number" x-model.number="bloc.largeur" @input="toucher()" min="100" max="600" class="w-20 rounded-lg border px-1 py-0.5"></label>
                                </template>
                                <template x-if="bloc.type === 'separateur'">
                                    <label class="text-xs">Style <select x-model="bloc.style" @change="toucher()" class="rounded-lg border px-1 py-0.5"><option value="fin">Fin</option><option value="epais">Épais</option><option value="pointille">Pointillé</option></select></label>
                                </template>
                                <template x-if="bloc.type === 'liste'">
                                    <div class="space-y-1">
                                        <template x-for="(element, j) in bloc.elements" :key="j">
                                            <div class="flex gap-1">
                                                <input x-model="bloc.elements[j]" @input="toucher()" maxlength="200" class="flex-1 rounded-xl border px-3 py-1.5">
                                                <button type="button" @click="bloc.elements.splice(j, 1); toucher()" aria-label="Retirer l’élément" class="rounded-lg border px-2 text-xs">✕</button>
                                            </div>
                                        </template>
                                        <button type="button" @click="if (bloc.elements.length < 20) { bloc.elements.push(''); toucher(); }" class="text-xs font-bold text-primary-800">+ Élément</button>
                                    </div>
                                </template>
                                <template x-if="bloc.type === 'espaceur'">
                                    <label class="text-xs">Hauteur (8–80) <input type="number" x-model.number="bloc.hauteur" @input="toucher()" min="8" max="80" class="w-20 rounded-lg border px-1 py-0.5"></label>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </details>

            <details class="rounded-2xl border border-slate-200 bg-white">
                <summary class="cursor-pointer px-5 py-3 text-sm font-black">Thème visuel</summary>
                <div class="px-5 pb-5 space-y-3 text-sm">
                    <div>
                        <label for="ed-theme" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Appliquer un thème existant</label>
                        <select id="ed-theme" @change="appliquerTheme($event.target.value)" class="w-full rounded-xl border px-3 py-2">
                            <option value="">— Thème inline actuel —</option>
                            @foreach($themes as $theme)
                                <option value="{{ $theme->id }}">{{ $theme->nom }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                        <template x-for="cle in ['couleur_principale','couleur_accent','fond','fond_contenu']" :key="cle">
                            <label class="text-xs font-bold"><span x-text="cle"></span>
                                <input type="color" x-model="theme[cle]" @input="toucher()" class="mt-1 block h-9 w-full rounded-lg border">
                            </label>
                        </template>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <label class="text-xs font-bold">Largeur (480–800)<input type="number" x-model.number="theme.largeur" @input="toucher()" min="480" max="800" class="mt-1 block w-full rounded-xl border px-3 py-2"></label>
                        <label class="text-xs font-bold">Logo (https)<input x-model="theme.logo_url" @input="toucher()" maxlength="500" placeholder="https://…" class="mt-1 block w-full rounded-xl border px-3 py-2 font-mono text-xs"></label>
                        <label class="text-xs font-bold">Établissement<input x-model="theme.nom_etablissement" @input="toucher()" maxlength="120" class="mt-1 block w-full rounded-xl border px-3 py-2"></label>
                        <label class="text-xs font-bold">Signature<input x-model="theme.signature" @input="toucher()" maxlength="500" class="mt-1 block w-full rounded-xl border px-3 py-2"></label>
                    </div>
                    <label class="block text-xs font-bold">Mentions<textarea x-model="theme.mentions" @input="toucher()" rows="2" maxlength="500" class="mt-1 block w-full rounded-xl border px-3 py-2"></textarea></label>
                </div>
            </details>
        </div>

        {{-- Colonne aperçu --}}
        <div class="xl:col-span-2">
            <div class="xl:sticky xl:top-20 rounded-2xl border border-slate-200 bg-white p-4">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-sm font-black">Aperçu isolé (données fictives)</h2>
                    <span class="flex gap-1" role="group" aria-label="Largeur d’aperçu">
                        <button type="button" @click="largeurApercu = '100%'" :class="largeurApercu === '100%' ? 'bg-slate-900 text-white' : ''" class="rounded-lg border px-2 py-1 text-xs font-bold">Bureau</button>
                        <button type="button" @click="largeurApercu = '375px'" :class="largeurApercu === '375px' ? 'bg-slate-900 text-white' : ''" class="rounded-lg border px-2 py-1 text-xs font-bold">Mobile</button>
                    </span>
                </div>
                <div class="mt-2 flex gap-1 text-xs font-bold" role="tablist" aria-label="Format d’aperçu">
                    <button type="button" role="tab" :aria-selected="onglet === 'html'" @click="onglet = 'html'" :class="onglet === 'html' ? 'underline' : ''" class="px-2 py-1">HTML</button>
                    <button type="button" role="tab" :aria-selected="onglet === 'texte'" @click="onglet = 'texte'" :class="onglet === 'texte' ? 'underline' : ''" class="px-2 py-1">Texte brut</button>
                    <button type="button" @click="rafraichirApercu()" :disabled="apercuCharge" class="ml-auto rounded-lg border px-2 py-1 disabled:opacity-40">Actualiser</button>
                </div>
                <p x-show="erreurApercu" class="mt-2 text-xs font-bold text-rose-700" role="alert" x-text="erreurApercu"></p>
                <div class="mt-2 overflow-auto rounded-xl border bg-slate-50" :style="'max-width:100%'">
                    <iframe x-show="onglet === 'html'" x-ref="apercu" title="Aperçu isolé du courriel" sandbox=""
                            :style="'width:' + largeurApercu + ';max-width:100%;height:520px;border:0;background:#fff'"></iframe>
                    <pre x-show="onglet === 'texte'" x-text="texteApercu" class="p-3 text-xs whitespace-pre-wrap" style="width:100%;height:520px;overflow:auto;"></pre>
                </div>
                <p class="mt-1 text-[11px] text-slate-500">Sandbox sans origine : aucun script du modèle ne s’exécute ici.</p>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('editeurStudio', (schemaInitial, baseInitial, urlEnregistrer, urlApercu) => ({
        meta: { objet: '', preheader: '', categorie: '' },
        theme: { couleur_principale: '#3730a3', couleur_accent: '#4f46e5', fond: '#f1f5f9', fond_contenu: '#ffffff', largeur: 600, logo_url: '', nom_etablissement: '', signature: '', mentions: '' },
        blocs: [],
        baseVersion: baseInitial || 0,
        note: '',
        sale: false,
        sauvegarde: false,
        message: '',
        messageOk: false,
        passe: [],      // pile annuler
        futur: [],      // pile rétablir
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

        initEditeur() {
            this.meta = Object.assign(this.meta, schemaInitial.meta || {});
            this.theme = Object.assign(this.theme, schemaInitial.theme || {});
            this.blocs = (schemaInitial.blocs || []).map((b) => Object.assign({ _cle: this.cle() }, JSON.parse(JSON.stringify(b || {}))));
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
            this.sale = this.photo() !== this.dernierEnregistre;
            this.planifierApercu();
        },

        gabaritBloc(type) {
            const base = { type, alignement: 'gauche' };
            if (type === 'titre') return Object.assign(base, { texte: '', niveau: 'h2' });
            if (type === 'texte') return Object.assign(base, { contenu: '' });
            if (type === 'bouton') return Object.assign(base, { libelle: '', url: '' });
            if (type === 'image') return Object.assign(base, { url: '', texte_alt: '', largeur: 560 });
            if (type === 'separateur') return Object.assign(base, { style: 'fin' });
            if (type === 'liste') return Object.assign(base, { elements: [''] });
            return Object.assign(base, { hauteur: 24 });
        },
        ajouterBloc(type) {
            if (this.blocs.length >= 30) return;
            this.blocs.push(Object.assign({ _cle: this.cle() }, this.gabaritBloc(type)));
            this.toucher();
        },
        supprimerBloc(i) { this.blocs.splice(i, 1); this.toucher(); },
        dupliquerBloc(i) {
            if (this.blocs.length >= 30) return;
            this.blocs.splice(i + 1, 0, Object.assign({ _cle: this.cle() }, JSON.parse(JSON.stringify(this.blocsSansCle()[i]))));
            this.toucher();
        },
        monter(i) { if (i <= 0) return; const [b] = this.blocs.splice(i, 1); this.blocs.splice(i - 1, 0, b); this.toucher(); },
        descendre(i) { if (i >= this.blocs.length - 1) return; const [b] = this.blocs.splice(i, 1); this.blocs.splice(i + 1, 0, b); this.toucher(); },
        deposer(i) {
            if (this.glisser === null || this.glisser === i) return;
            const [b] = this.blocs.splice(this.glisser, 1);
            this.blocs.splice(i, 0, b);
            this.glisser = null; this.survole = null;
            this.toucher();
        },

        memoriserChamp(el) { this.champActif = el; },
        insererVariable(nom) {
            const cible = this.champActif && document.contains(this.champActif) ? this.champActif : null;
            const marqueur = '{' + '{' + nom + '}' + '}';
            if (!cible) return;
            const debut = cible.selectionStart ?? cible.value.length;
            const fin = cible.selectionEnd ?? cible.value.length;
            cible.value = cible.value.slice(0, debut) + marqueur + cible.value.slice(fin);
            cible.dispatchEvent(new Event('input', { bubbles: true }));
            cible.focus();
        },
        appliquerTheme(id) {
            const donnees = @js($themes->mapWithKeys(fn ($t) => [$t->id => $t->config]));
            if (!id || !donnees[id]) return;
            Object.assign(this.theme, JSON.parse(JSON.stringify(donnees[id])));
            this.toucher();
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
        async rafraichirApercu() {
            if (this.apercuCharge) return;
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
                    return;
                }
                this.$refs.apercu.srcdoc = corps.html || '';
                this.texteApercu = corps.texte || '';
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
