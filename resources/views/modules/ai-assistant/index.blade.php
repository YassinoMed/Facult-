@extends('layouts.app')

@section('title', 'Assistant IA')

@php
    /** @var \Illuminate\Contracts\Pagination\LengthAwarePaginator $conversations */
    /** @var \App\Modules\AiAssistant\Models\AiConversation|null $convActive */
    /** @var \Illuminate\Support\Collection<int, \App\Modules\AiAssistant\Models\AiMessage> $messages */
    /** @var \App\Models\User $utilisateur */
    $conversationsInitiales = collect($conversations->items())->map(fn ($c) => [
        'id' => $c->id,
        'titre' => (string) $c->titre,
        'mode' => (string) $c->mode,
    ])->values()->all();

    $convInitiale = $convActive === null ? null : [
        'id' => $convActive->id,
        'titre' => (string) $convActive->titre,
        'mode' => (string) $convActive->mode,
    ];

    $messagesInitiaux = $messages->map(fn ($m) => [
        'id' => $m->id,
        'role' => (string) $m->role,
        'contenu' => (string) $m->contenu,
        'sources' => $m->sources ?? [],
    ])->values()->all();
@endphp

@section('content')
    <x-page-header
        titre="Assistant IA"
        description="Assistant hybride (développeur / métier) — réponses citées, données filtrées, actions supervisées par approbation."
    />

    <div x-data="chatAi({
        routeIndex: @json(route('admin.ai.conversations.index')),
        routeStore: @json(route('admin.ai.conversations.store')),
        routeMessages: @json(route('admin.ai.conversations.messages', '__ID__')),
        routeSend: @json(route('admin.ai.conversations.messages.store', '__ID__')),
        routeDelete: @json(route('admin.ai.conversations.destroy', '__ID__')),
        initialConv: @json($convInitiale),
        initialMessages: @json($messagesInitiaux),
        initialConversations: @json($conversationsInitiales),
        peutDeveloppeur: @json($peutDeveloppeur)
    })"
        class="grid gap-6 lg:grid-cols-[280px_1fr]"
    >

        {{-- Conversations PRIVÉES (isolation serveur : policy + scopes) --}}
        <aside class="hidden lg:block">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-sm font-semibold text-slate-700">Conversations</h2>
                <button type="button" class="btn btn-ghost text-xs" aria-label="Nouvelle conversation"
                        @click="nouvelleConversation()">+ Nouvelle</button>
            </div>

            <template x-if="conversations.length === 0">
                <p class="text-xs text-slate-500">Aucune conversation pour l’instant.</p>
            </template>

            <ul class="space-y-2" role="list">
                <template x-for="conv in conversations" :key="conv.id">
                    <li class="flex items-center gap-1">
                        <button type="button"
                                class="btn btn-ghost flex-1 text-left justify-between truncate"
                                :class="conv.id === (conversationCourante?.id ?? 0) ? 'ring-1 ring-indigo-400' : ''"
                                @click="ouvrir(conv.id)">
                            <span class="truncate" x-text="conv.titre"></span>
                            <span class="text-[10px] uppercase text-slate-500"
                                  x-text="conv.mode === 'developpeur' ? 'dev' : 'métier'"></span>
                        </button>
                        <button type="button" class="btn btn-ghost text-xs text-red-600"
                                @click="supprimer(conv.id)" aria-label="Supprimer la conversation">✕</button>
                    </li>
                </template>
            </ul>

            <template x-if="encoreDesConversations">
                <button type="button" class="btn btn-ghost text-xs mt-2"
                        @click="page++ ; chargerConversations()">Suivantes…</button>
            </template>
        </aside>

        {{-- Fil de chat --}}
        <section class="rounded-xl border border-slate-200 bg-white shadow-sm"
                 :data-mode="mode">
            <header class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 border-b border-slate-200">
                <div class="min-w-0">
                    <h2 class="text-base font-semibold text-slate-800 truncate"
                        x-text="conversationCourante ? conversationCourante.titre : 'Assistant IA'"></h2>
                    <p class="text-xs text-slate-500" x-text="fournisseurAffichage()"></p>
                </div>

                <nav class="flex items-center gap-3" aria-label="Mode de l’assistant"
                     x-show="!conversationCourante || conversationCourante.mode === mode">
                    <label class="flex items-center gap-1 text-xs cursor-pointer">
                        <input type="radio" name="mode-ia" value="metier" x-model="mode">
                        <span>Métier</span>
                    </label>
                    <template x-if="peutDeveloppeur">
                        <label class="flex items-center gap-1 text-xs cursor-pointer">
                            <input type="radio" name="mode-ia" value="developpeur" x-model="mode">
                            <span>Développeur</span>
                        </label>
                    </template>
                </nav>
            </header>

            <div class="px-4 py-4 min-h-[280px] max-h-[60vh] overflow-y-auto" x-ref="fil">
                {{-- état vide --}}
                <template x-if="!conversationCourante">
                    <x-empty-state
                        titre="Pas de conversation active"
                        description="Choisissez ou créez une conversation, puis posez votre question en langage naturel."
                    />
                </template>

                {{-- messages (x-text UNIQUEMENT : jamais de HTML brut du modèle) --}}
                <div class="space-y-3">
                    <template x-for="message in messages" :key="message.id">
                        <article :class="message.role === 'assistant'
                            ? 'bg-slate-50 rounded-lg p-3'
                            : 'bg-indigo-50 rounded-lg p-3 ml-auto max-w-[85%]'">
                            <p class="text-xs uppercase tracking-wide text-slate-500"
                               x-text="message.role === 'assistant' ? 'Assistant IA' : 'Vous'"></p>
                            <div class="mt-1 text-sm whitespace-pre-wrap break-words text-slate-800"
                                 x-text="message.contenu"></div>

                            <details x-show="message.sources && message.sources.length > 0" class="mt-2">
                                <summary class="text-xs text-slate-500 cursor-pointer">Sources citées</summary>
                                <ul class="mt-1 space-y-1" role="list">
                                    <template x-for="(source, index) in message.sources" :key="message.id + '-' + index">
                                        <li class="text-xs text-slate-600">
                                            <span x-text="source.titre"></span>
                                            <code class="text-[11px] break-all" x-text="source.chemin"></code>
                                        </li>
                                    </template>
                                </ul>
                            </details>
                        </article>
                    </template>

                    {{-- état de chargement --}}
                    <template x-if="chargementReponse">
                        <div class="flex items-center gap-2 text-sm text-slate-500" role="status" aria-live="polite">
                            <span class="inline-block size-4 animate-spin rounded-full border-2 border-slate-300 border-t-slate-600"></span>
                            <span>L’assistant rédige…</span>
                        </div>
                    </template>

                    {{-- erreur fail-soft (jamais de détail interne) --}}
                    <template x-if="erreur">
                        <div class="alert alert-error text-sm" role="alert" x-text="erreur"></div>
                    </template>
                </div>
            </div>

            {{-- Composer --}}
            <footer x-show="conversationCourante" class="px-4 py-3 border-t border-slate-200">
                <form class="flex flex-col sm:flex-row items-stretch sm:items-end gap-2"
                      @submit.prevent="envoyer()">
                    <label class="sr-only" for="message-ia">Message pour l’assistant</label>
                    <textarea id="message-ia" rows="2" maxlength="4000" x-model="draft"
                              class="flex-1 border rounded-lg px-2 py-1 text-sm focus:border-indigo-400 focus:ring-1"
                              @keydown.enter.prevent="envoyer()"
                              @keydown.escape.window="draft = ''"
                              placeholder="Posez votre question ou décrivez le problème…"></textarea>
                    <button type="submit" class="btn btn-primary"
                            :disabled="chargementReponse || draft.trim() === ''">Envoyer</button>
                </form>
                <p class="mt-1 text-[11px] text-slate-400">
                    Les données sensibles sont filtrées côté serveur ; aucune information personnelle n’est transmise au fournisseur.
                </p>
            </footer>
        </section>
    </div>

    @push('scripts')
        <script nonce>
            function chatAi(config) {
                return {
                    conversations: config.initialConversations ?? [],
                    messages: config.initialMessages ?? [],
                    conversationCourante: config.initialConv ?? null,
                    mode: (config.initialConv?.mode === 'developpeur' && config.peutDeveloppeur) ? 'developpeur' : 'metier',
                    draft: '',
                    chargementReponse: false,
                    encoreDesConversations: false,
                    page: 1,
                    erreur: null,
                    providerNom: null,
                    providerModele: null,

                    init() { this.$watch('messages', () => this.scrollBas()); },

                    csrf() {
                        return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                    },

                    url(route) { return route.replace('__ID__', this.conversationCourante?.id ?? ''); },

                    async chargerConversations() {
                        try {
                            const reponse = await fetch(`${config.routeIndex}?page=${this.page}`, {
                                headers: { 'Accept': 'application/json' }
                            });
                            if (!reponse.ok) { this.erreur = 'Chargement des conversations impossible.'; return; }
                            const donnees = await reponse.json();
                            if (this.page === 1) this.conversations = [];
                            this.conversations.push(...(donnees.data ?? []));
                            this.encoreDesConversations = this.page * 20 < (donnees.total ?? 0);
                        } catch {
                            this.erreur = 'Chargement des conversations impossible.';
                        }
                    },

                    async ouvrir(id) {
                        this.erreur = null;
                        try {
                            const reponse = await fetch(config.routeMessages.replace('__ID__', id), {
                                headers: { 'Accept': 'application/json' }
                            });
                            if (!reponse.ok) { this.erreur = 'Conversation indisponible.'; return; }
                            const donnees = await reponse.json();
                            this.conversationCourante = donnees.conversation;
                            this.messages = donnees.messages ?? [];
                            this.mode = this.conversationCourante.mode ?? 'metier';
                        } catch {
                            this.erreur = 'Conversation indisponible.';
                        }
                    },

                    async nouvelleConversation() {
                        this.erreur = null;
                        try {
                            const reponse = await fetch(config.routeStore, {
                                method: 'POST',
                                headers: {
                                    'Accept': 'application/json',
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': this.csrf(),
                                },
                                body: JSON.stringify({
                                    mode: this.mode,
                                    titre: this.mode === 'developpeur' ? 'Conversation développeur' : 'Nouvelle conversation',
                                })
                            });
                            if (!reponse.ok) {
                                const corps = await reponse.json().catch(() => ({}));
                                this.erreur = corps.message || 'Création refusée.';
                                return;
                            }
                            const donnees = await reponse.json();
                            this.conversationCourante = donnees.conversation;
                            this.messages = [];
                            this.conversations.unshift(donnees.conversation);
                            window.setTimeout(() => document.getElementById('message-ia')?.focus(), 30);
                        } catch {
                            this.erreur = 'Création impossible.';
                        }
                    },

                    async envoyer() {
                        const contenu = this.draft.trim();
                        if (!contenu || !this.conversationCourante?.id) return;

                        this.draft = '';
                        this.chargementReponse = true;
                        this.erreur = null;

                        this.messages.push({
                            id: `attente-${Date.now()}`, role: 'user', contenu, sources: [],
                        });

                        try {
                            const reponse = await fetch(this.url(config.routeSend), {
                                method: 'POST',
                                headers: {
                                    'Accept': 'application/json',
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': this.csrf(),
                                },
                                body: JSON.stringify({ contenu })
                            });

                            if (!reponse.ok) {
                                const corps = await reponse.json().catch(() => ({}));
                                this.erreur = corps.message || 'Réponse IA indisponible pour le moment.';
                                return;
                            }

                            const donnees = await reponse.json();
                            this.messages.push(donnees.message);
                            this.providerNom = donnees.message.fournisseur;
                            this.providerModele = donnees.message.modele;
                        } catch {
                            this.erreur = 'Réponse IA indisponible pour le moment.';
                        } finally {
                            this.chargementReponse = false;
                        }
                    },

                    async supprimer(id) {
                        if (!window.confirm('Supprimer cette conversation ?')) return;
                        try {
                            const reponse = await fetch(config.routeDelete.replace('__ID__', id), {
                                method: 'DELETE',
                                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf() }
                            });
                            if (reponse.ok) {
                                this.conversations = this.conversations.filter(c => c.id !== id);
                                if (this.conversationCourante?.id === id) {
                                    this.conversationCourante = null;
                                    this.messages = [];
                                }
                            }
                        } catch { /* fail-soft : la liste reste inchangée */ }
                    },

                    fournisseurAffichage() {
                        const nom = this.providerNom ?? 'à la première réponse';
                        const modele = this.providerModele ?? '';
                        return `Fournisseur : ${nom}${modele ? ' · modèle : ' + modele : ''}`;
                    },

                    scrollBas() {
                        window.setTimeout(() => {
                            const fil = this.$refs.fil;
                            if (fil) fil.scrollTop = fil.scrollHeight;
                        });
                    },
                };
            }
        </script>
    @endpush
@endsection
