@extends('layouts.app')

@section('title', 'UniRegistre AI — Assistant Intelligent')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6" x-data="assistantIa({
    conversationUuid: '{{ $conversationActive?->uuid ?? '' }}',
    csrfToken: '{{ csrf_token() }}',
    urls: {
        envoyer: '{{ $conversationActive ? route('admin.assistant-ia.messages.envoyer', $conversationActive->uuid) : '' }}',
        creer: '{{ route('admin.assistant-ia.conversations.creer') }}',
        base: '{{ route('admin.assistant-ia.index') }}'
    }
})">
    <!-- En-tête de section -->
    <div class="mb-6 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center justify-center p-2 rounded-xl bg-indigo-600 text-white shadow-md shadow-indigo-200">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </span>
                <h1 class="text-2xl font-bold text-gray-900">UniRegistre AI</h1>
                <span class="text-xs px-2.5 py-0.5 rounded-full font-semibold bg-indigo-100 text-indigo-800 border border-indigo-200">
                    Assistant Hybride
                </span>
            </div>
            <p class="text-sm text-gray-600 mt-1">
                Assistance agentique pour l'administration académique et l'exploration technique vérifiable.
            </p>
        </div>

        <div class="flex items-center gap-3">
            <!-- Jauge de quotas -->
            <div class="bg-white border border-gray-200 rounded-xl px-3 py-1.5 shadow-sm text-xs flex items-center gap-2">
                <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                <span class="text-gray-500">Quota journalier:</span>
                <span class="font-bold text-gray-800">{{ $consommation['consomme'] }} / {{ $consommation['plafond'] }}</span>
            </div>

            <!-- Badge Fournisseur Hybride -->
            <div class="bg-gray-100 border border-gray-200 rounded-xl px-3 py-1.5 text-xs text-gray-700 flex items-center gap-2">
                @if(in_array(($conversationActive?->fournisseur ?? $nomFournisseur), ['distant', 'groq'], true))
                    <span class="w-2 h-2 rounded-full bg-sky-500 animate-pulse" title="Fournisseur Cloud Autorisé"></span>
                    <span class="font-semibold text-sky-800">{{ ($conversationActive?->fournisseur ?? $nomFournisseur) === 'groq' ? 'GroqCloud' : 'Distant' }} : {{ $nomModele }}</span>
                @else
                    <span class="w-2 h-2 rounded-full bg-emerald-500" title="Fournisseur Local Souverain"></span>
                    <span class="font-semibold text-emerald-800">Local-First : {{ $nomModele }}</span>
                @endif
                <span class="text-[10px] bg-white border border-gray-300 rounded px-1.5 py-0.2 font-mono text-gray-500">
                    {{ in_array(($conversationActive?->fournisseur ?? $nomFournisseur), ['distant', 'groq'], true) ? 'EXTERNE CLOUD' : 'SOUVERAIN' }}
                </span>
            </div>

            @can('assistant_ia.admin')
                <a href="{{ route('admin.assistant-ia.sources.index') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-indigo-200 bg-indigo-50 hover:bg-indigo-100 text-xs font-semibold text-indigo-700 transition">
                    <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                    </svg>
                    <span>Knowledge Hub</span>
                </a>
            @endcan
        </div>
    </div>

    <!-- Alertes et erreurs éventuelles -->
    @if(session('erreur_ia') || $errors->has('erreur_ia'))
        <div class="mb-4 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-sm flex items-center gap-3">
            <svg class="w-5 h-5 flex-shrink-0 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
            <div>
                <span class="font-semibold">Information :</span>
                {{ session('erreur_ia') ?: $errors->first('erreur_ia') }}
            </div>
        </div>
    @endif

    @if(isset($localDisponible) && ! $localDisponible)
        <div class="mb-4 p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-900 text-sm flex items-start gap-3">
            <svg class="w-5 h-5 flex-shrink-0 text-amber-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <div class="flex-1">
                <span class="font-semibold">Moteur Local Souverain Ollama hors-ligne :</span>
                Le service Ollama n'est pas joignable sur <code>{{ config('assistant-ia.fournisseurs.local.base_url') }}</code>. Conformément aux principes de souveraineté et de non-fuite des données, <strong>aucun basculement automatique vers le cloud n'est opéré</strong>.
                <div class="mt-2 text-xs text-amber-800">
                    💡 Pour activer Ollama : lancez <code>ollama serve</code> dans votre terminal. Pour tester l'interface sans Ollama, sélectionnez le <strong>Simulateur Déterministe Test</strong> ci-dessous.
                </div>
            </div>
        </div>
    @endif

    <div x-show="erreurMessage" class="mb-4 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-sm flex items-center justify-between gap-3" style="display: none;">
        <div class="flex items-center gap-2">
            <svg class="w-5 h-5 text-rose-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span x-text="erreurMessage"></span>
        </div>
        <button @click="erreurMessage = null" class="text-rose-500 hover:text-rose-700 text-xs font-semibold">Fermer</button>
    </div>

    <!-- Disposition 2 colonnes : Historique (gauche) & Conversation (droite) -->
    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6 min-h-[640px]">
        <!-- Volet latéral : Conversations & Modes -->
        <div class="lg:col-span-1 bg-white border border-gray-200 rounded-2xl p-4 shadow-sm flex flex-col justify-between">
            <div>
                <!-- Bouton Nouvelle discussion -->
                <form action="{{ route('admin.assistant-ia.conversations.creer') }}" method="POST" class="mb-4">
                    @csrf
                    <input type="hidden" name="mode" :value="modeSelectionne">
                    <div class="mb-2">
                        <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wider mb-1">Cible IA</label>
                        @php
                            $aGroq = $peutDistant && (config('assistant-ia.fournisseurs.groq.actif', false) || config('assistant-ia.routage.distant_autorise', false));
                            $choixDefaut = (isset($localDisponible) && $localDisponible) ? 'local' : ($aGroq ? 'groq' : 'faux');
                        @endphp
                        <select name="fournisseur" class="w-full text-xs rounded-lg border-gray-300 py-1.5 focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="local" {{ $choixDefaut === 'local' ? 'selected' : '' }} {{ (isset($localDisponible) && ! $localDisponible) ? 'disabled' : '' }}>
                                🏠 Fournisseur Local Souverain (Ollama){{ (isset($localDisponible) && ! $localDisponible) ? ' — Hors-ligne' : '' }}
                            </option>
                            @if($aGroq)
                                <option value="groq" {{ $choixDefaut === 'groq' ? 'selected' : '' }}>⚡ GroqCloud AI (Ultra-Rapide — Recommandé)</option>
                            @endif
                            @if($peutDistant && config('assistant-ia.fournisseurs.distant.actif', false))
                                <option value="distant">☁️ Fournisseur Distant (Consentement requis)</option>
                            @endif
                            <option value="faux" {{ $choixDefaut === 'faux' ? 'selected' : '' }}>🧪 Simulateur Déterministe Test (Mock)</option>
                        </select>
                    </div>
                    <button type="submit" class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-medium text-sm transition shadow-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        <span>Nouvelle discussion</span>
                    </button>
                </form>

                <!-- Sélecteur de mode -->
                <div class="mb-4">
                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Mode Opérationnel</label>
                    <div class="grid grid-cols-2 gap-1 bg-gray-100 p-1 rounded-xl text-xs font-medium">
                        <button type="button" @click="modeSelectionne = 'metier'"
                            :class="modeSelectionne === 'metier' ? 'bg-white text-indigo-700 shadow-sm font-semibold' : 'text-gray-600 hover:text-gray-900'"
                            class="py-1.5 px-2 rounded-lg text-center transition">
                            Métier
                        </button>
                        @if($peutModeDev)
                        <button type="button" @click="modeSelectionne = 'developpeur'"
                            :class="modeSelectionne === 'developpeur' ? 'bg-white text-indigo-700 shadow-sm font-semibold' : 'text-gray-600 hover:text-gray-900'"
                            class="py-1.5 px-2 rounded-lg text-center transition">
                            Développeur
                        </button>
                        @else
                        <button type="button" disabled title="Permission mode développeur requise"
                            class="py-1.5 px-2 rounded-lg text-center text-gray-400 cursor-not-allowed">
                            Développeur 🔒
                        </button>
                        @endif
                    </div>
                </div>

                <!-- Liste des discussions -->
                <div class="mt-4">
                    <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Historique Récent</div>
                    <div class="space-y-1 max-h-[380px] overflow-y-auto pr-1">
                        @forelse($conversations as $conv)
                            <div class="group flex items-center justify-between rounded-xl px-3 py-2 text-xs transition {{ ($conversationActive && $conversationActive->id === $conv->id) ? 'bg-indigo-50 border border-indigo-200 text-indigo-900 font-semibold' : 'text-gray-700 hover:bg-gray-50' }}">
                                <a href="{{ route('admin.assistant-ia.conversations.show', $conv->uuid) }}" class="flex-1 truncate pr-2 flex items-center gap-1.5">
                                    <span class="w-1.5 h-1.5 rounded-full {{ $conv->mode === 'developpeur' ? 'bg-amber-500' : 'bg-indigo-500' }}"></span>
                                    <span class="truncate">{{ $conv->titre }}</span>
                                </a>
                                <form action="{{ route('admin.assistant-ia.conversations.supprimer', $conv->uuid) }}" method="POST" onsubmit="return confirm('Supprimer cette conversation ?');" class="opacity-0 group-hover:opacity-100 transition">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-gray-400 hover:text-rose-600 p-1" title="Supprimer">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                        </svg>
                                    </button>
                                </form>
                            </div>
                        @empty
                            <p class="text-xs text-gray-400 italic py-2 text-center">Aucune discussion enregistrée.</p>
                        @endforelse
                    </div>
                </div>
            </div>

            <!-- Pied de volet -->
            <div class="pt-4 border-t border-gray-100 text-[11px] text-gray-400 flex items-center justify-between">
                <span>UniRegistre AI Foundation</span>
                <span>v1.0</span>
            </div>
        </div>

        <!-- Volet principal : Zone de discussion -->
        <div class="lg:col-span-3 bg-white border border-gray-200 rounded-2xl shadow-sm flex flex-col h-[640px]">
            <!-- En-tête de la discussion active -->
            <div class="px-6 py-3.5 border-b border-gray-100 flex items-center justify-between bg-gray-50/50 rounded-t-2xl">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full {{ ($conversationActive?->mode ?? 'metier') === 'developpeur' ? 'bg-amber-500' : 'bg-indigo-600' }}"></span>
                    <h2 class="font-semibold text-gray-900 text-sm truncate max-w-md">
                        {{ $conversationActive?->titre ?? 'Nouvelle session assistée' }}
                    </h2>
                    <span class="text-[10px] uppercase font-bold px-2 py-0.5 rounded-full {{ ($conversationActive?->mode ?? 'metier') === 'developpeur' ? 'bg-amber-100 text-amber-800' : 'bg-indigo-100 text-indigo-800' }}">
                        Mode {{ $conversationActive?->mode ?? 'Métier' }}
                    </span>
                    @if($conversationActive)
                        <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full border {{ $conversationActive->fournisseur === 'groq' ? 'bg-amber-50 text-amber-700 border-amber-200' : ($conversationActive->fournisseur === 'local' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-purple-50 text-purple-700 border-purple-200') }}">
                            {{ $conversationActive->fournisseur === 'groq' ? '⚡ GroqCloud AI' : ($conversationActive->fournisseur === 'local' ? '🏠 Local Ollama' : '🧪 Simulateur Mock') }}
                        </span>
                    @endif
                </div>
                <div class="text-xs text-gray-400 flex items-center gap-2">
                    <svg class="w-4 h-4 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                    </svg>
                    <span>Périmètre vérifié</span>
                </div>
            </div>

            @if($conversationActive && $conversationActive->fournisseur === 'faux' && $peutDistant && (config('assistant-ia.fournisseurs.groq.actif', false) || config('assistant-ia.routage.distant_autorise', false)))
                <div class="mx-6 mt-3 p-3 rounded-xl bg-amber-50 border border-amber-200 text-amber-900 text-xs flex items-center justify-between shadow-xs">
                    <div class="flex items-center gap-2">
                        <span class="text-base">🧪</span>
                        <div>
                            <strong class="font-semibold">Discussion sur simulateur de test (Mock déterministe)</strong>
                            <p class="text-[11px] text-amber-700">Cette discussion répète les messages en écho. Basculez sur GroqCloud AI pour obtenir des réponses réelles et intelligentes.</p>
                        </div>
                    </div>
                    <form action="{{ route('admin.assistant-ia.conversations.changer_fournisseur', $conversationActive->uuid) }}" method="POST">
                        @csrf
                        <input type="hidden" name="fournisseur" value="groq">
                        <button type="submit" class="px-3 py-1.5 rounded-lg bg-amber-600 hover:bg-amber-700 text-white font-semibold text-xs transition shadow-sm whitespace-nowrap">
                            ⚡ Activer GroqCloud AI
                        </button>
                    </form>
                </div>
            @endif

            <!-- Fil des messages -->
            <div class="flex-1 p-6 overflow-y-auto space-y-4" id="messagesContainer">
                @if(! $conversationActive || $conversationActive->messages->isEmpty())
                    <!-- Écran d'accueil / Suggestions rapides -->
                    <div class="h-full flex flex-col items-center justify-center text-center max-w-lg mx-auto py-12">
                        <div class="w-14 h-14 rounded-2xl bg-indigo-50 border border-indigo-100 flex items-center justify-center text-indigo-600 mb-4 shadow-sm">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/>
                            </svg>
                        </div>
                        <h3 class="text-base font-bold text-gray-900 mb-1">Comment puis-je vous aider aujourd'hui ?</h3>
                        <p class="text-xs text-gray-500 mb-6">
                            Posez une question sur le cycle d'inscription, vérifiez un dossier, explorez le référentiel LMD ou auditez un service.
                        </p>

                        <!-- Exemples cliquables -->
                        <div class="grid grid-cols-1 gap-2 w-full text-left">
                            <button @click="saisirExemple('Explique-moi les règles de validation pour un passage en Licence 2')" class="p-3 rounded-xl border border-gray-200 hover:border-indigo-300 hover:bg-indigo-50/40 text-xs text-gray-700 transition flex items-center justify-between">
                                <span>📚 Règles de validation pour passage en L2</span>
                                <span class="text-indigo-600 font-semibold">→</span>
                            </button>
                            <button @click="saisirExemple('Comment diagnostiquer un dossier d\'inscription avec pièces manquantes ?')" class="p-3 rounded-xl border border-gray-200 hover:border-indigo-300 hover:bg-indigo-50/40 text-xs text-gray-700 transition flex items-center justify-between">
                                <span>🔍 Diagnostic d'un dossier avec pièces manquantes</span>
                                <span class="text-indigo-600 font-semibold">→</span>
                            </button>
                            @if($peutModeDev)
                            <button @click="saisirExemple('Explique le rôle de InscriptionEligibilityService et ses règles')" class="p-3 rounded-xl border border-gray-200 hover:border-indigo-300 hover:bg-indigo-50/40 text-xs text-gray-700 transition flex items-center justify-between">
                                <span>💻 Audit architectural de InscriptionEligibilityService</span>
                                <span class="text-indigo-600 font-semibold">→</span>
                            </button>
                            @endif
                        </div>
                    </div>
                @else
                    <!-- Messages existants -->
                    @foreach($conversationActive->messages as $msg)
                        <div class="flex {{ $msg->role === 'user' ? 'justify-end' : 'justify-start' }}">
                            <div class="max-w-2xl rounded-2xl p-4 {{ $msg->role === 'user' ? 'bg-indigo-600 text-white rounded-br-none shadow-sm' : 'bg-gray-50 border border-gray-200 text-gray-800 rounded-bl-none shadow-sm' }}">
                                <div class="flex items-center gap-2 mb-1.5 text-[11px] {{ $msg->role === 'user' ? 'text-indigo-200' : 'text-gray-400' }}">
                                    <span class="font-bold">{{ $msg->role === 'user' ? 'Vous' : 'UniRegistre AI' }}</span>
                                    <span>•</span>
                                    <span>{{ $msg->created_at->format('H:i') }}</span>
                                    @if($msg->role === 'assistant' && $msg->latence_ms > 0)
                                        <span>•</span>
                                        <span>{{ round($msg->latence_ms) }}ms</span>
                                    @endif
                                </div>

                                <div class="text-sm whitespace-pre-wrap leading-relaxed">{{ $msg->contenu }}</div>

                                <!-- Citations / Sources RAG -->
                                @if(!empty($msg->sources))
                                    <div class="mt-3 pt-2.5 border-t border-gray-200/60">
                                        <div class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5 flex items-center gap-1">
                                            <svg class="w-3.5 h-3.5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
                                            </svg>
                                            <span>Sources vérifiées :</span>
                                        </div>
                                        <div class="flex flex-wrap gap-1.5">
                                            @foreach($msg->sources as $source)
                                                <span class="inline-flex items-center gap-1 text-[11px] px-2 py-0.5 rounded-md bg-white border border-gray-200 text-gray-700 shadow-2xs" title="{{ $source['extrait'] ?? '' }}">
                                                    <span>📄 {{ $source['titre'] ?? 'Document' }}</span>
                                                </span>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                @endif

                <!-- Indicateur de saisie en cours -->
                <div x-show="chargementEnCours" class="flex justify-start" style="display: none;">
                    <div class="bg-gray-50 border border-gray-200 rounded-2xl rounded-bl-none p-3.5 flex items-center gap-3 text-xs text-gray-500 shadow-sm">
                        <span class="relative flex h-3 w-3">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-indigo-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-3 w-3 bg-indigo-600"></span>
                        </span>
                        <span>UniRegistre AI analyse votre demande et consulte les sources...</span>
                    </div>
                </div>
            </div>

            <!-- Barre de saisie -->
            <div class="p-4 border-t border-gray-100 bg-white rounded-b-2xl">
                <form @submit.prevent="envoyerMessage()" class="relative">
                    <textarea x-model="messageTexte"
                        @keydown.enter.prevent="if(!$event.shiftKey) envoyerMessage()"
                        rows="2"
                        placeholder="Posez votre question en langage naturel (Entrée pour envoyer, Maj+Entrée pour nouvelle ligne)..."
                        class="w-full pl-4 pr-24 py-3 rounded-xl border border-gray-300 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 text-sm text-gray-900 resize-none outline-none transition placeholder-gray-400">
                    </textarea>

                    <button type="submit"
                        :disabled="chargementEnCours || !messageTexte.trim()"
                        class="absolute right-2.5 bottom-3.5 inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 disabled:opacity-40 disabled:cursor-not-allowed text-white text-xs font-semibold transition shadow-sm">
                        <span>Envoyer</span>
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
                        </svg>
                    </button>
                </form>
                <div class="mt-2 flex items-center justify-between text-[11px] text-gray-400">
                    <span>UniRegistre AI n'archive aucune donnée d'identité et ne modifie rien sans approbation humaine explicite.</span>
                    <span>Confidentialité & Sécurité §P1</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Consentement Explicite (Transfert Distant) -->
    <div x-show="modalConsentement" class="fixed inset-0 z-50 overflow-y-auto bg-gray-900/60 backdrop-blur-sm flex items-center justify-center p-4" style="display: none;">
        <div class="bg-white rounded-2xl shadow-xl max-w-lg w-full p-6 border border-gray-100" @click.away="annulerConsentement()">
            <div class="flex items-center gap-3 mb-4">
                <span class="p-2.5 rounded-xl bg-amber-100 text-amber-800">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                </span>
                <div>
                    <h3 class="text-lg font-bold text-gray-900">Consentement Explicite Requis</h3>
                    <p class="text-xs text-gray-500">Demande d'utilisation d'un modèle d'IA externe</p>
                </div>
            </div>

            <div class="space-y-3 mb-5 text-xs text-gray-600 bg-gray-50 p-4 rounded-xl border border-gray-200">
                <div class="flex justify-between pb-2 border-b border-gray-200">
                    <span class="text-gray-500">Fournisseur externe :</span>
                    <span class="font-bold text-gray-800" x-text="(defiActuel?.fournisseur || 'distant') + ' (' + (defiActuel?.modele || '') + ')'"></span>
                </div>
                <div class="flex justify-between pb-2 border-b border-gray-200">
                    <span class="text-gray-500">Motif du recours :</span>
                    <span class="font-semibold text-gray-700" x-text="defiActuel?.motif || 'Assistance approfondie'"></span>
                </div>
                <div class="flex justify-between pb-2 border-b border-gray-200">
                    <span class="text-gray-500">Volume de texte estimé :</span>
                    <span class="font-mono text-gray-800" x-text="(defiActuel?.nb_caracteres || 0) + ' caractères'"></span>
                </div>
                <div>
                    <span class="text-gray-500 block mb-1">Garantie de non-divulgation :</span>
                    <p class="text-[11px] text-emerald-800 font-medium bg-emerald-50 p-2.5 rounded-lg border border-emerald-200">
                        🛡️ Les notes, CIN, paiements, relevés d'examens et secrets d'infrastructure ne quittent JAMAIS les serveurs locaux d'UniRegistre.
                    </p>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3">
                <button type="button" @click="annulerConsentement()" class="px-4 py-2 rounded-xl text-xs font-semibold text-gray-700 hover:bg-gray-100 transition">
                    Annuler (Rester en local)
                </button>
                <button type="button" @click="confirmerConsentement()" class="px-4 py-2 rounded-xl text-xs font-semibold text-white bg-indigo-600 hover:bg-indigo-700 shadow-sm transition">
                    Confirmer le transfert explicite
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('assistantIa', (config) => ({
        modeSelectionne: '{{ $conversationActive?->mode ?? 'metier' }}',
        messageTexte: '',
        chargementEnCours: false,
        erreurMessage: null,
        modalConsentement: false,
        defiActuel: null,
        dernierMessageEnAttente: '',

        saisirExemple(texte) {
            this.messageTexte = texte;
        },

        annulerConsentement() {
            this.modalConsentement = false;
            this.defiActuel = null;
            this.dernierMessageEnAttente = '';
        },

        confirmerConsentement() {
            const jeton = this.defiActuel?.jeton;
            this.modalConsentement = false;
            if (jeton) {
                this.envoyerMessage(jeton);
            }
        },

        async envoyerMessage(jetonConsentement = null) {
            const texte = jetonConsentement ? this.dernierMessageEnAttente : this.messageTexte.trim();
            if (!texte || this.chargementEnCours) return;

            // Si pas de conversation active, créer d'abord une conversation
            if (!config.conversationUuid) {
                this.chargementEnCours = true;
                try {
                    const selectFournisseur = document.querySelector('select[name="fournisseur"]');
                    const fournisseurChoisi = selectFournisseur ? selectFournisseur.value : null;

                    const resCreer = await fetch(config.urls.creer, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': config.csrfToken,
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({ 
                            mode: this.modeSelectionne,
                            fournisseur: fournisseurChoisi
                        })
                    });

                    if (!resCreer.ok) throw new Error('Impossible d\'initialiser la discussion');
                    const conv = await resCreer.json();
                    window.location.href = `${config.urls.base}/conversations/${conv.uuid}?initial_msg=${encodeURIComponent(texte)}`;
                    return;
                } catch (e) {
                    this.chargementEnCours = false;
                    this.erreurMessage = e.message;
                    return;
                }
            }

            this.chargementEnCours = true;
            this.erreurMessage = null;
            const msgContenu = texte;
            if (!jetonConsentement) {
                this.messageTexte = '';
                this.ajouterMessageDOM('user', msgContenu);
                this.defilerBas();
            }

            try {
                const payloadRequete = { contenu: msgContenu };
                if (jetonConsentement) {
                    payloadRequete.jeton_consentement = jetonConsentement;
                }

                const res = await fetch(config.urls.envoyer, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': config.csrfToken,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify(payloadRequete)
                });

                const data = await res.json();

                // Gestion du consentement explicite requis (HTTP 428)
                if (res.status === 428 || data.statut === 'consentement_requis') {
                    this.defiActuel = data.defi_consentement;
                    this.dernierMessageEnAttente = msgContenu;
                    this.modalConsentement = true;
                    this.chargementEnCours = false;
                    return;
                }

                if (!res.ok || !data.succes) {
                    throw new Error(data.erreur || 'Une erreur est survenue lors de l\'échange avec le modèle.');
                }

                this.ajouterMessageDOM('assistant', data.message.contenu, data.message.sources, data.message.latence_ms);
                this.defilerBas();
            } catch (err) {
                this.erreurMessage = err.message;
            } finally {
                this.chargementEnCours = false;
            }
        },

        ajouterMessageDOM(role, contenu, sources = [], latence = 0) {
            const container = document.getElementById('messagesContainer');
            if (!container) return;

            const divMsg = document.createElement('div');
            divMsg.className = `flex ${role === 'user' ? 'justify-end' : 'justify-start'}`;

            const bulle = document.createElement('div');
            bulle.className = `max-w-2xl rounded-2xl p-4 ${role === 'user' ? 'bg-indigo-600 text-white rounded-br-none shadow-sm' : 'bg-gray-50 border border-gray-200 text-gray-800 rounded-bl-none shadow-sm'}`;

            const entete = document.createElement('div');
            entete.className = `flex items-center gap-2 mb-1.5 text-[11px] ${role === 'user' ? 'text-indigo-200' : 'text-gray-400'}`;
            entete.innerHTML = `<span class="font-bold">${role === 'user' ? 'Vous' : 'UniRegistre AI'}</span> • <span>Maintenant</span> ${latence > 0 ? `• <span>${Math.round(latence)}ms</span>` : ''}`;

            const corps = document.createElement('div');
            corps.className = 'text-sm whitespace-pre-wrap leading-relaxed';
            corps.textContent = contenu;

            bulle.appendChild(entete);
            bulle.appendChild(corps);

            if (sources && sources.length > 0) {
                const sourcesDiv = document.createElement('div');
                sourcesDiv.className = 'mt-3 pt-2.5 border-t border-gray-200/60';
                sourcesDiv.innerHTML = `<div class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Sources vérifiées :</div>`;
                const pills = document.createElement('div');
                pills.className = 'flex flex-wrap gap-1.5';
                sources.forEach(s => {
                    const pill = document.createElement('span');
                    pill.className = 'inline-flex items-center gap-1 text-[11px] px-2 py-0.5 rounded-md bg-white border border-gray-200 text-gray-700';
                    pill.textContent = `📄 ${s.titre || 'Source'}`;
                    pills.appendChild(pill);
                });
                sourcesDiv.appendChild(pills);
                bulle.appendChild(sourcesDiv);
            }

            divMsg.appendChild(bulle);
            container.appendChild(divMsg);
        },

        defilerBas() {
            this.$nextTick(() => {
                const c = document.getElementById('messagesContainer');
                if (c) c.scrollTop = c.scrollHeight;
            });
        }
    }));
});
</script>
@endsection
