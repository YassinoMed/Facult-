@extends('layouts.app')

@section('title', 'Appel — Espace Enseignant')

@php
$presents = $compteurs['presents']; $absents = $compteurs['absents']; $retards = $compteurs['retards']; $nonRenseignes = $compteurs['non_renseignes'];
@endphp

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8" x-data="{
            presents: 0, absents: 0, retards: 0, nonRenseignes: 0, confirmerCloture: false, confirmerTous: false,
            recompter() {
                const coches = document.querySelectorAll('#form-appel input[type=radio]:checked');
                this.presents = 0; this.absents = 0; this.retards = 0;
                coches.forEach(r => { if (r.value === 'present') this.presents++; else if (r.value === 'absent') this.absents++; else if (r.value === 'retard') this.retards++; });
                const total = document.querySelectorAll('#form-appel tbody tr[data-etudiant]').length;
                this.nonRenseignes = total - (this.presents + this.absents + this.retards);
            }
        }" x-init="$nextTick(() => recompter())">
        <x-page-header :titre="'Appel : ' . ($appel->slot->matiere?->nom ?? '—')" :description="$appel->slot->schedule?->groupe?->nom . ' · ' . $appel->date->format('d/m/Y') . ' · ' . substr((string) $appel->slot->start_time, 0, 5) . ' → ' . substr((string) $appel->slot->end_time, 0, 5)"
            :fil="['Appel' => route('enseignant.appel.selection'), 'Feuille' => '#']">
            <x-slot:actions>
                <x-status-badge :statut="$appel->statut" />
                <x-btn variant="secondary" href="{{ route('enseignant.appel.exporter', $appel) }}">Export CSV</x-btn>
            </x-slot:actions>
        </x-page-header>

        @if(session('success'))
            <div class="mt-4"><x-alert type="success" :message="session('success')" /></div>
        @endif
        @if($errors->any())
            <div class="mt-4"><x-alert type="error" :message="$errors->first()" /></div>
        @endif

        {{-- Compteurs temps réel (Alpine, zéro requête) --}}
        <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-3" aria-live="polite">
            <div class="rounded-2xl border border-emerald-200 dark:border-emerald-900 bg-emerald-50/50 dark:bg-emerald-950/20 p-4 shadow-sm">
                <p class="text-[11px] font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-400">Présents</p>
                <p class="mt-1 text-3xl font-black tabular-nums text-emerald-800 dark:text-emerald-200" x-text="presents"></p>
            </div>
            <div class="rounded-2xl border border-rose-200 dark:border-rose-900 bg-rose-50/50 dark:bg-rose-950/20 p-4 shadow-sm">
                <p class="text-[11px] font-bold uppercase tracking-wider text-rose-700 dark:text-rose-400">Absents</p>
                <p class="mt-1 text-3xl font-black tabular-nums text-rose-800 dark:text-rose-200" x-text="absents"></p>
            </div>
            <div class="rounded-2xl border border-amber-200 dark:border-amber-900 bg-amber-50/50 dark:bg-amber-950/20 p-4 shadow-sm">
                <p class="text-[11px] font-bold uppercase tracking-wider text-amber-700 dark:text-amber-400">Retards</p>
                <p class="mt-1 text-3xl font-black tabular-nums text-amber-800 dark:text-amber-200" x-text="retards"></p>
            </div>
            <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-900 p-4 shadow-sm">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Non renseignés</p>
                <p class="mt-1 text-3xl font-black tabular-nums text-slate-700 dark:text-slate-300" x-text="nonRenseignes"></p>
            </div>
        </div>

        {{-- Barre de progression de l'appel --}}
        <div class="mt-3 p-4 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm">
            <div class="flex items-center justify-between text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                <span>Progression de l'appel</span>
                <span x-text="Math.round(((presents + absents + retards) / Math.max(1, (presents + absents + retards + nonRenseignes))) * 100) + ' %'"></span>
            </div>
            <div class="w-full bg-slate-100 dark:bg-slate-800 rounded-full h-3 overflow-hidden">
                <div class="bg-indigo-600 h-3 rounded-full transition-all duration-300"
                     :style="'width: ' + Math.round(((presents + absents + retards) / Math.max(1, (presents + absents + retards + nonRenseignes))) * 100) + '%'"></div>
            </div>
        </div>

        <p class="mt-2 text-xs text-slate-500">Enregistrés : {{ $presents }} présent(s), {{ $absents }} absent(s), {{ $retards }} retard(s), {{ $nonRenseignes }} non renseigné(s).</p>

        @if(! $appel->estCloture())
            <form method="GET" action="{{ route('enseignant.appel.feuille', $appel) }}" class="mt-4 flex flex-wrap gap-2 items-end">
                <div class="flex-1 min-w-[200px]">
                    <label for="search" class="sr-only">Rechercher un étudiant</label>
                    <input type="search" id="search" name="search" value="{{ $recherche }}" placeholder="Nom, prénom, n° inscription…"
                           class="w-full rounded-xl border border-slate-300 dark:border-slate-700 px-4 py-2.5 text-sm bg-white dark:bg-slate-900 min-h-[44px]">
                </div>
                <div>
                    <label for="statut" class="sr-only">Filtrer par statut</label>
                    <select id="statut" name="statut" class="rounded-xl border border-slate-300 dark:border-slate-700 px-3 py-2 text-sm bg-white dark:bg-slate-900 min-h-[44px]">
                        <option value="">Tous</option>
                        @foreach(['present' => 'Présents', 'absent' => 'Absents', 'retard' => 'Retards', 'non_renseigne' => 'Non renseignés'] as $code => $libelle)
                            <option value="{{ $code }}" @selected($filtreStatut === $code)>{{ $libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <x-btn type="submit" variant="secondary">Filtrer</x-btn>
            </form>

            <form method="POST" action="{{ route('enseignant.appel.enregistrer', $appel) }}" id="form-appel" class="mt-4" @change="recompter()">
                @csrf
                <div class="flex flex-wrap gap-2 mb-3">
                    <x-btn type="button" variant="secondary" @click="confirmerTous = true">Tout marquer présent</x-btn>
                    <x-btn type="button" variant="ghost" onclick="document.getElementById('form-appel').reset(); document.getElementById('form-appel').dispatchEvent(new Event('change', {bubbles: true}))">Réinitialiser les non enregistrés</x-btn>
                </div>

                <section aria-label="Liste d'appel" class="overflow-x-auto rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm">
                    <table class="w-full min-w-[640px] text-left text-xs sm:text-sm">
                        <thead>
                            <tr class="border-b border-slate-100 dark:border-slate-800 text-[11px] uppercase tracking-wider text-slate-400">
                                <th scope="col" class="px-5 py-3 font-bold">Étudiant</th>
                                <th scope="col" class="px-5 py-3 font-bold">N° inscription</th>
                                <th scope="col" class="px-5 py-3 font-bold" role="group" aria-label="Statut">Statut de présence</th>
                                @unless($appel->estCloture())
                                    <th scope="col" class="px-5 py-3 font-bold"><span class="sr-only">QR</span></th>
                                @endunless
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            @forelse($etudiants as $etudiant)
                                @php $releve = $releves->get($etudiant->id); $statutActuel = $releve?->statut; @endphp
                                <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/30" data-etudiant="{{ $etudiant->id }}">
                                    <td class="px-5 py-3 font-bold text-slate-800 dark:text-slate-100">
                                        <div class="flex items-center gap-2.5">
                                            <div class="w-8 h-8 rounded-full bg-indigo-100 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300 font-bold flex items-center justify-center text-xs shrink-0">
                                                {{ mb_substr($etudiant->prenom, 0, 1) }}{{ mb_substr($etudiant->nom, 0, 1) }}
                                            </div>
                                            <span>{{ $etudiant->nom_complet }}</span>
                                        </div>
                                    </td>
                                    <td class="px-5 py-3 font-mono text-xs text-slate-500">{{ $etudiant->num_inscription }}</td>
                                    <td class="px-5 py-3">
                                        <input type="hidden" name="releves[{{ $etudiant->id }}][etudiant_id]" value="{{ $etudiant->id }}">
                                        <div class="flex items-center gap-1.5" role="radiogroup" aria-label="Statut de {{ $etudiant->nom_complet }}">
                                            <label class="inline-flex items-center justify-center rounded-lg border px-3 py-1.5 text-xs font-bold cursor-pointer transition has-checked:bg-emerald-600 has-checked:text-white has-checked:border-emerald-600 border-slate-300 dark:border-slate-700 hover:border-emerald-500 min-h-[36px]">
                                                <input type="radio" name="releves[{{ $etudiant->id }}][statut]" value="present"
                                                       @checked($statutActuel === 'present') class="sr-only">
                                                P <span class="hidden sm:inline ml-1 font-normal text-[11px]">(Présent)</span>
                                            </label>
                                            <label class="inline-flex items-center justify-center rounded-lg border px-3 py-1.5 text-xs font-bold cursor-pointer transition has-checked:bg-rose-600 has-checked:text-white has-checked:border-rose-600 border-slate-300 dark:border-slate-700 hover:border-rose-500 min-h-[36px]">
                                                <input type="radio" name="releves[{{ $etudiant->id }}][statut]" value="absent"
                                                       @checked($statutActuel === 'absent') class="sr-only">
                                                A <span class="hidden sm:inline ml-1 font-normal text-[11px]">(Absent)</span>
                                            </label>
                                            <label class="inline-flex items-center justify-center rounded-lg border px-3 py-1.5 text-xs font-bold cursor-pointer transition has-checked:bg-amber-600 has-checked:text-white has-checked:border-amber-600 border-slate-300 dark:border-slate-700 hover:border-amber-500 min-h-[36px]">
                                                <input type="radio" name="releves[{{ $etudiant->id }}][statut]" value="retard"
                                                       @checked($statutActuel === 'retard') class="sr-only">
                                                R <span class="hidden sm:inline ml-1 font-normal text-[11px]">(Retard)</span>
                                            </label>
                                            <label class="inline-flex items-center justify-center rounded-lg border px-2 py-1.5 text-xs font-bold cursor-pointer transition has-checked:bg-slate-500 has-checked:text-white has-checked:border-slate-500 border-slate-200 dark:border-slate-800 text-slate-400 hover:text-slate-600 min-h-[36px]" title="Réinitialiser à Non renseigné">
                                                <input type="radio" name="releves[{{ $etudiant->id }}][statut]" value="non_renseigne"
                                                       @checked(! $statutActuel) class="sr-only">
                                                ✕
                                            </label>
                                        </div>
                                        @if($statutActuel)<p class="mt-0.5 text-[11px] text-slate-400">Enregistré : {{ $statutActuel }}</p>@endif
                                    </td>
                                    @unless($appel->estCloture())
                                        <td class="px-5 py-3 text-right">
                                            <a href="{{ route('enseignant.appel.qr', [$appel, $etudiant]) }}" target="_blank" rel="noopener"
                                               class="font-bold text-indigo-700 dark:text-indigo-300 underline underline-offset-2">QR</a>
                                        </td>
                                    @endunless
                                </tr>
                            @empty
                                <tr><td colspan="3" class="p-5"><x-empty-state message="Aucun étudiant pour ces critères." /></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </section>

                <div class="mt-3 flex flex-col sm:flex-row gap-2">
                    <x-btn type="submit">Enregistrer l’appel</x-btn>
                </div>

                <x-modal titre="Tout marquer présent ?" nom="confirmerTous">
                    <p>Tous les étudiants affichés seront marqués présents (les choix déjà cochés sont conservés pour les autres).</p>
                    <x-slot:actions>
                        <x-btn variant="secondary" @click="confirmerTous = false">Annuler</x-btn>
                        <x-btn @click="document.querySelectorAll('#form-appel input[type=radio][value=present]').forEach(r => r.checked = true); confirmerTous = false">Confirmer</x-btn>
                    </x-slot:actions>
                </x-modal>
            </form>

            <form method="POST" action="{{ route('enseignant.appel.cloturer', $appel) }}" id="form-cloture" class="mt-3 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 shadow-sm"
                  @submit.prevent="if ({{ $nonRenseignes > 0 ? 'true' : 'false' }} && ! confirmerCloture) { confirmerCloture = true } else { $el.submit() }">
                @csrf
                <h3 class="text-sm font-black text-slate-900 dark:text-slate-100">Clôturer l’appel</h3>
                @if($nonRenseignes > 0)
                    <p class="mt-1 text-xs text-amber-700 dark:text-amber-300 font-bold">{{ $nonRenseignes }} étudiant(s) non renseigné(s) : ils resteront non renseignés (jamais absents). Cochez la case pour confirmer explicitement.</p>
                    <label class="mt-2 flex items-center gap-2 text-sm font-bold text-slate-700 dark:text-slate-200 cursor-pointer">
                        <input type="checkbox" id="confirmer-incomplet" name="confirmer_incomplet" value="1" class="h-5 w-5 rounded">
                        Je confirme la clôture avec {{ $nonRenseignes }} non renseigné(s)
                    </label>
                @endif
                <div class="mt-2"><x-btn type="submit" variant="primary">Clôturer l’appel</x-btn></div>
                <x-modal titre="Clôturer avec des non renseignés ?" nom="confirmerCloture">
                    <p>Des étudiants resteront non renseignés. Ils ne seront pas marqués absents.</p>
                    <x-slot:actions>
                        <x-btn variant="secondary" @click="confirmerCloture = false">Annuler</x-btn>
                        <x-btn @click="document.getElementById('confirmer-incomplet').checked = true; confirmerCloture = false; document.getElementById('form-cloture').requestSubmit()">Clôturer quand même</x-btn>
                    </x-slot:actions>
                </x-modal>
            </form>
        @else
            {{-- Appel clôturé : lecture + corrections motivées --}}
            <section aria-label="Relevé clôturé" class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm">
                <table class="w-full min-w-[640px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 dark:border-slate-800 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-5 py-3 font-bold">Étudiant</th>
                            <th scope="col" class="px-5 py-3 font-bold">Statut</th>
                            <th scope="col" class="px-5 py-3 font-bold">Correction motivée</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach($etudiants as $etudiant)
                            @php $releve = $releves->get($etudiant->id); @endphp
                            <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/30">
                                <td class="px-5 py-3 font-bold">{{ $etudiant->nom_complet }}<span class="block text-xs font-normal font-mono text-slate-500">{{ $etudiant->num_inscription }}</span></td>
                                <td class="px-5 py-3"><x-status-badge :statut="$releve?->statut ?? 'non_renseigne'" /></td>
                                <td class="px-5 py-3">
                                    <form method="POST" action="{{ route('enseignant.appel.corriger', $appel) }}" class="flex flex-wrap gap-1 items-center">
                                        @csrf
                                        <input type="hidden" name="etudiant_id" value="{{ $etudiant->id }}">
                                        <select name="statut" required aria-label="Nouveau statut" class="rounded-lg border border-slate-300 dark:border-slate-700 px-2 py-1.5 text-xs bg-white dark:bg-slate-800 min-h-[36px]">
                                            @foreach(['present' => 'Présent', 'absent' => 'Absent', 'retard' => 'Retard'] as $code => $libelle)
                                                <option value="{{ $code }}">{{ $libelle }}</option>
                                            @endforeach
                                        </select>
                                        <input type="text" name="motif" required minlength="3" maxlength="500" placeholder="Motif obligatoire" aria-label="Motif de correction"
                                               class="rounded-lg border border-slate-300 dark:border-slate-700 px-2 py-1.5 text-xs bg-white dark:bg-slate-800 min-h-[36px] flex-1 min-w-[140px]">
                                        <x-btn type="submit" variant="secondary" class="text-xs py-1.5 px-3">Corriger</x-btn>
                                    </form>
                                    @if(! empty($releve?->historique))
                                        <details class="mt-1 text-[11px] text-slate-500">
                                            <summary class="cursor-pointer underline underline-offset-2">Historique ({{ count($releve->historique) }})</summary>
                                            <ul class="mt-1 space-y-0.5">
                                                @foreach($releve->historique as $trace)
                                                    <li>{{ $trace['date'] ?? '' }} · {{ $trace['action'] ?? '' }}{{ ! empty($trace['motif']) ? ' · ' . $trace['motif'] : '' }}</li>
                                                @endforeach
                                            </ul>
                                        </details>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endif
    </div>
@endsection
