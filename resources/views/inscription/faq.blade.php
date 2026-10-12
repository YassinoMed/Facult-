@extends('layouts.app')

@section('title', 'Assistant & FAQ officielle — Inscriptions universitaires')
@section('meta_description', 'Assistant officiel et questions fréquentes sur l\'inscription universitaire : périodes, pièces, suivi du dossier et orientation vers la scolarité.')

@section('content')
<div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">

    <header class="text-center">
        <p class="text-xs font-bold uppercase tracking-[0.25em] text-primary-700">Inscriptions universitaires</p>
        <h1 class="mt-2 text-3xl font-black tracking-tight text-slate-900 sm:text-4xl">Assistant & Questions fréquentes</h1>
        <p class="mx-auto mt-3 max-w-2xl text-sm leading-relaxed text-slate-600">
            Réponses officielles établies à partir des règles et procédures réelles du parcours d'inscription.
            Chaque réponse cite sa page de référence officielle sans inventer de règles.
        </p>
        <div class="mt-5 flex flex-wrap justify-center gap-3">
            <a href="{{ route('inscription.create') }}"
               class="inline-flex items-center gap-2 rounded-xl bg-primary-800 px-5 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-primary-700 transition">
                S'inscrire
            </a>
            <a href="#autres-questions"
               class="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-5 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50 transition">
                Contacter la scolarité
            </a>
        </div>
    </header>

    {{-- Assistant interactif fondé sur les sources officielles (LOOP 15) --}}
    <section class="mt-8 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8"
             aria-labelledby="titre-assistant"
             x-data="assistantFaq({
                 initialQuestion: '{{ addslashes($questionRecherchee ?? '') }}',
                 initialResultat: {{ json_encode($reponseAssistant ?? null) }},
                 urlEndpoint: '{{ route('inscription.faq.assistant') }}',
                 csrfToken: '{{ csrf_token() }}'
             })">
        <div class="flex items-center justify-between gap-3">
            <div>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-primary-50 px-3 py-1 text-xs font-black text-primary-800">
                    <svg class="h-3.5 w-3.5 text-primary-600" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z" />
                    </svg>
                    Assistant officiel
                </span>
                <h2 id="titre-assistant" class="mt-2 text-lg font-black text-slate-900 sm:text-xl">
                    Interroger l'assistant d'inscription
                </h2>
                <p class="mt-1 text-xs text-slate-500">
                    Posez votre question en langage naturel : réponses déterministes et sourcées.
                </p>
            </div>
        </div>

        <form method="GET" action="{{ route('inscription.faq') }}" @submit.prevent="soumettre" class="mt-5">
            <div class="relative flex items-center">
                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4 text-slate-400">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                    </svg>
                </div>
                <input type="text"
                       name="q"
                       x-model="question"
                       @input.debounce.400ms="interrogerAutomatique"
                       placeholder="Ex. : Quand s'inscrire ? Quelles pièces préparer ? Mot de passe perdu ?"
                       aria-label="Votre question sur l'inscription"
                       class="block w-full rounded-2xl border border-slate-300 bg-slate-50/50 py-3.5 pl-11 pr-28 text-sm text-slate-900 shadow-inner placeholder:text-slate-400 focus:border-primary-600 focus:bg-white focus:outline-none focus:ring-4 focus:ring-primary-500/10 transition" />
                <div class="absolute inset-y-0 right-1.5 flex items-center gap-1">
                    <button type="button"
                            x-show="question.length > 0"
                            @click="effacer"
                            class="rounded-xl p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition"
                            title="Effacer la recherche">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                    <button type="submit"
                            :disabled="chargement || question.trim().length === 0"
                            class="inline-flex items-center gap-1.5 rounded-xl bg-primary-800 px-4 py-2 text-xs font-bold text-white hover:bg-primary-700 disabled:opacity-50 transition">
                        <span x-show="!chargement">Rechercher</span>
                        <span x-show="chargement" class="inline-flex items-center gap-1">
                            <svg class="h-3 w-3 animate-spin text-white" viewBox="0 0 24 24" fill="none">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                            </svg>
                        </span>
                    </button>
                </div>
            </div>
        </form>

        {{-- Suggestions rapides en puces --}}
        <div class="mt-3 flex flex-wrap items-center gap-1.5 text-xs">
            <span class="font-medium text-slate-500">Exemples :</span>
            <button type="button" @click="poserExemple('Quand puis-je m\'inscrire ?')" class="rounded-lg bg-slate-100 px-2.5 py-1 text-slate-700 hover:bg-primary-50 hover:text-primary-800 transition">Quand s'inscrire ?</button>
            <button type="button" @click="poserExemple('Quelles pièces dois-je préparer ?')" class="rounded-lg bg-slate-100 px-2.5 py-1 text-slate-700 hover:bg-primary-50 hover:text-primary-800 transition">Pièces requises</button>
            <button type="button" @click="poserExemple('Comment suivre mon dossier ?')" class="rounded-lg bg-slate-100 px-2.5 py-1 text-slate-700 hover:bg-primary-50 hover:text-primary-800 transition">Suivre mon dossier</button>
            <button type="button" @click="poserExemple('J\'ai perdu mon mot de passe')" class="rounded-lg bg-slate-100 px-2.5 py-1 text-slate-700 hover:bg-primary-50 hover:text-primary-800 transition">Mot de passe perdu</button>
        </div>

        {{-- Carte de résultat de l'assistant --}}
        <div x-show="resultat !== null"
             x-transition
             class="mt-6 border-t border-slate-100 pt-6">

            {{-- Cas 1 : Réponse officielle vérifiée (statut repondu) --}}
            <template x-if="resultat && resultat.statut === 'repondu'">
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50/50 p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex h-7 w-7 items-center justify-center rounded-xl bg-emerald-600 text-white shadow-sm">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                </svg>
                            </span>
                            <div>
                                <template x-if="resultat.confiance === 'haute'">
                                    <span class="rounded-md bg-emerald-100 px-2 py-0.5 text-[10px] font-black uppercase tracking-wider text-emerald-800">
                                        Source officielle vérifiée
                                    </span>
                                </template>
                                {{-- Confiance moyenne (LOOP 15 §cahier) : la réponse
                                     est présentée comme INCERTAINE, jamais comme
                                     acquise — orientation vers la scolarité. --}}
                                <template x-if="resultat.confiance !== 'haute'">
                                    <span class="rounded-md bg-amber-100 px-2 py-0.5 text-[10px] font-black uppercase tracking-wider text-amber-900">
                                        Réponse indicative — pertinence moyenne, à confirmer auprès de la scolarité
                                    </span>
                                </template>
                                <h3 class="mt-1 text-sm font-bold text-slate-900" x-text="resultat.question_officielle"></h3>
                            </div>
                        </div>
                        <span class="text-[11px] font-medium text-emerald-700" x-text="'Pertinence ' + Math.round((resultat.score || 1) * 100) + '%'"></span>
                    </div>

                    <p class="mt-3 text-sm leading-relaxed text-slate-700" x-text="resultat.reponse"></p>

                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-emerald-200/60 pt-3 text-xs">
                        <div class="text-slate-600">
                            Source de référence : <span class="font-bold text-slate-800" x-text="resultat.source_libelle"></span>
                        </div>
                        <template x-if="resultat.source_url">
                            <a :href="resultat.source_url"
                               class="inline-flex items-center gap-1 font-bold text-emerald-800 underline decoration-emerald-400 hover:text-emerald-950">
                                Consulter la page officielle
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                                </svg>
                            </a>
                        </template>
                    </div>
                </div>
            </template>

            {{-- Cas 2 : Question incertaine / hors périmètre (statut incertain) --}}
            <template x-if="resultat && resultat.statut === 'incertain'">
                <div class="rounded-2xl border border-amber-200 bg-amber-50/60 p-5">
                    <div class="flex items-start gap-3">
                        <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-xl bg-amber-600 text-white shadow-sm">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                            </svg>
                        </span>
                        <div>
                            <span class="rounded-md bg-amber-100 px-2 py-0.5 text-[10px] font-black uppercase tracking-wider text-amber-900">
                                Information non répertoriée — Règle non inventée
                            </span>
                            <p class="mt-2 text-sm leading-relaxed text-slate-800" x-text="resultat.reponse"></p>

                            <div class="mt-4 rounded-xl border border-amber-200 bg-white/80 p-3 text-xs">
                                <p class="font-bold text-amber-950">Contact du service scolarité :</p>
                                <p class="mt-1 text-slate-700">
                                    E-mail : <a :href="'mailto:' + (resultat.contact_scolarite ? resultat.contact_scolarite.email : '')" class="font-semibold text-primary-800 underline" x-text="resultat.contact_scolarite ? resultat.contact_scolarite.email : 'scolarite@universite.ac.ma'"></a>
                                    <span class="mx-1.5 text-slate-300">|</span>
                                    Horaires : <span class="font-medium text-slate-600" x-text="resultat.contact_scolarite ? resultat.contact_scolarite.horaires : 'Horaires d\'ouverture'"></span>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            {{-- Cas 3 : Refus de consultation privée / Données personnelles (statut refus_prive) --}}
            <template x-if="resultat && resultat.statut === 'refus_prive'">
                <div class="rounded-2xl border border-indigo-200 bg-indigo-50/60 p-5">
                    <div class="flex items-start gap-3">
                        <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-xl bg-indigo-600 text-white shadow-sm">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                            </svg>
                        </span>
                        <div>
                            <span class="rounded-md bg-indigo-100 px-2 py-0.5 text-[10px] font-black uppercase tracking-wider text-indigo-900">
                                Confidentialité & Protection des données privées
                            </span>
                            <p class="mt-2 text-sm leading-relaxed text-slate-800" x-text="resultat.reponse"></p>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <template x-if="resultat.source_url">
                                    <a :href="resultat.source_url"
                                       class="inline-flex items-center gap-1.5 rounded-xl bg-indigo-700 px-4 py-2 text-xs font-bold text-white hover:bg-indigo-800 transition">
                                        Se connecter à l'espace étudiant
                                    </a>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            {{-- Cas 4 : Tentative d'action de mutation (statut action_refusee) --}}
            <template x-if="resultat && resultat.statut === 'action_refusee'">
                <div class="rounded-2xl border border-slate-300 bg-slate-50 p-5">
                    <div class="flex items-start gap-3">
                        <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-xl bg-slate-600 text-white shadow-sm">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 0 0 5.636 5.636m12.728 12.728A9 9 0 0 1 5.636 5.636m12.728 12.728L5.636 5.636" />
                            </svg>
                        </span>
                        <div>
                            <span class="rounded-md bg-slate-200 px-2 py-0.5 text-[10px] font-black uppercase tracking-wider text-slate-800">
                                Outil en lecture seule — Aucune action métier
                            </span>
                            <p class="mt-2 text-sm leading-relaxed text-slate-800" x-text="resultat.reponse"></p>
                        </div>
                    </div>
                </div>
            </template>

            {{-- Suggestions officielles (LOOP 15) : questions approuvées
                 retournées par le service, cliquables pour reposer sans
                 ressaisie — réduit les questions hors périmètre. --}}
            <template x-if="resultat && (resultat.suggestions || []).length > 0">
                <div class="mt-4 flex flex-wrap items-center gap-1.5 text-xs">
                    <span class="font-medium text-slate-500">Voir aussi :</span>
                    <template x-for="suggestion in (resultat.suggestions || [])" :key="suggestion">
                        <button type="button"
                                @click="poserExemple(suggestion)"
                                class="rounded-lg bg-slate-100 px-2.5 py-1 text-slate-700 hover:bg-primary-50 hover:text-primary-800 transition"
                                x-text="suggestion"></button>
                    </template>
                </div>
            </template>

            {{-- Échec technique (LOOP 15/17 : état d'erreur explicite) :
                 jamais de spinner qui s'éteint sans explication — le
                 dépôt/la question n'est jamais perdu, cap vers l'humain. --}}
            <div x-show="erreur && !chargement"
                 class="mt-6 rounded-2xl border border-rose-200 bg-rose-50/60 p-5"
                 role="alert">
                <p class="text-sm font-bold text-rose-900">Service momentanément indisponible</p>
                <p class="mt-1 text-sm text-slate-700">
                    Votre question n'a pas pu être traitée pour le moment. Réessayez dans quelques instants
                    ou contactez directement la scolarité : {{ $contact['email'] ?? '' }} — {{ $contact['horaires'] ?? '' }}.
                </p>
            </div>
        </div>
    </section>

    {{-- Fallback sans JavaScript côté serveur si paramètre q présent --}}
    @if(isset($reponseAssistant) && $reponseAssistant !== null)
        <noscript>
            <div class="mt-6 rounded-2xl border border-primary-200 bg-primary-50/50 p-5">
                <span class="text-xs font-bold uppercase text-primary-800">Réponse de l'assistant officiel :</span>
                <p class="mt-2 text-sm text-slate-800">{{ $reponseAssistant['reponse'] }}</p>
                <p class="mt-3 text-xs text-slate-500">
                    Source : <strong class="text-slate-800">{{ $reponseAssistant['source_libelle'] }}</strong>
                </p>
            </div>
        </noscript>
    @endif

    @php
        $categories = collect($entrees)->groupBy('categorie');
    @endphp

    <nav aria-label="Catégories de questions" class="mt-12 flex flex-wrap justify-center gap-2">
        @foreach($categories as $categorie => $entreesGroupees)
            <a href="#{{ \Illuminate\Support\Str::slug($categorie) }}"
               class="rounded-full border border-slate-200 bg-white px-4 py-1.5 text-xs font-bold text-slate-700 hover:border-primary-500 hover:text-primary-800 transition">
                {{ $categorie }} <span class="ml-1 text-slate-400">({{ $entreesGroupees->count() }})</span>
            </a>
        @endforeach
    </nav>

    @foreach($categories as $categorie => $entreesGroupees)
        <section id="{{ \Illuminate\Support\Str::slug($categorie) }}"
                 aria-labelledby="titre-{{ \Illuminate\Support\Str::slug($categorie) }}"
                 class="mt-10 scroll-mt-24">
            <h2 id="titre-{{ \Illuminate\Support\Str::slug($categorie) }}"
                class="text-lg font-black uppercase tracking-wider text-slate-900">{{ $categorie }}</h2>

            <div class="mt-4 space-y-3">
                @foreach($entreesGroupees as $entree)
                    <details class="group rounded-2xl border border-slate-200 bg-white shadow-sm open:border-primary-300 open:ring-2 open:ring-primary-500/15 transition">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 text-sm font-bold text-slate-900 [&::-webkit-details-marker]:hidden">
                            {{ $entree['question'] }}
                            <svg class="h-4 w-4 shrink-0 text-slate-400 transition group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                            </svg>
                        </summary>
                        <div class="border-t border-slate-100 px-5 py-4">
                            <p class="text-sm leading-relaxed text-slate-700">{{ $entree['reponse'] }}</p>
                            <p class="mt-3 text-xs text-slate-500">
                                Source : <span class="font-semibold text-slate-700">{{ $entree['source_libelle'] }}</span>
                                @if(!empty($entree['source_route']) && \Illuminate\Support\Facades\Route::has($entree['source_route']))
                                    — <a href="{{ route($entree['source_route']) }}" class="font-semibold text-primary-700 underline decoration-primary-300 hover:text-primary-900">voir la page</a>
                                @endif
                            </p>
                        </div>
                    </details>
                @endforeach
            </div>
        </section>
    @endforeach

    <section id="autres-questions" aria-labelledby="titre-autres" class="mt-12 scroll-mt-24 rounded-2xl border-2 border-dashed border-primary-300 bg-primary-50/60 p-6 text-center">
        <h2 id="titre-autres" class="text-lg font-black text-primary-900">Votre question n'est pas traitée ici ?</h2>
        <p class="mx-auto mt-2 max-w-xl text-sm leading-relaxed text-primary-900/80">
            Les situations personnelles (dossier en cours, pièce précise, décision) ne peuvent être traitées
            que par un agent : contactez la scolarité de votre faculté.
        </p>
        <p class="mt-3 text-sm font-bold text-primary-900">
            {{ $contact['email'] ?? '' }} — {{ $contact['horaires'] ?? '' }}
        </p>
        @if(session()->has('etudiant_auth_id') && app(\App\Support\Modules\ModuleRegistry::class)->estActif('online-requests'))
            <a href="{{ route('etudiant.demandes.create') }}"
               class="mt-4 inline-flex items-center gap-2 rounded-xl bg-primary-800 px-5 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-primary-700 transition">
                Déposer une demande d'assistance
            </a>
        @endif
    </section>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('assistantFaq', (config) => ({
        question: config.initialQuestion || '',
        resultat: config.initialResultat || null,
        chargement: false,
        erreur: false,
        urlEndpoint: config.urlEndpoint,
        csrfToken: config.csrfToken,

        effacer() {
            this.question = '';
            this.resultat = null;
            this.erreur = false;
        },

        poserExemple(texte) {
            this.question = texte;
            this.soumettre();
        },

        async interrogerAutomatique() {
            if (this.question.trim().length >= 3) {
                await this.soumettre();
            } else if (this.question.trim().length === 0) {
                this.resultat = null;
                this.erreur = false;
            }
        },

        async soumettre() {
            const q = this.question.trim();
            if (!q) return;

            this.chargement = true;
            this.erreur = false;
            try {
                const response = await fetch(this.urlEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ question: q })
                });

                if (response.ok) {
                    this.resultat = await response.json();
                } else {
                    // 422/429/5xx : état d'erreur explicite + cap vers
                    // l'humain (jamais de spinner silencieux).
                    this.erreur = true;
                }
            } catch (err) {
                this.erreur = true;
            } finally {
                this.chargement = false;
            }
        }
    }));
});
</script>
@endsection
