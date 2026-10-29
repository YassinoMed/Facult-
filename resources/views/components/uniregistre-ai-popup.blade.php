{{--
    Composant : Popup Conversationnel UniRegistre AI (Blade + Alpine.js + Tailwind CSS v4)
    Multiportail : Administration (User), Enseignant (Teacher), Étudiant (Etudiant).
    Règles : Contexte de page calculé serveur, citations vérifiables [SRC_...], préparation/confirmation humaine.
--}}
<div x-data="uniregistreAiPopup()"
     x-init="initPopup()"
     @keydown.window.escape="siOuvertFermer()"
     @keydown.window.ctrl.j.prevent="basculerOuverture()"
     @keydown.window.meta.j.prevent="basculerOuverture()"
     class="no-print">

    {{-- ── BOUTON FLOTTANT DÉCLENCHEUR ── --}}
    <div class="fixed bottom-6 right-6 z-40 flex items-center gap-2">
        <button type="button"
                @click="basculerOuverture()"
                :aria-expanded="ouvert.toString()"
                aria-controls="dialog-uniregistre-ai"
                aria-label="Ouvrir l'assistant UniRegistre AI (Ctrl+J ou ⌘J)"
                class="group relative flex h-14 items-center gap-3 rounded-full bg-slate-900 px-4 text-white shadow-xl ring-1 ring-white/20 transition-all duration-300 hover:scale-105 hover:bg-slate-800 hover:shadow-2xl focus:outline-none focus:ring-2 focus:ring-indigo-400 focus:ring-offset-2 dark:bg-slate-800 dark:hover:bg-slate-700">
            <span class="relative flex h-8 w-8 items-center justify-center rounded-full bg-gradient-to-tr from-indigo-500 to-sky-400 text-white shadow-inner">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z" />
                </svg>
                <span x-show="chargement" class="absolute -right-0.5 -top-0.5 flex h-3 w-3">
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                    <span class="relative inline-flex h-3 w-3 rounded-full bg-emerald-500"></span>
                </span>
            </span>
            <div class="hidden text-left sm:block">
                <span class="block text-xs font-bold tracking-tight text-white">UniRegistre AI</span>
                <span class="block text-[10px] font-medium text-slate-300" x-text="portailLibelle">Portail</span>
            </div>
            <kbd class="hidden rounded bg-white/10 px-1.5 py-0.5 text-[10px] font-semibold text-slate-300 sm:inline-block">⌘J</kbd>
        </button>
    </div>

    {{-- ── PANNEAU CONVERSATIONNEL ACCESSIBLE ── --}}
    <div id="dialog-uniregistre-ai"
         x-show="ouvert"
         x-cloak
         role="dialog"
         aria-modal="true"
         aria-labelledby="titre-popup-ai"
         class="fixed inset-0 z-50 flex flex-col justify-end p-0 sm:bottom-24 sm:right-6 sm:top-auto sm:left-auto sm:w-[480px] sm:p-0">

        {{-- Arrière-plan flou sur mobile --}}
        <div x-show="ouvert"
             x-transition:enter="transition-opacity ease-out duration-200"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition-opacity ease-in duration-150"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             @click="ouvert = false"
             class="fixed inset-0 bg-slate-900/40 backdrop-blur-xs sm:hidden"></div>

        {{-- Conteneur principal de la fenêtre --}}
        <div x-show="ouvert"
             x-transition:enter="transition ease-out duration-200 transform"
             x-transition:enter-start="translate-y-4 opacity-0 sm:scale-95"
             x-transition:enter-end="translate-y-0 opacity-100 sm:scale-100"
             x-transition:leave="transition ease-in duration-150 transform"
             x-transition:leave-start="translate-y-0 opacity-100 sm:scale-100"
             x-transition:leave-end="translate-y-4 opacity-0 sm:scale-95"
             class="relative flex h-[85vh] w-full flex-col overflow-hidden rounded-t-3xl bg-white shadow-2xl ring-1 ring-slate-900/10 sm:h-[640px] sm:max-h-[85vh] sm:rounded-2xl dark:bg-slate-900 dark:ring-white/10">

            {{-- ── EN-TÊTE DU POPUP ── --}}
            <header class="flex items-center justify-between border-b border-slate-100 bg-slate-900 px-4 py-3 text-white dark:border-slate-800">
                <div class="flex items-center gap-2.5">
                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-indigo-500/30 text-indigo-300">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" />
                        </svg>
                    </span>
                    <div>
                        <h2 id="titre-popup-ai" class="text-sm font-bold tracking-tight text-white">UniRegistre AI</h2>
                        <div class="flex items-center gap-1.5 text-[11px] text-slate-300">
                            <span class="inline-block h-1.5 w-1.5 rounded-full bg-emerald-400"></span>
                            <span x-text="fournisseurActif">Local</span>
                            <span>•</span>
                            <span class="rounded bg-white/10 px-1 py-0.2 text-[10px]" x-text="portailLibelle">Étudiant</span>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-1">
                    {{-- Nouvelle discussion --}}
                    <button type="button"
                            @click="nouvelleConversation()"
                            title="Nouvelle conversation"
                            aria-label="Démarrer une nouvelle discussion"
                            class="rounded-lg p-1.5 text-slate-300 hover:bg-white/10 hover:text-white">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                    </button>
                    {{-- Fermer --}}
                    <button type="button"
                            @click="ouvert = false"
                            title="Fermer (Échap)"
                            aria-label="Fermer la fenêtre d'assistance"
                            class="rounded-lg p-1.5 text-slate-300 hover:bg-white/10 hover:text-white">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </header>

            {{-- ── BANDEAU DU CONTEXTE DE PAGE DÉTECTÉ ── --}}
            <div x-show="contextePage && contextePage.ecran" class="border-b border-slate-100 bg-slate-50/80 px-4 py-2 text-xs text-slate-600 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-300">
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-1.5 truncate">
                        <span class="font-semibold text-slate-700 dark:text-slate-200">Écran :</span>
                        <span class="truncate" x-text="contextePage.ecran">Général</span>
                    </div>
                    <span class="shrink-0 rounded-full bg-indigo-50 px-2 py-0.5 text-[10px] font-medium text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-300">Contextualisé</span>
                </div>
            </div>

            {{-- ── FLUX DES MESSAGES ── --}}
            <div id="flux-messages-ai"
                 class="flex-1 space-y-4 overflow-y-auto p-4 text-sm scroll-smooth">

                {{-- Message de bienvenue initial si la discussion est vide --}}
                <template x-if="messages.length === 0">
                    <div class="space-y-4 py-4 text-center">
                        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600 dark:bg-indigo-950/50 dark:text-indigo-400">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342" />
                            </svg>
                        </div>
                        <div>
                            <h3 class="font-bold text-slate-800 dark:text-slate-100">Bonjour <span x-text="nomActeur"></span></h3>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400" x-text="contextePage.description || 'Posez une question sur les procédures et règlements universitaires.'"></p>
                        </div>

                        {{-- Suggestions contextuelles cliquables --}}
                        <div x-show="suggestions.length > 0" class="pt-2 text-left">
                            <span class="block text-[11px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">Suggestions pour cette page :</span>
                            <div class="mt-2 space-y-1.5">
                                <template x-for="(sug, sIdx) in suggestions" :key="sIdx">
                                    <button type="button"
                                            @click="choisirSuggestion(sug)"
                                            class="w-full rounded-xl border border-slate-200/80 bg-slate-50/50 p-2.5 text-left text-xs font-medium text-slate-700 transition hover:border-indigo-300 hover:bg-indigo-50/50 hover:text-indigo-900 dark:border-slate-800 dark:bg-slate-800/40 dark:text-slate-200 dark:hover:border-indigo-600 dark:hover:bg-indigo-950/30">
                                        <span class="flex items-center gap-2">
                                            <svg class="h-3.5 w-3.5 shrink-0 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                                            </svg>
                                            <span x-text="sug"></span>
                                        </span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>
                </template>

                {{-- Liste dynamique des messages échangés --}}
                <template x-for="(msg, index) in messages" :key="index">
                    <div :class="msg.role === 'user' ? 'flex justify-end' : 'flex justify-start'">
                        <div :class="msg.role === 'user'
                                ? 'max-w-[85%] rounded-2xl rounded-tr-xs bg-indigo-600 px-4 py-2.5 text-white shadow-sm'
                                : 'max-w-[90%] rounded-2xl rounded-tl-xs border border-slate-100 bg-slate-50 px-4 py-3 text-slate-800 shadow-xs dark:border-slate-800 dark:bg-slate-800/80 dark:text-slate-100'">

                            {{-- En-tête rôle --}}
                            <div class="mb-1 flex items-center justify-between gap-2 text-[11px]">
                                <span class="font-bold" :class="msg.role === 'user' ? 'text-indigo-200' : 'text-slate-500 dark:text-slate-400'"
                                      x-text="msg.role === 'user' ? 'Vous' : 'UniRegistre AI'"></span>
                                <span class="text-[10px] opacity-70" x-text="msg.heure || ''"></span>
                            </div>

                            {{-- Corps du message avec préservation des retours ligne --}}
                            <div class="whitespace-pre-wrap break-words leading-relaxed text-xs sm:text-[13px]" x-text="msg.contenu"></div>

                            {{-- Citations vérifiées [SRC_...] --}}
                            <template x-if="msg.citations && msg.citations.length > 0">
                                <div class="mt-3 border-t border-slate-200/60 pt-2 text-xs dark:border-slate-700/60">
                                    <span class="block text-[11px] font-bold text-slate-600 dark:text-slate-300">Sources réglementaires vérifiées :</span>
                                    <div class="mt-1 space-y-1">
                                        <template x-for="(cite, cIdx) in msg.citations" :key="cIdx">
                                            <div class="rounded-lg bg-white/70 p-1.5 text-[11px] text-slate-600 dark:bg-slate-900/60 dark:text-slate-300">
                                                <div class="font-semibold text-indigo-600 dark:text-indigo-400" x-text="'[' + cite.source_id + '] ' + (cite.titre || 'Référentiel')"></div>
                                                <div class="italic text-slate-500" x-text="cite.extrait ? '« ' + cite.extrait + ' »' : ''"></div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>

                            {{-- Carte de proposition d'action à validation humaine --}}
                            <template x-if="msg.action_proposee">
                                <div class="mt-3 rounded-xl border border-amber-200 bg-amber-50/90 p-3 text-xs text-amber-900 dark:border-amber-800/80 dark:bg-amber-950/40 dark:text-amber-200">
                                    <div class="flex items-center gap-1.5 font-bold text-amber-800 dark:text-amber-300">
                                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                                        </svg>
                                        <span>Action préparée (validation requise)</span>
                                    </div>
                                    <p class="mt-1 font-semibold" x-text="msg.action_proposee.resume_impact"></p>
                                    <p class="mt-1 text-[11px] text-amber-700 dark:text-amber-300" x-text="msg.action_proposee.consequences"></p>

                                    <div class="mt-2.5 flex items-center gap-2">
                                        <button type="button"
                                                @click="confirmerAction(msg.action_proposee.action_uuid)"
                                                :disabled="chargementAction"
                                                class="rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-bold text-white shadow-xs transition hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-500">
                                            <span x-show="!chargementAction">Confirmer l'opération</span>
                                            <span x-show="chargementAction">Vérification serveur...</span>
                                        </button>
                                        <span class="text-[10px] text-amber-600 dark:text-amber-400">Recontrôle d'autorisation obligatoire</span>
                                    </div>
                                </div>
                            </template>

                            {{-- Résultat après confirmation d'action --}}
                            <template x-if="msg.action_resultat">
                                <div class="mt-2.5 rounded-xl border border-emerald-200 bg-emerald-50/90 p-2.5 text-xs text-emerald-900 dark:border-emerald-800/80 dark:bg-emerald-950/40 dark:text-emerald-200">
                                    <div class="flex items-center gap-1.5 font-bold text-emerald-800 dark:text-emerald-300">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                        </svg>
                                        <span x-text="msg.action_resultat.statut === 'en_attente_approbation' ? 'Transmise à l\'approbation 4-yeux' : 'Action confirmée et exécutée'"></span>
                                    </div>
                                    <p class="mt-0.5 text-[11px]" x-text="msg.action_resultat.message"></p>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                {{-- Indicateur de chargement en cours --}}
                <div x-show="chargement" class="flex justify-start">
                    <div class="flex items-center gap-2 rounded-2xl rounded-tl-xs border border-slate-100 bg-slate-50 px-4 py-3 text-xs text-slate-500 shadow-xs dark:border-slate-800 dark:bg-slate-800/80 dark:text-slate-400">
                        <span class="flex gap-1">
                            <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-indigo-500" style="animation-delay: 0ms;"></span>
                            <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-indigo-500" style="animation-delay: 150ms;"></span>
                            <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-indigo-500" style="animation-delay: 300ms;"></span>
                        </span>
                        <span>UniRegistre AI recherche les sources autorisées...</span>
                    </div>
                </div>

                {{-- Affichage d'erreur éventuelle --}}
                <div x-show="erreurMessage" class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-200">
                    <div class="flex items-start gap-2">
                        <svg class="h-4 w-4 shrink-0 text-rose-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                        </svg>
                        <div>
                            <span class="font-bold">Indisponibilité :</span>
                            <span x-text="erreurMessage"></span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ── FORMULAIRE DE SAISIE DE MESSAGE ── --}}
            <form @submit.prevent="envoyerMessage()" class="border-t border-slate-100 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
                <div class="relative flex items-center">
                    <textarea x-model="nouveauMessage"
                              @keydown.enter.exact.prevent="envoyerMessage()"
                              rows="1"
                              maxlength="4000"
                              placeholder="Posez votre question sur UniRegistre..."
                              :disabled="chargement"
                              aria-label="Message pour l'assistant IA"
                              class="w-full resize-none rounded-xl border border-slate-200 bg-slate-50 py-2.5 pl-3.5 pr-12 text-xs text-slate-800 transition focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-indigo-500 disabled:opacity-50 sm:text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:focus:border-indigo-400"></textarea>

                    <button type="submit"
                            :disabled="chargement || !nouveauMessage.trim()"
                            aria-label="Envoyer le message"
                            class="absolute right-2 rounded-lg bg-indigo-600 p-2 text-white shadow-xs transition hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:cursor-not-allowed disabled:opacity-40">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
                        </svg>
                    </button>
                </div>
                <div class="mt-1 flex items-center justify-between px-1 text-[10px] text-slate-400">
                    <span>UniRegistre AI • Validation humaine requise pour les actes</span>
                    <span x-text="nouveauMessage.length + '/4000'"></span>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function uniregistreAiPopup() {
    return {
        ouvert: false,
        chargement: false,
        chargementAction: false,
        nomActeur: '',
        portail: 'admin',
        portailLibelle: 'Portail',
        fournisseurActif: 'Local',
        conversationUuid: null,
        messages: [],
        suggestions: [],
        contextePage: {},
        nouveauMessage: '',
        erreurMessage: '',

        initPopup() {
            // Lecture automatique de la route courante depuis les métadonnées de la page si disponibles
            const metaRoute = document.querySelector('meta[name="current-route"]')?.content || '';
            this.initialiserContexte(metaRoute);
        },

        basculerOuverture() {
            this.ouvert = !this.ouvert;
            if (this.ouvert && this.messages.length === 0 && !this.conversationUuid) {
                this.creerOuReprendreConversation();
            }
            if (this.ouvert) {
                this.$nextTick(() => {
                    const textarea = document.querySelector('#dialog-uniregistre-ai textarea');
                    if (textarea) textarea.focus();
                });
            }
        },

        siOuvertFermer() {
            if (this.ouvert) {
                this.ouvert = false;
            }
        },

        async initialiserContexte(routeName = '') {
            try {
                const response = await fetch('/assistant-ia/popup/initialiser?route_name=' + encodeURIComponent(routeName), {
                    headers: { 'Accept': 'application/json' }
                });
                if (response.ok) {
                    const data = await response.json();
                    if (data.succes) {
                        this.nomActeur = data.acteur.nom;
                        this.portail = data.acteur.portail;
                        this.portailLibelle = this.portail === 'etudiant' ? 'Étudiant' : (this.portail === 'enseignant' ? 'Enseignant' : 'Administration');
                        this.fournisseurActif = data.fournisseurs.fournisseur_actif === 'groq' ? 'GroqCloud' : 'Local';
                        this.contextePage = data.contexte || {};
                        this.suggestions = data.contexte.suggestions || [];
                        if (data.conversations && data.conversations.length > 0) {
                            this.conversationUuid = data.conversations[0].uuid;
                            this.chargerConversation(this.conversationUuid);
                        }
                    }
                }
            } catch (e) {
                console.warn('Initialisation UniRegistre AI popup différée', e);
            }
        },

        async creerOuReprendreConversation() {
            try {
                const response = await fetch('/assistant-ia/popup/conversations', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                    },
                    body: JSON.stringify({ mode: 'metier' })
                });
                if (response.ok) {
                    const data = await response.json();
                    if (data.succes) {
                        this.conversationUuid = data.conversation.uuid;
                        this.messages = [];
                    }
                }
            } catch (e) {
                console.error('Erreur création conversation IA', e);
            }
        },

        async chargerConversation(uuid) {
            try {
                const response = await fetch('/assistant-ia/popup/conversations/' + uuid, {
                    headers: { 'Accept': 'application/json' }
                });
                if (response.ok) {
                    const data = await response.json();
                    if (data.succes && data.messages) {
                        this.messages = data.messages.map(m => ({
                            role: m.role,
                            contenu: m.contenu,
                            citations: m.sources || [],
                            action_proposee: null,
                            action_resultat: null
                        }));
                        this.faireDefilerVersBas();
                    }
                }
            } catch (e) {
                console.warn('Erreur chargement conversation', e);
            }
        },

        choisirSuggestion(texte) {
            this.nouveauMessage = texte;
            this.envoyerMessage();
        },

        async envoyerMessage() {
            const texte = this.nouveauMessage.trim();
            if (!texte || this.chargement) return;

            if (!this.conversationUuid) {
                await this.creerOuReprendreConversation();
            }

            // Ajout immédiat côté interface
            this.messages.push({
                role: 'user',
                contenu: texte,
                heure: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
            });

            this.nouveauMessage = '';
            this.chargement = true;
            this.erreurMessage = '';
            this.faireDefilerVersBas();

            try {
                const response = await fetch('/assistant-ia/popup/conversations/' + this.conversationUuid + '/messages', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                    },
                    body: JSON.stringify({
                        contenu: texte,
                        route_name: this.contextePage.ecran || '',
                        allow_remote: false
                    })
                });

                const data = await response.json();

                if (response.ok && data.succes) {
                    this.messages.push({
                        role: 'assistant',
                        contenu: data.reponse.texte,
                        citations: data.citations || [],
                        action_proposee: data.reponse.appels_outils?.find(a => a.outil === 'preparer_action_scolarite')?.arguments || null,
                        heure: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
                    });
                } else if (response.status === 428) {
                    this.erreurMessage = "Consentement requis pour inférence distante. Veuillez valider le défi de transfert.";
                } else {
                    this.erreurMessage = data.erreur || "Une erreur est survenue lors du traitement.";
                }
            } catch (e) {
                this.erreurMessage = "Impossible de joindre le service UniRegistre AI.";
            } finally {
                this.chargement = false;
                this.faireDefilerVersBas();
            }
        },

        async confirmerAction(actionUuid) {
            if (!actionUuid || this.chargementAction) return;

            this.chargementAction = true;
            try {
                const response = await fetch('/assistant-ia/popup/actions/' + actionUuid + '/confirmer', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                    }
                });

                const data = await response.json();

                if (response.ok && data.succes) {
                    const dernierMsg = this.messages[this.messages.length - 1];
                    if (dernierMsg) {
                        dernierMsg.action_proposee = null;
                        dernierMsg.action_resultat = data;
                    }
                } else {
                    alert(data.erreur || "Échec de confirmation de l'action.");
                }
            } catch (e) {
                alert("Erreur lors de la confirmation de l'action.");
            } finally {
                this.chargementAction = false;
            }
        },

        nouvelleConversation() {
            this.conversationUuid = null;
            this.messages = [];
            this.creerOuReprendreConversation();
        },

        faireDefilerVersBas() {
            this.$nextTick(() => {
                const flux = document.getElementById('flux-messages-ai');
                if (flux) flux.scrollTop = flux.scrollHeight;
            });
        }
    };
}
</script>
