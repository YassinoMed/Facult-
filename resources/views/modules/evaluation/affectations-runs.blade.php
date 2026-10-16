@extends('layouts.app')

@section('title', 'Traitements d’affectation')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 py-8">
        <x-page-header
            titre="Traitements d’affectation"
            description="Suivi en temps réel des affectations automatiques exécutées en arrière-plan."
            :fil="['Évaluations' => route('admin.evaluations.index'), 'Affectation enseignants' => route('admin.evaluations.affectations'), 'Traitements' => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('admin.evaluations.affectations') }}">Retour affectations</x-btn>
            </x-slot:actions>
        </x-page-header>

        @if(session('succes'))
            <div class="mt-4"><x-alert type="success" :message="session('succes')" /></div>
        @endif
        @if(session('warning'))
            <div class="mt-4"><x-alert type="warning" :message="session('warning')" /></div>
        @endif
        @if(session('error'))
            <div class="mt-4"><x-alert type="error" :message="session('error')" /></div>
        @endif
        @if($errors->has('run'))
            <div class="mt-4"><x-alert type="error" :message="$errors->first('run')" /></div>
        @endif

        @php
            $libelles = [
                'PENDING' => 'En attente', 'RUNNING' => 'En cours', 'COMPLETED' => 'Terminé',
                'COMPLETED_WITH_WARNINGS' => 'Terminé avec avertissements', 'FAILED' => 'Échec', 'CANCELLED' => 'Annulé',
            ];
            $couleurs = [
                'PENDING' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-300',
                'RUNNING' => 'bg-sky-100 text-sky-800 dark:bg-sky-900/50 dark:text-sky-300',
                'COMPLETED' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300',
                'COMPLETED_WITH_WARNINGS' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-300',
                'FAILED' => 'bg-rose-100 text-rose-800 dark:bg-rose-900/50 dark:text-rose-300',
                'CANCELLED' => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400',
            ];
        @endphp

        @if($misEnAvant)
            <div class="mt-6 ds-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm p-5"
                 x-data="suiviRun(@js(route('admin.evaluations.affectations.runs.status', $misEnAvant)), @js($misEnAvant->progression()))">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-[11px] font-black uppercase tracking-wider text-slate-400">Traitement suivi</p>
                        <h3 class="text-base font-black text-slate-900 dark:text-slate-100">
                            {{ $misEnAvant->session?->nom ?? 'Session' }}
                            <span class="text-xs font-mono text-slate-400">#{{ $misEnAvant->uuid }}</span>
                        </h3>
                    </div>
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold {{ $couleurs[$misEnAvant->status] ?? '' }}"
                          :class="classeStatut">
                        <span x-show="!termine" class="h-1.5 w-1.5 rounded-full bg-current animate-pulse" aria-hidden="true"></span>
                        <span x-text="libelle">…</span>
                    </span>
                </div>

                <div class="mt-3 h-2.5 rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden" role="progressbar"
                     aria-label="Progression du traitement" :aria-valuenow="etat.progress" aria-valuemin="0" aria-valuemax="100">
                    <div class="h-full bg-indigo-500 transition-all" :style="'width: ' + etat.progress + '%'"></div>
                </div>
                <p class="mt-1 text-xs font-bold text-slate-600 dark:text-slate-400 tabular-nums">
                    <span x-text="etat.progress">0</span> % ·
                    <span x-text="etat.epreuves_traitees">0</span> / <span x-text="etat.total_epreuves">0</span> épreuves ·
                    <span x-text="etat.affectations_creees">0</span> affectations ·
                    <span x-text="etat.conflits">0</span> conflits ·
                    <span x-text="tempsEcoule()">—</span>
                </p>

                <div x-show="etat.error_message" x-cloak class="mt-3 rounded-xl border border-rose-300 bg-rose-50 p-3 dark:border-rose-800 dark:bg-rose-950/40">
                    <p class="text-xs text-rose-700 dark:text-rose-300 font-semibold" x-text="etat.error_message"></p>
                </div>

                <div x-show="termine && etat.result_summary" x-cloak class="mt-3 rounded-xl border border-slate-200 dark:border-slate-700 p-4 text-xs">
                    <h4 class="font-black uppercase tracking-wider text-slate-500 mb-2">Rapport final</h4>
                    <dl class="grid grid-cols-2 sm:grid-cols-4 gap-x-4 gap-y-1 text-slate-600 dark:text-slate-400">
                        <template x-for="ligne in lignesResume()" :key="ligne[0]">
                            <div class="contents">
                                <dt x-text="ligne[0]"></dt>
                                <dd class="text-right font-bold text-slate-900 dark:text-slate-100" x-text="ligne[1]"></dd>
                            </div>
                        </template>
                    </dl>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    @can('surveillances.manage')
                        <form x-show="!termine" method="post" action="{{ route('admin.evaluations.affectations.runs.annuler', $misEnAvant) }}"
                              onsubmit="return confirm('Annuler ce traitement ? L’arrêt sera pris en compte à la prochaine épreuve.');">
                            @csrf
                            <x-btn type="submit" variant="danger" class="text-xs py-1.5 px-4">Annuler le traitement</x-btn>
                        </form>
                    @endcan
                    <x-btn variant="secondary" href="{{ route('admin.evaluations.affectations', $misEnAvant->session_examen_id ? ['session_id' => $misEnAvant->session_examen_id] : []) }}" class="text-xs py-1.5 px-4">Voir les affectations</x-btn>
                    <x-btn type="button" variant="secondary" @click="actualiser()" class="text-xs py-1.5 px-4">Actualiser</x-btn>
                </div>
            </div>

            <script>
                function suiviRun(url, initial) {
                    const libelles = @js($libelles);
                    const couleurs = {
                        PENDING: 'bg-amber-100 text-amber-800',
                        RUNNING: 'bg-sky-100 text-sky-800',
                        COMPLETED: 'bg-emerald-100 text-emerald-800',
                        COMPLETED_WITH_WARNINGS: 'bg-amber-100 text-amber-800',
                        FAILED: 'bg-rose-100 text-rose-800',
                        CANCELLED: 'bg-slate-100 text-slate-600',
                    };
                    return {
                        etat: initial,
                        minuteur: null,
                        get termine() { return !!this.etat.is_terminated; },
                        get libelle() { return libelles[this.etat.status] ?? this.etat.status; },
                        get classeStatut() { return couleurs[this.etat.status] ?? ''; },
                        demarrer() {
                            this.actualiser();
                            this.minuteur = setInterval(() => {
                                if (this.termine) { clearInterval(this.minuteur); return; }
                                this.actualiser();
                            }, 2500);
                        },
                        actualiser() {
                            fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' })
                                .then((r) => {
                                    // Session verrouillée : recharger vers l'écran serveur.
                                    if (r.status === 423) { window.location.reload(); return Promise.reject(); }
                                    return r.ok ? r.json() : Promise.reject();
                                })
                                .then((d) => {
                                    this.etat = d;
                                    if (this.termine && this.minuteur) { clearInterval(this.minuteur); }
                                })
                                .catch(() => {});
                        },
                        tempsEcoule() {
                            if (!this.etat.started_at) { return '—'; }
                            const fin = this.etat.finished_at ? new Date(this.etat.finished_at) : new Date();
                            const s = Math.max(0, Math.round((fin - new Date(this.etat.started_at)) / 1000));
                            const mm = String(Math.floor(s / 60)).padStart(2, '0');
                            const ss = String(s % 60).padStart(2, '0');

                            return mm + ':' + ss;
                        },
                        lignesResume() {
                            const r = this.etat.result_summary || {};

                            return [
                                ['Épreuves analysées', r.epreuves_analysees ?? '—'],
                                ['Épreuves affectées', r.epreuves_affectees ?? r.epreuves_analysees ?? '—'],
                                ['Épreuves ignorées', r.epreuves_ignorees ?? 0],
                                ['Enseignants mobilisés', r.enseignants_mobilises ?? '—'],
                                ['Surveillances', r.surveillances ?? this.etat.affectations_creees],
                                ['Gardes', r.gardes ?? '—'],
                                ['Charge min / max', (r.charge_min ?? '—') + ' / ' + (r.charge_max ?? '—')],
                                ['Écart', r.ecart ?? '—'],
                                ['Jours moyens', r.jours_moyens ?? '—'],
                                ['> 3 jours', r.enseignants_plus_3_jours ?? '—'],
                                ['Conflits', r.conflits ?? this.etat.conflits],
                            ];
                        },
                        init() { this.demarrer(); },
                    };
                }
            </script>
        @endif

        <div class="mt-6 ds-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                <h3 class="text-sm font-black text-slate-900 dark:text-slate-100">Historique des traitements</h3>
                <form method="get" action="{{ route('admin.evaluations.affectations.runs') }}" class="flex items-center gap-2 text-xs">
                    <select name="session_id" onchange="this.form.submit()" class="rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-2 py-1">
                        <option value="">Toutes sessions</option>
                        @foreach($sessions as $s)
                            <option value="{{ $s->id }}" @selected(request('session_id') == $s->id)>{{ $s->nom }}</option>
                        @endforeach
                    </select>
                </form>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800/60 text-[11px] font-black uppercase tracking-wider text-slate-500 border-b border-slate-200 dark:border-slate-800">
                        <tr>
                            <th class="px-4 py-2">Référence</th>
                            <th class="px-4 py-2">Session</th>
                            <th class="px-4 py-2">Mode</th>
                            <th class="px-4 py-2">Statut</th>
                            <th class="px-4 py-2 text-center">Progression</th>
                            <th class="px-4 py-2 text-center">Épreuves</th>
                            <th class="px-4 py-2 text-center">Créées</th>
                            <th class="px-4 py-2">Lancé le</th>
                            <th class="px-4 py-2 text-right">Suivi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @forelse($runs as $run)
                            <tr class="hover:bg-slate-50/70 dark:hover:bg-slate-800/40 {{ request('run') === $run->uuid ? 'bg-indigo-50/60 dark:bg-indigo-950/30' : '' }}">
                                <td class="px-4 py-2 font-mono text-xs">#{{ \Illuminate\Support\Str::limit($run->uuid, 8, '') }}</td>
                                <td class="px-4 py-2 font-semibold">{{ $run->session?->nom ?? '—' }}</td>
                                <td class="px-4 py-2 text-xs">{{ $run->mode }}</td>
                                <td class="px-4 py-2">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold {{ $couleurs[$run->status] ?? '' }}">
                                        {{ $libelles[$run->status] ?? $run->status }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-center tabular-nums">{{ $run->pourcentage() }} %</td>
                                <td class="px-4 py-2 text-center tabular-nums">{{ $run->epreuves_traitees }}/{{ $run->total_epreuves }}</td>
                                <td class="px-4 py-2 text-center tabular-nums">{{ $run->affectations_creees }}</td>
                                <td class="px-4 py-2 text-xs whitespace-nowrap">{{ $run->created_at?->format('d/m/Y H:i') }}</td>
                                <td class="px-4 py-2 text-right">
                                    <a href="{{ route('admin.evaluations.affectations.runs', ['run' => $run->uuid]) }}" class="text-xs font-bold text-indigo-700 dark:text-indigo-300 underline underline-offset-2">Suivre</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="px-4 py-8 text-center text-sm text-slate-500"><x-empty-state message="Aucun traitement d’affectation pour le moment." /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($runs->hasPages())
                <div class="px-4 py-3 border-t border-slate-100 dark:border-slate-800">{{ $runs->links() }}</div>
            @endif
        </div>
    </div>
@endsection
