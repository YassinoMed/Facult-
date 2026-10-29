{{--
    Modale partagée d'attribution de rôle (menu comptes + page rôles).
    Variables : $mode ('compte'|'role'), $config (urls, roles, registre,
    facultes, compte?, roleCode?). La sécurité est 100 % serveur
    (AttributionRoleService) ; ici : confirmation explicite, états,
    erreurs sans détail sensible, succès appliqué après réponse serveur.
--}}
<div x-data="attributionRole(@js($config))" @ouvrir-attribution.window="ouvrir($event.detail)"
     @keydown.escape.window="if (ouvert && !envoi) fermer()">
    <template x-if="ouvert">
        <div class="fixed inset-0 z-[80] flex items-end justify-center sm:items-center sm:p-6" role="dialog"
             aria-modal="true" aria-labelledby="titre-attribution-role">
            <div class="absolute inset-0 bg-slate-900/60" @click="if (!envoi) fermer()" aria-hidden="true"></div>
            <div class="relative max-h-[92vh] w-full max-w-xl overflow-y-auto rounded-3xl bg-white p-6 shadow-2xl sm:p-8">
                <h3 id="titre-attribution-role" tabindex="-1" x-ref="titre" class="text-lg font-black text-slate-900">
                    Attribuer un rôle
                </h3>

                {{-- Compte cible --}}
                <div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm">
                    <template x-if="mode === 'role'">
                        <div>
                            <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500" for="recherche-compte">Compte cible (recherche)</label>
                            <input id="recherche-compte" type="text" x-model="recherche" @input="chercher()"
                                   placeholder="Nom ou email (2 lettres min.)…" autocomplete="off"
                                   class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            <ul x-show="resultats.length" class="mt-2 max-h-40 overflow-y-auto rounded-xl border border-slate-200 bg-white" role="listbox" aria-label="Comptes trouvés">
                                <template x-for="c in resultats" :key="c.id">
                                    <li><button type="button" @click="choisirCompte(c)" role="option"
                                        class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-slate-100">
                                        <span><strong x-text="c.name"></strong> <span class="text-slate-400" x-text="c.email"></span></span>
                                        <span class="text-xs text-slate-500" x-text="c.role_libelle"></span>
                                    </button></li>
                                </template>
                            </ul>
                            <p x-show="recherche.length >= 2 && !resultats.length && !rechercheEnCours" class="mt-1 text-xs text-slate-400">Aucun compte trouvé.</p>
                        </div>
                    </template>
                    <div x-show="cible" class="text-sm text-slate-700">
                        <p><strong x-text="cible?.name"></strong> <span class="text-slate-400" x-text="cible?.email"></span></p>
                        <p class="mt-1 text-xs">Rôle actuel : <strong x-text="libelleRole(cible?.role)"></strong>
                            <span x-show="cible?.faculte" x-text="' · ' + cible.faculte"></span></p>
                    </div>
                    <p x-show="!cible && mode === 'compte'" class="text-sm text-rose-700">Compte introuvable.</p>
                </div>

                {{-- Rôle cible --}}
                <div class="mt-4">
                    <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500" for="role-cible">Rôle demandé</label>
                    <select id="role-cible" x-model="roleCible" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        <template x-for="r in roles" :key="r.code">
                            <option :value="r.code" x-text="r.libelle + ' (' + r.nb_permissions + ' permissions)' + (r.sensible ? ' — sensible' : '')"></option>
                        </template>
                    </select>
                </div>

                {{-- Faculté si admin_faculte --}}
                <div class="mt-3" x-show="roleCible === 'admin_faculte'">
                    <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500" for="faculte-cible">Faculté de rattachement (obligatoire)</label>
                    <select id="faculte-cible" x-model="faculteId" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        <option value="">— Choisir —</option>
                        <template x-for="f in facultes" :key="f.id">
                            <option :value="f.id" x-text="f.nom"></option>
                        </template>
                    </select>
                </div>

                {{-- Comparaison --}}
                <div x-show="cible && roleCible" class="mt-4 rounded-2xl border border-slate-200 p-4 text-sm">
                    <p class="text-slate-700"><span x-text="nbPermissions(cible?.role)"></span> → <strong x-text="nbPermissions(roleCible)"></strong> permissions</p>
                    <p x-show="ajoutees().length" class="mt-1 text-xs text-emerald-700">+ <span x-text="ajoutees().slice(0, 8).join(', ')"></span><span x-show="ajoutees().length > 8" x-text="' (+' + (ajoutees().length - 8) + ')'"></span></p>
                    <p x-show="retirees().length" class="mt-1 text-xs text-rose-700">− <span x-text="retirees().slice(0, 8).join(', ')"></span><span x-show="retirees().length > 8" x-text="' (+' + (retirees().length - 8) + ')'"></span></p>
                    <p x-show="avertissementSensible()" class="mt-2 rounded-xl border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-bold text-amber-900" role="alert">
                        Changement sensible : le rôle super-admin est concerné. Vérifiez l'identité du compte avant de confirmer.
                    </p>
                </div>

                <p x-show="erreur" class="mt-3 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-800" role="alert" x-text="erreur"></p>
                <p x-show="succes" class="mt-3 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800" role="status" x-text="succes"></p>

                <div class="mt-5 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <button type="button" @click="fermer()" :disabled="envoi" class="rounded-xl border border-slate-300 px-5 py-2.5 text-sm font-bold text-slate-700 disabled:opacity-60">Annuler</button>
                    <button type="button" @click="confirmer()" :disabled="envoi || !peutConfirmer()" x-ref="confirmer"
                            class="rounded-xl bg-primary-800 px-5 py-2.5 text-sm font-bold text-white disabled:opacity-60">
                        <span x-show="!envoi">Confirmer l'attribution</span>
                        <span x-show="envoi">Envoi en cours…</span>
                    </button>
                </div>
            </div>
        </div>
    </template>

    <script>
    function attributionRole(config) {
        return {
            mode: config.mode, roles: config.roles, registre: config.registre, facultes: config.facultes,
            urls: config.urls, roleImpose: config.roleCode || null,
            ouvert: false, cible: null, roleCible: '', faculteId: '', envoi: false,
            erreur: '', succes: '', recherche: '', resultats: [], rechercheEnCours: false, delai: null, declencheur: null,
            ouvrir(detail) {
                this.erreur = ''; this.succes = ''; this.envoi = false;
                this.declencheur = document.activeElement;
                if (this.mode === 'compte') {
                    this.cible = detail.compte;
                    this.roleCible = detail.compte.role;
                    this.faculteId = '';
                } else {
                    this.cible = null; this.recherche = ''; this.resultats = [];
                    this.roleCible = detail.roleCode || this.roleImpose || (this.roles[0] && this.roles[0].code);
                    this.faculteId = '';
                }
                this.ouvert = true;
                this.$nextTick(() => this.$refs.titre?.focus());
            },
            fermer() { this.ouvert = false; if (this.delai) clearTimeout(this.delai); this.declencheur?.focus?.(); },
            choisirCompte(c) { this.cible = c; this.resultats = []; this.recherche = c.name; this.erreur = ''; },
            chercher() {
                if (this.delai) clearTimeout(this.delai);
                if (this.recherche.trim().length < 2) { this.resultats = []; return; }
                this.delai = setTimeout(async () => {
                    this.rechercheEnCours = true;
                    try {
                        const r = await fetch(this.urls.recherche + '?q=' + encodeURIComponent(this.recherche.trim()), { headers: { Accept: 'application/json' } });
                        const corps = await r.json().catch(() => ({}));
                        this.resultats = r.ok ? (corps.comptes || []) : [];
                        if (!r.ok) this.erreur = corps.message || 'Recherche impossible.';
                    } catch (e) { this.erreur = 'Réseau indisponible.'; }
                    finally { this.rechercheEnCours = false; }
                }, 300);
            },
            libelleRole(code) { const r = this.roles.find((x) => x.code === code); return r ? r.libelle : (code || '—'); },
            nbPermissions(code) { const l = this.registre[code]; return l ? l.length : 0; },
            ajoutees() { const a = new Set(this.registre[this.cible?.role] || []); return (this.registre[this.roleCible] || []).filter((p) => !a.has(p)); },
            retirees() { const b = new Set(this.registre[this.roleCible] || []); return (this.registre[this.cible?.role] || []).filter((p) => !b.has(p)); },
            avertissementSensible() { return this.roleCible === 'super_admin' || this.cible?.role === 'super_admin'; },
            peutConfirmer() {
                if (!this.cible || !this.roleCible) return false;
                if (this.cible.est_propre_compte) return false;
                if (this.roleCible === 'admin_faculte' && !this.faculteId) return false;
                return true;
            },
            jeton() { const m = document.querySelector('meta[name="csrf-token"]'); return m ? m.content : ''; },
            urlAttribution() {
                return this.mode === 'compte'
                    ? this.urls.attribuer.replace('__ID__', this.cible.id)
                    : this.urls.attribuer;
            },
            async confirmer() {
                if (this.envoi || !this.peutConfirmer()) return;
                this.envoi = true; this.erreur = ''; this.succes = '';
                const corps = { role: this.roleCible, role_actuel: this.cible.role };
                if (this.mode === 'role') corps.user_id = this.cible.id;
                if (this.roleCible === 'admin_faculte') corps.faculte_id = parseInt(this.faculteId, 10);
                try {
                    const r = await fetch(this.urlAttribution(), {
                        method: this.mode === 'compte' ? 'PATCH' : 'POST',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.jeton() },
                        body: JSON.stringify(corps),
                    });
                    const data = await r.json().catch(() => ({}));
                    if (!r.ok || data.ok === false) {
                        this.erreur = data.message || (r.status === 409 ? 'Le rôle a changé entre-temps : refermez et recommencez.' : (r.status === 403 ? 'Opération refusée (autorisation insuffisante).' : 'Échec de l’attribution.'));
                        return;
                    }
                    this.succes = data.message;
                    this.$dispatch('role-attribue', { cible: this.cible, resultat: data });
                    setTimeout(() => this.fermer(), 900);
                } catch (e) { this.erreur = 'Réseau indisponible, réessayez.'; }
                finally { this.envoi = false; }
            },
        };
    }
    </script>
</div>
