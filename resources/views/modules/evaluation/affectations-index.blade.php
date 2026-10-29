@extends('layouts.app')

@section('title', 'Affectation des enseignants — Examens & Épreuves')

@section('content')
    @php
        $sessionParDefaut = (string) (request('session_id') ?: ($sessions->firstWhere('epreuves_count', '>', 0)?->id ?? $sessions->first()?->id ?? ''));
    @endphp

    <script>
        function affectationsManager() {
            return {
                sessionSelectionnee: '{{ $sessionParDefaut }}',
                alerteSession: false,
                enCoursAuto: false,
                enCoursOptimiser: false,
                modaleAffecter: false,
                modaleConfirmationAuto: false,
                detailsAffiches: false,
                epreuveSelectionnee: null,

                sessionsData: {
                    @foreach($sessions as $s)
                        '{{ $s->id }}': {
                            id: {{ $s->id }},
                            nom: @json($s->nom),
                            epreuves: {{ $s->epreuves_count ?? 0 }}
                        },
                    @endforeach
                },
                enseignantsDisposCount: {{ $kpis['enseignants_disponibles'] }},

                runActif: @json($activeRun?->progression()),
                pollingTimer: null,
                notificationLancement: false,

                init() {
                    if (this.runActif && !this.runActif.is_terminated) {
                        this.demarrerPolling();
                    }
                },

                validerSession() {
                    if (!this.sessionSelectionnee) {
                        const ids = Object.keys(this.sessionsData);
                        if (ids.length > 0) {
                            const foundWithEpreuves = ids.find(id => this.sessionsData[id].epreuves > 0);
                            this.sessionSelectionnee = foundWithEpreuves || ids[0];
                        }
                    }
                    if (!this.sessionSelectionnee) {
                        this.alerteSession = true;
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                        return false;
                    }
                    const sess = this.sessionsData[this.sessionSelectionnee];
                    if (sess && sess.epreuves === 0) {
                        alert("La session sélectionnée (« " + (sess.nom || '') + " ») ne contient aucune épreuve. Veuillez choisir une session comportant des épreuves.");
                        return false;
                    }
                    this.alerteSession = false;
                    return true;
                },

                changerSession(id) {
                    this.alerteSession = false;
                    if (id && id !== '{{ request('session_id') }}') {
                        window.location.href = '{{ route('admin.evaluations.affectations.index') }}?session_id=' + encodeURIComponent(id);
                    }
                },

                sessionNom() {
                    if (this.sessionSelectionnee && this.sessionsData[this.sessionSelectionnee]) {
                        return this.sessionsData[this.sessionSelectionnee].nom;
                    }
                    return 'Session sélectionnée';
                },

                sessionEpreuvesCount() {
                    if (this.sessionSelectionnee && this.sessionsData[this.sessionSelectionnee]) {
                        return this.sessionsData[this.sessionSelectionnee].epreuves;
                    }
                    return {{ $kpis['total_examens'] }};
                },

                ouvrirConfirmationAuto() {
                    if (!this.validerSession()) return;
                    this.modaleConfirmationAuto = true;
                },

                async lancerAffectationAuto() {
                    this.modaleConfirmationAuto = false;
                    this.enCoursAuto = true;
                    try {
                        const resp = await fetch('{{ route('admin.evaluations.affectations.auto') }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': '{{ csrf_token() }}'
                            },
                            body: JSON.stringify({ session_id: this.sessionSelectionnee })
                        });
                        const data = await resp.json();
                        if ((resp.ok || resp.status === 409) && data.run) {
                            this.runActif = data.run;
                            this.notificationLancement = true;
                            this.demarrerPolling();
                            window.scrollTo({ top: 0, behavior: 'smooth' });
                        } else {
                            alert(data.message || 'Erreur lors du lancement.');
                        }
                    } catch (e) {
                        console.error(e);
                        alert('Impossible de contacter le serveur.');
                    } finally {
                        this.enCoursAuto = false;
                    }
                },

                async lancerOptimisation() {
                    if (!this.validerSession()) return;
                    this.enCoursOptimiser = true;
                    try {
                        const resp = await fetch('{{ route('admin.evaluations.affectations.optimiser') }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': '{{ csrf_token() }}'
                            },
                            body: JSON.stringify({ session_id: this.sessionSelectionnee })
                        });
                        const data = await resp.json();
                        if (data.redirect) {
                            window.location.href = data.redirect;
                            return;
                        }
                        if ((resp.ok || resp.status === 409) && data.run) {
                            this.runActif = data.run;
                            this.notificationLancement = true;
                            this.demarrerPolling();
                            window.scrollTo({ top: 0, behavior: 'smooth' });
                        } else {
                            alert(data.message || 'Erreur lors du lancement de l\'optimisation.');
                        }
                    } catch (e) {
                        console.error(e);
                        alert('Impossible de contacter le serveur.');
                    } finally {
                        this.enCoursOptimiser = false;
                    }
                },

                demarrerPolling() {
                    if (this.pollingTimer) clearInterval(this.pollingTimer);
                    this.pollingTimer = setInterval(() => {
                        this.actualiserStatut();
                    }, 2500);
                },

                async actualiserStatut() {
                    if (!this.runActif || !this.runActif.id) return;
                    try {
                        const resp = await fetch('{{ url('admin/evaluations/affectations/runs') }}/' + this.runActif.id + '/status', {
                            headers: { 'Accept': 'application/json' }
                        });
                        if (resp.ok) {
                            const data = await resp.json();
                            this.runActif = data;
                            if (data.is_terminated) {
                                clearInterval(this.pollingTimer);
                                this.pollingTimer = null;
                            }
                        } else if (resp.status === 423) {
                            window.location.reload();
                        }
                    } catch (e) {
                        console.error('Erreur actualisation statut:', e);
                    }
                },

                formaterTemps(secondes) {
                    if (!secondes || secondes < 0) return '00:00';
                    const mins = Math.floor(secondes / 60);
                    const secs = secondes % 60;
                    return String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
                },

                ouvrirAffectation(epreuve) {
                    this.epreuveSelectionnee = epreuve;
                    this.modaleAffecter = true;
                }
            };
        }

        if (window.Alpine) {
            window.Alpine.data('affectationsManager', affectationsManager);
        } else {
            document.addEventListener('alpine:init', () => {
                if (window.Alpine) {
                    window.Alpine.data('affectationsManager', affectationsManager);
                }
            });
        }
    </script>

    <div class="mx-auto max-w-7xl px-4 sm:px-6 py-8" x-data="affectationsManager()">

        <!-- En-tête de page -->
        <x-page-header
            titre="Affectation des enseignants"
            description="Gestion, équilibrage et contrôle des conflits pour l'affectation des enseignants aux examens et épreuves."
            :fil="['Évaluations' => route('admin.evaluations.index'), 'Affectation enseignants' => '#']">
            <x-slot:actions>
                <div class="flex flex-wrap items-center gap-2">
                    <div class="flex items-center gap-1.5 bg-slate-50 dark:bg-slate-800 px-3 py-1.5 rounded-xl border border-slate-200 dark:border-slate-700">
                        <label for="header_session_id" class="text-xs font-bold text-slate-700 dark:text-slate-300 whitespace-nowrap">Session :</label>
                        <select id="header_session_id"
                                x-model="sessionSelectionnee"
                                @change="changerSession($event.target.value)"
                                class="rounded-lg border-0 bg-transparent text-slate-800 dark:text-slate-200 py-0.5 pl-1 pr-6 text-xs font-semibold focus:ring-1 focus:ring-indigo-500 cursor-pointer">
                            <option value="">-- Choisir une session --</option>
                            @foreach($sessions as $s)
                                <option value="{{ $s->id }}" @selected($sessionParDefaut == $s->id)>{{ $s->nom }} ({{ $s->epreuves_count ?? 0 }} épreuves)</option>
                            @endforeach
                        </select>
                    </div>

                    @can('surveillances.assign')
                        <form method="POST" action="{{ route('admin.evaluations.affectations.auto') }}"
                              class="inline-block"
                              @submit.prevent="ouvrirConfirmationAuto()">
                            @csrf
                            <input type="hidden" name="session_id" :value="sessionSelectionnee" value="{{ $sessionParDefaut }}">
                            <button type="submit"
                                    title="⚡ Affectation automatique"
                                    :disabled="enCoursAuto || enCoursOptimiser || (runActif && !runActif.is_terminated)"
                                    class="inline-flex items-center justify-center gap-1.5 rounded-xl px-4 py-2.5 text-xs font-bold transition focus:outline-none min-h-[44px] bg-indigo-600 hover:bg-indigo-700 text-white shadow-sm disabled:opacity-60 disabled:cursor-not-allowed cursor-pointer">
                                <svg x-show="!enCoursAuto" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m3.75 13.5 10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75Z" /></svg>
                                <span x-text="enCoursAuto ? '⏳ Affectation en cours...' : '⚡ Lancer l\'affectation automatique'">⚡ Lancer l'affectation automatique</span>
                            </button>
                        </form>
                    @endcan

                    @can('surveillances.manage')
                        <form method="POST" action="{{ route('admin.evaluations.affectations.optimiser') }}"
                              class="inline-block"
                              @submit.prevent="lancerOptimisation()">
                            @csrf
                            <input type="hidden" name="session_id" :value="sessionSelectionnee" value="{{ $sessionParDefaut }}">
                            <button type="submit"
                                    title="Optimiser les affectations"
                                    :disabled="enCoursAuto || enCoursOptimiser || (runActif && !runActif.is_terminated)"
                                    class="inline-flex items-center justify-center gap-1.5 rounded-xl px-4 py-2.5 text-xs font-bold transition focus:outline-none min-h-[44px] border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 shadow-xs disabled:opacity-60 disabled:cursor-not-allowed cursor-pointer">
                                <svg x-show="!enCoursOptimiser" class="w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6a7.5 7.5 0 1 0 7.5 7.5h-7.5V6Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5H21A7.5 7.5 0 0 0 13.5 3v7.5Z" /></svg>
                                <span x-text="enCoursOptimiser ? '⏳ Optimisation...' : 'Optimiser les affectations'">Optimiser les affectations</span>
                            </button>
                        </form>
                    @endcan

                    <x-btn variant="secondary" href="{{ route('admin.evaluations.surveillances.cockpit') }}" class="min-h-[44px]">Cockpit charges</x-btn>
                    <x-btn variant="secondary" href="{{ route('admin.evaluations.planning') }}" class="min-h-[44px]">Planning examens</x-btn>
                    <x-btn variant="secondary" href="{{ route('admin.evaluations.affectations.runs', request('session_id') ? ['session_id' => request('session_id')] : []) }}" class="min-h-[44px]">Suivi des traitements</x-btn>
                    @can('surveillances.manage')
                        <x-btn variant="secondary" href="{{ route('admin.evaluations.surveillances.contraintes', request('session_id') ? ['session_id' => request('session_id')] : []) }}" class="min-h-[44px]">
                            ⚙️ Règles d'affectation
                        </x-btn>
                    @endcan
                </div>
            </x-slot:actions>
        </x-page-header>

        <!-- Notification de lancement asynchrone (§6) -->
        <div x-show="notificationLancement" x-cloak class="mt-4">
            <div class="rounded-xl border border-emerald-300 bg-emerald-50 p-4 text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200 flex items-center justify-between gap-3 shadow-xs">
                <div class="flex items-center gap-2">
                    <svg class="w-5 h-5 text-emerald-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                    <div>
                        <p class="text-sm font-bold">✓ Affectation lancée</p>
                        <p class="text-xs text-emerald-700 dark:text-emerald-300">Le traitement est exécuté en arrière-plan. Vous pouvez continuer à utiliser l'application.</p>
                    </div>
                </div>
                <button type="button" @click="notificationLancement = false" class="text-emerald-600 hover:text-emerald-800 text-sm font-bold" aria-label="Fermer">&times;</button>
            </div>
        </div>

        <!-- Panneau de Suivi de Progression Asynchrone (§6, §23) -->
        <div x-show="runActif" x-cloak class="mt-4 ds-card p-5 bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm transition">
            <div class="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-slate-100 dark:border-slate-800">
                <div class="flex items-center gap-2.5">
                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 font-bold text-sm">
                        ⚡
                    </span>
                    <div>
                        <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">
                            Affectation automatique <span class="text-xs font-mono text-slate-500" x-text="'#RUN-' + (runActif?.uuid ? runActif.uuid.substring(0, 8).toUpperCase() : runActif?.id)"></span>
                        </h3>
                        <p class="text-xs text-slate-500">
                            Mode : <span class="font-semibold capitalize" x-text="runActif?.mode === 'optimiser' ? 'Optimisation fine' : 'Affectation globale'"></span>
                        </p>
                    </div>
                </div>

                <!-- Badge Statut -->
                <div class="flex items-center gap-2">
                    <template x-if="runActif?.status === 'PENDING'">
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:border-amber-800">
                            <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span>
                            En attente
                        </span>
                    </template>
                    <template x-if="runActif?.status === 'RUNNING'">
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-indigo-50 text-indigo-700 border border-indigo-200 dark:bg-indigo-950/40 dark:text-indigo-300 dark:border-indigo-800">
                            <span class="w-2 h-2 rounded-full bg-indigo-500 animate-ping"></span>
                            🟡 En cours
                        </span>
                    </template>
                    <template x-if="runActif?.status === 'COMPLETED'">
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-800">
                            ✓ Terminé
                        </span>
                    </template>
                    <template x-if="runActif?.status === 'COMPLETED_WITH_WARNINGS'">
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:border-amber-800">
                            ⚠ Terminé avec avertissements
                        </span>
                    </template>
                    <template x-if="runActif?.status === 'FAILED'">
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-rose-50 text-rose-700 border border-rose-200 dark:bg-rose-950/40 dark:text-rose-300 dark:border-rose-800">
                            ✕ Impossible / Échec
                        </span>
                    </template>
                </div>
            </div>

            <!-- Barre de progression (§6, §23) -->
            <div class="mt-4 space-y-1.5">
                <div class="flex justify-between items-center text-xs">
                    <span class="font-bold text-slate-700 dark:text-slate-300">Progression</span>
                    <span class="font-mono font-bold text-indigo-600 dark:text-indigo-400" x-text="(runActif?.progress || 0) + ' %'">0 %</span>
                </div>
                <div class="w-full bg-slate-100 dark:bg-slate-800 rounded-full h-3 overflow-hidden p-0.5">
                    <div class="bg-indigo-600 h-2 rounded-full transition-all duration-300"
                         :class="{ 'animate-pulse': runActif?.status === 'RUNNING' }"
                         :style="'width: ' + (runActif?.progress || 0) + '%'"></div>
                </div>
            </div>

            <!-- Compteurs en grille (§6, §23) -->
            <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-5 gap-3 text-center">
                <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-100 dark:border-slate-800">
                    <div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Épreuves traitées</div>
                    <div class="text-base font-black text-slate-900 dark:text-slate-100 mt-0.5">
                        <span x-text="runActif?.epreuves_traitees || 0">0</span> / <span x-text="runActif?.total_epreuves || 0">0</span>
                    </div>
                </div>

                <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-100 dark:border-slate-800">
                    <div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Affectations créées</div>
                    <div class="text-base font-black text-indigo-600 dark:text-indigo-400 mt-0.5" x-text="runActif?.affectations_creees || 0">0</div>
                </div>

                <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-100 dark:border-slate-800">
                    <div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Conflits</div>
                    <div class="text-base font-black mt-0.5" :class="(runActif?.conflits || 0) > 0 ? 'text-amber-600' : 'text-slate-700 dark:text-slate-300'" x-text="runActif?.conflits || 0">0</div>
                </div>

                <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-100 dark:border-slate-800">
                    <div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Avertissements</div>
                    <div class="text-base font-black mt-0.5" :class="(runActif?.erreurs || 0) > 0 ? 'text-rose-600' : 'text-slate-700 dark:text-slate-300'" x-text="runActif?.erreurs || 0">0</div>
                </div>

                <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-100 dark:border-slate-800 col-span-2 sm:col-span-4 lg:col-span-1">
                    <div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Temps écoulé</div>
                    <div class="text-base font-mono font-black text-slate-700 dark:text-slate-300 mt-0.5" x-text="formaterTemps(runActif?.elapsed_seconds)">00:00</div>
                </div>
            </div>

            <!-- Résumé / Diagnostic (§19, §20) -->
            <template x-if="runActif?.is_terminated">
                <div class="mt-4 p-4 rounded-xl text-xs space-y-2 border"
                     :class="{
                         'bg-emerald-50 border-emerald-200 text-emerald-900 dark:bg-emerald-950/30 dark:border-emerald-800 dark:text-emerald-200': runActif?.status === 'COMPLETED',
                         'bg-amber-50 border-amber-200 text-amber-900 dark:bg-amber-950/30 dark:border-amber-800 dark:text-amber-200': runActif?.status === 'COMPLETED_WITH_WARNINGS',
                         'bg-rose-50 border-rose-200 text-rose-900 dark:bg-rose-950/30 dark:border-rose-800 dark:text-rose-200': runActif?.status === 'FAILED'
                     }">
                    <div class="flex items-center gap-2 font-bold text-sm">
                        <span x-show="runActif?.status === 'COMPLETED'">✓ Traitement terminé avec succès</span>
                        <span x-show="runActif?.status === 'COMPLETED_WITH_WARNINGS'">⚠ Traitement terminé avec avertissements</span>
                        <span x-show="runActif?.status === 'FAILED'">✕ Affectation impossible ou en échec</span>
                    </div>
                    <template x-if="runActif?.error_message">
                        <p class="font-medium text-rose-700 dark:text-rose-300" x-text="runActif?.error_message"></p>
                    </template>
                    <template x-if="detailsAffiches && runActif?.result_summary">
                        <div class="mt-2 font-mono text-[11px] whitespace-pre-wrap bg-white/70 dark:bg-slate-900/60 p-3 rounded-lg border border-slate-200/50 dark:border-slate-700/50" x-text="runActif?.result_summary?.texte || JSON.stringify(runActif?.result_summary, null, 2)"></div>
                    </template>
                </div>
            </template>

            <!-- Actions du panneau de suivi (§23) -->
            <div class="mt-4 flex flex-wrap items-center justify-between gap-2 pt-3 border-t border-slate-100 dark:border-slate-800">
                <div class="flex items-center gap-2">
                    <template x-if="runActif?.is_terminated">
                        <div class="flex items-center gap-2">
                            <a :href="'{{ route('admin.evaluations.affectations') }}?session_id=' + (sessionSelectionnee || '')"
                               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold bg-indigo-600 hover:bg-indigo-700 text-white shadow-xs">
                                ✓ Voir les affectations
                            </a>
                            <template x-if="(runActif?.conflits || 0) > 0 || (runActif?.erreurs || 0) > 0">
                                <a :href="'{{ route('admin.evaluations.affectations') }}?session_id=' + (sessionSelectionnee || '') + '&statut_affectation=conflit'"
                                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold border border-amber-300 bg-amber-50 text-amber-800 hover:bg-amber-100 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
                                    ⚠ Voir les problèmes
                                </a>
                            </template>
                        </div>
                    </template>
                </div>

                <div class="flex items-center gap-2">
                    <template x-if="runActif?.result_summary">
                        <button type="button" @click="detailsAffiches = !detailsAffiches"
                                class="inline-flex items-center gap-1 text-xs font-semibold text-indigo-600 dark:text-indigo-400 hover:underline">
                            <span x-text="detailsAffiches ? 'Masquer le rapport' : 'Voir les détails'">Voir les détails</span>
                        </button>
                    </template>

                    <button type="button" @click="actualiserStatut()"
                            class="inline-flex items-center gap-1 text-xs font-semibold text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                        Actualiser
                    </button>
                    <template x-if="runActif?.is_terminated">
                        <button type="button" @click="runActif = null"
                                class="text-xs text-slate-400 hover:text-slate-600 px-2 py-1">
                            Masquer
                        </button>
                    </template>
                </div>
            </div>
        </div>

        <!-- Messages Flash & Alertes -->
        <div x-show="alerteSession" x-cloak class="mt-4">
            <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200 flex items-center justify-between gap-3 shadow-xs">
                <div class="flex items-center gap-2">
                    <svg class="w-5 h-5 text-amber-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                    <span class="text-sm font-semibold">⚠ Veuillez sélectionner une session d'examen.</span>
                </div>
                <button type="button" @click="alerteSession = false" class="text-amber-600 hover:text-amber-800 text-sm font-bold" aria-label="Fermer l'alerte">&times;</button>
            </div>
        </div>

        @if(session('succes'))
            <div class="mt-4">
                <x-alert type="success" :message="session('succes')" />
            </div>
        @endif

        @if(session('avertissement'))
            <div class="mt-4">
                <x-alert type="warning" :message="session('avertissement')" />
            </div>
        @endif

        @if($errors->has('affectation'))
            <div class="mt-4">
                <x-alert type="error" :message="$errors->first('affectation')" />
            </div>
        @endif

        @if($errors->has('session_id'))
            <div class="mt-4">
                <x-alert type="warning" :message="'⚠ ' . $errors->first('session_id')" />
            </div>
        @endif

        @if(isset($activeRun) && $activeRun)
            <div class="mt-4">
                <x-alert type="info" :message="'⏳ Un traitement d’affectation est en cours pour cette session ('.$activeRun->epreuves_traitees.'/'.$activeRun->total_epreuves.' épreuves).'" />
                <a href="{{ route('admin.evaluations.affectations.runs', ['run' => $activeRun->uuid]) }}" class="text-xs font-bold text-indigo-700 dark:text-indigo-300 underline underline-offset-2">Suivre le traitement en temps réel →</a>
            </div>
        @endif

        <!-- KPIs Synthétiques -->
        <div class="mt-6 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            <x-stat-card
                libelle="Examens"
                :valeur="$kpis['total_examens']"
                detail="Épreuves au planning"
                couleur="primary" />

            <x-stat-card
                libelle="Sans surveillant"
                :valeur="$kpis['examens_sans_enseignant']"
                detail="Sans enseignant affecté"
                couleur="{{ $kpis['examens_sans_enseignant'] > 0 ? 'rose' : 'emerald' }}" />

            <x-stat-card
                libelle="Affectés"
                :valeur="$kpis['examens_affectes']"
                detail="Couverture complète"
                couleur="emerald" />

            <x-stat-card
                libelle="Enseignants disponibles"
                :valeur="$kpis['enseignants_disponibles']"
                detail="Enseignants dispos mobilisables"
                couleur="slate" />

            <x-stat-card
                libelle="Affectations"
                :valeur="$kpis['total_affectations']"
                detail="Postes attribués"
                couleur="primary" />

            <x-stat-card
                libelle="Conflits détectés"
                :valeur="$kpis['conflits_detectes']"
                detail="Horaires ou capacités"
                couleur="{{ $kpis['conflits_detectes'] > 0 ? 'amber' : 'emerald' }}" />
        </div>

        <!-- Filtres et Recherche Multicritères -->
        <div class="mt-6 ds-card p-5 bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm">
            <form method="get" action="{{ route('admin.evaluations.affectations') }}" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-sm">
                    <!-- Recherche textuelle -->
                    <div class="lg:col-span-2">
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-400 mb-1">Recherche globale</label>
                        <div class="relative">
                            <input type="text" name="q" value="{{ request('q') }}"
                                   placeholder="Rechercher par épreuve, matière, examen ou groupe..."
                                   class="w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-200 px-3.5 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 pl-9">
                            <svg class="w-4 h-4 text-slate-400 absolute left-3 top-2.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                        </div>
                    </div>

                    <!-- Session -->
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-400 mb-1">Session</label>
                        <select name="session_id"
                                x-model="sessionSelectionnee"
                                @change="alerteSession = false"
                                class="w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-200 px-3 py-2 text-sm focus:border-indigo-500">
                            <option value="">Toutes les sessions</option>
                            @foreach($sessions as $s)
                                <option value="{{ $s->id }}" @selected($sessionParDefaut == $s->id)>{{ $s->nom }} ({{ $s->epreuves_count ?? 0 }} épreuves)</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Statut d'affectation -->
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-400 mb-1">Statut affectation</label>
                        <select name="statut_affectation" class="w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-200 px-3 py-2 text-sm focus:border-indigo-500">
                            <option value="">Tous les statuts</option>
                            <option value="affecte" @selected(request('statut_affectation') === 'affecte')>✓ Affecté</option>
                            <option value="partiellement" @selected(request('statut_affectation') === 'partiellement')>⚠ Partiellement affecté</option>
                            <option value="non_affecte" @selected(request('statut_affectation') === 'non_affecte')>✕ Non affecté</option>
                            <option value="conflit" @selected(request('statut_affectation') === 'conflit')>⛔ En conflit</option>
                        </select>
                    </div>
                </div>

                <!-- Ligne de filtres secondaires -->
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 text-xs pt-1 border-t border-slate-100 dark:border-slate-800">
                    <!-- Année -->
                    <div>
                        <label class="block font-bold text-slate-500 dark:text-slate-400 mb-1">Année universitaire</label>
                        <select name="annee_universitaire_id" class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-200 px-2.5 py-1.5 focus:border-indigo-500">
                            <option value="">Toutes</option>
                            @foreach($annees as $a)
                                <option value="{{ $a->id }}" @selected(request('annee_universitaire_id') == $a->id)>{{ $a->libelle }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Semestre -->
                    <div>
                        <label class="block font-bold text-slate-500 dark:text-slate-400 mb-1">Semestre</label>
                        <select name="semestre" class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-200 px-2.5 py-1.5 focus:border-indigo-500">
                            <option value="">Tous</option>
                            <option value="1" @selected(request('semestre') == '1')>Semestre 1</option>
                            <option value="2" @selected(request('semestre') == '2')>Semestre 2</option>
                        </select>
                    </div>

                    <!-- Niveau -->
                    <div>
                        <label class="block font-bold text-slate-500 dark:text-slate-400 mb-1">Niveau</label>
                        <select name="niveau" class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-200 px-2.5 py-1.5 focus:border-indigo-500">
                            <option value="">Tous</option>
                            @foreach(['L1', 'L2', 'L3', 'M1', 'M2'] as $niv)
                                <option value="{{ $niv }}" @selected(request('niveau') === $niv)>{{ $niv }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Groupe -->
                    <div>
                        <label class="block font-bold text-slate-500 dark:text-slate-400 mb-1">Groupe</label>
                        <select name="groupe_id" class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-200 px-2.5 py-1.5 focus:border-indigo-500">
                            <option value="">Tous</option>
                            @foreach($groupes as $g)
                                <option value="{{ $g->id }}" @selected(request('groupe_id') == $g->id)>{{ $g->code }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Matière / ECUE -->
                    <div>
                        <label class="block font-bold text-slate-500 dark:text-slate-400 mb-1">Matière / ECUE</label>
                        <select name="matiere_id" class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-200 px-2.5 py-1.5 focus:border-indigo-500">
                            <option value="">Toutes</option>
                            @foreach($matieres as $m)
                                <option value="{{ $m->id }}" @selected(request('matiere_id') == $m->id)>{{ $m->code }} - {{ \Illuminate\Support\Str::limit($m->nom, 15) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Enseignant -->
                    <div>
                        <label class="block font-bold text-slate-500 dark:text-slate-400 mb-1">Enseignant</label>
                        <select name="teacher_id" class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-200 px-2.5 py-1.5 focus:border-indigo-500">
                            <option value="">Tous</option>
                            @foreach($enseignants as $ens)
                                <option value="{{ $ens->id }}" @selected(request('teacher_id') == $ens->id)>{{ $ens->nom }} {{ $ens->prenom }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <!-- Boutons d'action du formulaire -->
                <div class="flex items-center justify-between pt-2">
                    <a href="{{ route('admin.evaluations.affectations') }}" class="text-xs font-semibold text-slate-500 hover:text-slate-700 dark:hover:text-slate-300 underline">
                        Réinitialiser tous les filtres
                    </a>
                    <x-btn type="submit" variant="primary" class="text-xs py-1.5 px-4">
                        Appliquer les filtres
                    </x-btn>
                </div>
            </form>
        </div>

        <!-- Tableau des Examens et Affectations -->
        <div class="mt-6 ds-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
            @if($epreuves->isEmpty())
                <div class="py-12">
                    <x-empty-state message="Aucune épreuve trouvée correspondant aux critères de recherche." />
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm border-collapse">
                        <thead class="bg-slate-50 dark:bg-slate-800/60 text-[11px] font-black uppercase tracking-wider text-slate-500 dark:text-slate-400 border-b border-slate-200 dark:border-slate-800">
                            <tr>
                                <th class="px-4 py-3">Examen & Session</th>
                                <th class="px-4 py-3">Matière / ECUE</th>
                                <th class="px-4 py-3">Niveau & Groupe</th>
                                <th class="px-4 py-3">Date & Horaire</th>
                                <th class="px-4 py-3">Salle & Places</th>
                                <th class="px-4 py-3 text-center">Étudiants</th>
                                <th class="px-4 py-3">Enseignant(s) affecté(s)</th>
                                <th class="px-4 py-3 text-center">Statut</th>
                                <th class="px-4 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            @foreach($epreuves as $epreuve)
                                @php
                                    $nbEtudiants = max($epreuve->candidats_count ?? 0, (int) ($epreuve->capacite_necessaire ?? 0), $epreuve->groupe?->etudiants?->count() ?? 0);
                                    $salleCapacite = $epreuve->salle?->capacite ?? 0;
                                    $conflits = $epreuve->conflits_calcules ?? [];
                                    $hasConflit = !empty($conflits);
                                @endphp
                                <tr class="hover:bg-slate-50/70 dark:hover:bg-slate-800/40 transition-colors {{ $hasConflit ? 'bg-rose-50/30 dark:bg-rose-950/10' : '' }}">
                                    <!-- Examen & Session -->
                                    <td class="px-4 py-3.5">
                                        <div class="font-bold text-slate-900 dark:text-slate-100">{{ $epreuve->code }}</div>
                                        <div class="text-xs text-slate-500 dark:text-slate-400">
                                            {{ $epreuve->examen?->libelle ?? 'Examen' }}
                                            @if($epreuve->session)
                                                · <span class="font-medium text-slate-600 dark:text-slate-300">{{ $epreuve->session->nom }}</span>
                                            @endif
                                        </div>
                                    </td>

                                    <!-- Matière / ECUE -->
                                    <td class="px-4 py-3.5">
                                        <div class="font-bold text-slate-800 dark:text-slate-200">{{ $epreuve->matiere?->nom ?? '—' }}</div>
                                        <div class="text-xs font-mono text-slate-500">{{ $epreuve->matiere?->code }} (Coef {{ $epreuve->coefficient ?: ($epreuve->matiere?->coefficient ?: 1) }})</div>
                                    </td>

                                    <!-- Niveau & Groupe -->
                                    <td class="px-4 py-3.5 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
                                            {{ $epreuve->groupe?->niveau ?? ($epreuve->matiere?->niveau ?? 'LMD') }}
                                        </span>
                                        <span class="ml-1 text-xs text-slate-600 dark:text-slate-400 font-medium">
                                            {{ $epreuve->groupe?->code ?? 'Tous' }}
                                        </span>
                                    </td>

                                    <!-- Date & Horaire -->
                                    <td class="px-4 py-3.5 whitespace-nowrap">
                                        <div class="font-medium text-slate-800 dark:text-slate-200 tabular-nums">
                                            {{ $epreuve->date ? $epreuve->date->format('d/m/Y') : '—' }}
                                        </div>
                                        <div class="text-xs text-slate-500 tabular-nums">
                                            {{ substr((string) $epreuve->heure_debut, 0, 5) }} → {{ substr((string) $epreuve->heure_fin, 0, 5) }}
                                            ({{ $epreuve->duree_minutes ?: 120 }} min)
                                        </div>
                                    </td>

                                    <!-- Salle & Places -->
                                    <td class="px-4 py-3.5 whitespace-nowrap">
                                        @if($epreuve->salle)
                                            <div class="font-semibold text-slate-800 dark:text-slate-200">
                                                {{ $epreuve->salle->code }}
                                            </div>
                                            <div class="text-xs {{ $salleCapacite < $nbEtudiants ? 'text-rose-600 font-bold' : 'text-slate-500' }}">
                                                {{ $salleCapacite }} places
                                            </div>
                                        @else
                                            <span class="text-xs text-slate-400 italic">Non attribuée</span>
                                        @endif
                                    </td>

                                    <!-- Nombre d'étudiants -->
                                    <td class="px-4 py-3.5 text-center whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold {{ $salleCapacite > 0 && $salleCapacite < $nbEtudiants ? 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300' : 'bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-200' }}">
                                            {{ $nbEtudiants }}
                                        </span>
                                    </td>

                                    <!-- Enseignant(s) affecté(s) -->
                                    <td class="px-4 py-3.5 min-w-[220px]">
                                        <div class="space-y-1.5">
                                            @php
                                                $surveillantsActifs = $epreuve->surveillants->whereIn('statut', ['assigne', 'confirme']);
                                            @endphp

                                            @forelse($surveillantsActifs as $surv)
                                                <div class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-xs font-medium mr-1.5 mb-1
                                                    @if($surv->role === 'principal') bg-indigo-50 text-indigo-700 border border-indigo-200 dark:bg-indigo-950/40 dark:text-indigo-300 dark:border-indigo-800
                                                    @elseif($surv->role === 'adjoint') bg-sky-50 text-sky-700 border border-sky-200 dark:bg-sky-950/40 dark:text-sky-300 dark:border-sky-800
                                                    @elseif($surv->role === 'reserve') bg-amber-50 text-amber-700 border border-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:border-amber-800
                                                    @else bg-slate-100 text-slate-700 border border-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:border-slate-700
                                                    @endif">
                                                    <span>{{ $surv->enseignant?->nom_complet ?? $surv->utilisateur?->name ?? 'Enseignant' }}</span>
                                                    <span class="text-[10px] font-black uppercase tracking-wider opacity-75">
                                                        @if($surv->role === 'principal') (Princ.)
                                                        @elseif($surv->role === 'adjoint') (Adj.)
                                                        @elseif($surv->role === 'reserve') (Garde)
                                                        @else (Surv.)
                                                        @endif
                                                    </span>

                                                    @can('surveillances.manage')
                                                        <form method="post" action="{{ route('admin.evaluations.affectations.retirer', $surv) }}" class="inline ml-1" onsubmit="return confirm('Retirer cet enseignant de l’épreuve ?');">
                                                            @csrf
                                                            <button type="submit" class="hover:text-rose-600 transition-colors" title="Retirer l'affectation">&times;</button>
                                                        </form>
                                                    @endcan
                                                </div>
                                            @empty
                                                <span class="text-xs text-slate-400 italic">Aucun enseignant affecté</span>
                                            @endforelse

                                            <!-- Avertissements de Conflits Détectés sur l'épreuve -->
                                            @if($hasConflit)
                                                <div class="mt-1 space-y-1">
                                                    @foreach($conflits as $c)
                                                        <div class="flex items-start gap-1 text-[11px] text-rose-600 dark:text-rose-400 font-medium">
                                                            <svg class="w-3.5 h-3.5 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                                                            <span>{{ $c['message'] }}</span>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    </td>

                                    <!-- Statut visuel -->
                                    <td class="px-4 py-3.5 text-center whitespace-nowrap">
                                        @if($epreuve->statut_visuel === 'conflit')
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-rose-100 text-rose-800 dark:bg-rose-900/50 dark:text-rose-300 animate-pulse">
                                                <span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span>
                                                Conflit
                                            </span>
                                        @elseif($epreuve->statut_visuel === 'affecte')
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300">
                                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                                Affecté
                                            </span>
                                        @elseif($epreuve->statut_visuel === 'partiellement')
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-300">
                                                <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                                                Partiel
                                            </span>
                                        @elseif($epreuve->statut_visuel === 'a_verifier')
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-indigo-100 text-indigo-800 dark:bg-indigo-900/50 dark:text-indigo-300">
                                                <span class="h-1.5 w-1.5 rounded-full bg-indigo-500"></span>
                                                À vérifier
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400">
                                                <span class="h-1.5 w-1.5 rounded-full bg-slate-400"></span>
                                                Non affecté
                                            </span>
                                        @endif
                                    </td>

                                    <!-- Actions -->
                                    <td class="px-4 py-3.5 text-right whitespace-nowrap space-x-2">
                                        @can('surveillances.assign')
                                            <button type="button"
                                                    @click="ouvrirAffectation({
                                                        id: {{ $epreuve->id }},
                                                        code: '{{ $epreuve->code }}',
                                                        matiere: '{{ addslashes($epreuve->matiere?->nom ?? '') }}',
                                                        date: '{{ $epreuve->date ? $epreuve->date->format('d/m/Y') : '' }}',
                                                        horaire: '{{ substr((string) $epreuve->heure_debut, 0, 5) }} → {{ substr((string) $epreuve->heure_fin, 0, 5) }}',
                                                        salle_id: '{{ $epreuve->salle_id }}'
                                                    })"
                                                    class="inline-flex items-center gap-1 rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1.5 text-xs font-bold text-indigo-700 hover:bg-indigo-100 transition-colors dark:bg-indigo-950/40 dark:border-indigo-800 dark:text-indigo-300">Affecter</button>
                                        @endcan
                                        <a href="{{ route('admin.evaluations.epreuves.show', $epreuve) }}"
                                           class="text-xs font-bold text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200 underline underline-offset-2">
                                            Détails
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="px-4 py-3 border-t border-slate-100 dark:border-slate-800">
                    {{ $epreuves->links() }}
                </div>
            @endif
        </div>

        <!-- Modale : Affectation Manuelle -->
        <div x-show="modaleAffecter" x-cloak class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <div x-show="modaleAffecter" @click="modaleAffecter = false" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs transition-opacity"></div>
                <span class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>

                <div x-show="modaleAffecter" class="inline-block align-bottom bg-white dark:bg-slate-900 rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full border border-slate-200 dark:border-slate-800 p-6">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                        <h3 class="text-base font-bold text-slate-900 dark:text-slate-100" id="modal-title">
                            Affecter un enseignant
                        </h3>
                        <button type="button" @click="modaleAffecter = false" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">&times;</button>
                    </div>

                    <template x-if="epreuveSelectionnee">
                        <form method="post" :action="'{{ url('admin/evaluations/affectations') }}/' + epreuveSelectionnee.id + '/assigner'" class="mt-4 space-y-4">
                            @csrf
                            <div class="p-3 bg-slate-50 dark:bg-slate-800/60 rounded-xl text-xs space-y-1 text-slate-600 dark:text-slate-400">
                                <div><span class="font-bold">Épreuve :</span> <span x-text="epreuveSelectionnee.code"></span> — <span x-text="epreuveSelectionnee.matiere"></span></div>
                                <div><span class="font-bold">Créneau :</span> Le <span x-text="epreuveSelectionnee.date"></span> (<span x-text="epreuveSelectionnee.horaire"></span>)</div>
                            </div>

                            <!-- Sélection de l'enseignant -->
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 dark:text-slate-300 mb-1">Enseignant *</label>
                                <select name="teacher_id" required class="w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-200 px-3 py-2 text-sm focus:border-indigo-500">
                                    <option value="">Sélectionner un enseignant actif...</option>
                                    @foreach($enseignants as $ens)
                                        <option value="{{ $ens->id }}">
                                            {{ $ens->nom }} {{ $ens->prenom }} ({{ $ens->statut ?? 'Enseignant' }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <!-- Rôle -->
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 dark:text-slate-300 mb-1">Rôle d'affectation *</label>
                                <select name="role" required class="w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-200 px-3 py-2 text-sm focus:border-indigo-500">
                                    <option value="principal">Surveillant Principal (Responsable d'épreuve)</option>
                                    <option value="adjoint" selected>Surveillant Adjoint</option>
                                    <option value="surveillant">Surveillant</option>
                                    <option value="reserve">Garde de réserve</option>
                                </select>
                            </div>

                            <!-- Salle -->
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 dark:text-slate-300 mb-1">Salle (Optionnel)</label>
                                <select name="salle_id" class="w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-200 px-3 py-2 text-sm focus:border-indigo-500">
                                    <option value="">Salle principale de l'épreuve</option>
                                    @foreach($salles as $salle)
                                        <option value="{{ $salle->id }}">{{ $salle->code }} ({{ $salle->capacite }} places)</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="pt-3 border-t border-slate-100 dark:border-slate-800 flex justify-end gap-2">
                                <x-btn type="button" variant="secondary" @click="modaleAffecter = false">Annuler</x-btn>
                                <x-btn type="submit" variant="primary">Confirmer l'affectation</x-btn>
                            </div>
                        </form>
                    </template>
                </div>
            </div>
        </div>

        <!-- Modale Confirmation Affectation Automatique Asynchrone (§22) -->
        <div x-show="modaleConfirmationAuto" x-cloak class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-confirm-title" role="dialog" aria-modal="true">
            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <div x-show="modaleConfirmationAuto" @click="modaleConfirmationAuto = false" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs transition-opacity"></div>
                <span class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>

                <div x-show="modaleConfirmationAuto" class="inline-block align-bottom bg-white dark:bg-slate-900 rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-md sm:w-full border border-slate-200 dark:border-slate-800 p-6">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                        <h3 class="text-base font-black text-slate-900 dark:text-slate-100 flex items-center gap-2" id="modal-confirm-title">
                            <span>⚡</span> Confirmation
                        </h3>
                        <button type="button" @click="modaleConfirmationAuto = false" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">&times;</button>
                    </div>

                    <div class="mt-4 space-y-3 text-sm">
                        <div class="p-3.5 bg-slate-50 dark:bg-slate-800/60 rounded-xl space-y-2 border border-slate-100 dark:border-slate-800">
                            <div class="flex justify-between items-center text-xs">
                                <span class="text-slate-500 font-semibold">Session :</span>
                                <span class="font-bold text-slate-900 dark:text-slate-100" x-text="sessionNom()"></span>
                            </div>
                            <div class="flex justify-between items-center text-xs">
                                <span class="text-slate-500 font-semibold">Épreuves :</span>
                                <span class="font-bold text-slate-900 dark:text-slate-100" x-text="sessionEpreuvesCount()"></span>
                            </div>
                            <div class="flex justify-between items-center text-xs">
                                <span class="text-slate-500 font-semibold">Enseignants disponibles :</span>
                                <span class="font-bold text-indigo-600 dark:text-indigo-400" x-text="enseignantsDisposCount"></span>
                            </div>
                        </div>

                        <p class="text-xs text-slate-600 dark:text-slate-400 leading-relaxed">
                            Le traitement sera exécuté en arrière-plan.
                        </p>
                    </div>

                    <div class="mt-5 pt-3 border-t border-slate-100 dark:border-slate-800 flex justify-end gap-2">
                        <x-btn type="button" variant="secondary" @click="modaleConfirmationAuto = false">
                            Annuler
                        </x-btn>
                        <button type="button"
                                @click="lancerAffectationAuto()"
                                :disabled="enCoursAuto"
                                class="inline-flex items-center justify-center gap-1.5 rounded-xl px-4 py-2.5 text-xs font-bold transition focus:outline-none min-h-[44px] bg-indigo-600 hover:bg-indigo-700 text-white shadow-sm disabled:opacity-60 cursor-pointer">
                            <span x-text="enCoursAuto ? '⏳ Affectation en cours...' : 'Lancer'">Lancer</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

