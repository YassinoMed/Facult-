@extends('layouts.app')

@section('title', 'Gestion des propositions d\'affectation')

@section('content')
<div class="mx-auto max-w-7xl px-4 sm:px-6 py-8" x-data="{
    selections: [],
    tousCoches: false,
    modaleModifier: false,
    modaleRejeter: false,
    proposalCourant: null,
    nouveauGroupeId: '',
    motifModif: 'Ajustement manuel par l\'administrateur',
    motifRefus: '',

    basculerTous(coche) {
        if (coche) {
            this.selections = {{ Js::from($propositions->pluck('id')) }};
        } else {
            this.selections = [];
        }
        this.tousCoches = coche;
    },

    ouvrirModifier(prop) {
        this.proposalCourant = prop;
        this.nouveauGroupeId = prop.proposed_group_id;
        this.modaleModifier = true;
    },

    groupesCompatiblesCourants() {
        return (this.proposalCourant && this.proposalCourant.metadata && this.proposalCourant.metadata.groupes_compatibles) || {};
    },

    alternativesCourantes() {
        return (this.proposalCourant && this.proposalCourant.metadata && this.proposalCourant.metadata.alternatives) || [];
    },

    aCompatiblesCourants() {
        return Object.keys(this.groupesCompatiblesCourants()).length > 0;
    },

    ouvrirRejeter(prop) {
        this.proposalCourant = prop;
        this.motifRefus = '';
        this.modaleRejeter = true;
    }
}">
    {{-- En-tête de page --}}
    <x-page-header 
        titre="Propositions d'affectation" 
        description="Mode semi-automatique : examinez les propositions d'affectation générées par le moteur de scoring, ajustez les groupes cibles ou validez en masse."
        :fil="['Gestion académique' => '#', 'Affectations' => route('admin.affectations.masse'), 'Propositions' => '#']">
        <x-slot:actions>
            <a href="{{ route('admin.affectations.masse') }}" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">
                <svg class="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
                </svg>
                Cockpit de masse
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- Indicateurs rapides du cockpit --}}
    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <p class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-amber-600 dark:text-amber-400">
                <span class="h-2 w-2 rounded-full bg-amber-500" aria-hidden="true"></span>Propositions en attente
            </p>
            <p class="mt-1 text-3xl font-black tabular-nums text-slate-900 dark:text-white">{{ number_format($enAttenteCount, 0, ',', ' ') }}</p>
            <p class="text-[11px] text-slate-500 mt-1">Nécessitent validation administrative</p>
        </div>

        <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <p class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">
                <span class="h-2 w-2 rounded-full bg-emerald-500" aria-hidden="true"></span>Certitude élevée
            </p>
            <p class="mt-1 text-3xl font-black tabular-nums text-emerald-600 dark:text-emerald-400">{{ number_format($eleveeCount, 0, ',', ' ') }}</p>
            <p class="text-[11px] text-slate-500 mt-1">Score optimal &ge; 85/100 sans ambiguïté</p>
        </div>

        <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <p class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-blue-600 dark:text-blue-400">
                <span class="h-2 w-2 rounded-full bg-blue-500" aria-hidden="true"></span>Cas ambigus
            </p>
            <p class="mt-1 text-3xl font-black tabular-nums text-blue-600 dark:text-blue-400">{{ number_format($ambiguesCount, 0, ',', ' ') }}</p>
            <p class="text-[11px] text-slate-500 mt-1">Groupes équivalents ou capacité tendue</p>
        </div>

        <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <p class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-slate-500">
                <span class="h-2 w-2 rounded-full bg-slate-500" aria-hidden="true"></span>Score moyen global
            </p>
            <p class="mt-1 text-3xl font-black tabular-nums text-slate-900 dark:text-white">{{ $scoreMoyen }} <span class="text-sm font-normal text-slate-400">/ 100</span></p>
            <p class="text-[11px] text-slate-500 mt-1">Indice de pertinence du moteur</p>
        </div>
    </div>

    {{-- Filtres de consultation --}}
    <div class="mt-6 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <form method="GET" action="{{ route('admin.affectations.propositions.index') }}" class="flex flex-wrap items-center gap-3">
            <div class="flex items-center gap-2">
                <label for="annee_universitaire_id" class="text-xs font-bold text-slate-600 dark:text-slate-400">Année :</label>
                <select id="annee_universitaire_id" name="annee_universitaire_id" onchange="this.form.submit()" class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs text-slate-900 shadow-sm focus:border-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white">
                    @foreach($annees as $a)
                        <option value="{{ $a->id }}" @selected($anneeId === $a->id)>{{ $a->libelle }} @if($a->active)(Courante)@endif</option>
                    @endforeach
                </select>
            </div>

            <div class="flex items-center gap-2">
                <label for="certitude" class="text-xs font-bold text-slate-600 dark:text-slate-400">Certitude :</label>
                <select id="certitude" name="certitude" onchange="this.form.submit()" class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs text-slate-900 shadow-sm focus:border-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white">
                    <option value="">Toutes</option>
                    <option value="elevee" @selected(request('certitude') === 'elevee')>Élevée (&ge; 85)</option>
                    <option value="moyenne" @selected(request('certitude') === 'moyenne')>Moyenne (70-84)</option>
                    <option value="faible" @selected(request('certitude') === 'faible')>Faible / Ambiguë (&lt; 70)</option>
                </select>
            </div>

            <div class="flex items-center gap-2">
                <label for="statut" class="text-xs font-bold text-slate-600 dark:text-slate-400">Statut :</label>
                <select id="statut" name="statut" onchange="this.form.submit()" class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs text-slate-900 shadow-sm focus:border-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white">
                    <option value="en_attente" @selected(request('statut', 'en_attente') === 'en_attente')>En attente de validation</option>
                    <option value="acceptee" @selected(request('statut') === 'acceptee')>Acceptées</option>
                    <option value="modifiee" @selected(request('statut') === 'modifiee')>Modifiées puis acceptées</option>
                    <option value="rejetee" @selected(request('statut') === 'rejetee')>Rejetées</option>
                </select>
            </div>
        </form>
    </div>

    {{-- Actions de masse sur sélection --}}
    <form method="POST" action="{{ route('admin.affectations.propositions.bulk-accepter') }}" id="formBulk">
        @csrf
        <template x-for="id in selections" :key="id">
            <input type="hidden" name="proposal_ids[]" :value="id">
        </template>
    </form>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-3" x-show="selections.length > 0" x-cloak x-transition>
        <div class="flex items-center gap-2 rounded-xl bg-primary-50 px-3.5 py-2 text-xs font-bold text-primary-900 dark:bg-primary-950/40 dark:text-primary-300 border border-primary-200">
            <span class="flex h-5 w-5 items-center justify-center rounded-full bg-primary-600 text-[10px] text-white" x-text="selections.length"></span>
            proposition(s) sélectionnée(s)
        </div>

        <button type="button" onclick="document.getElementById('formBulk').submit()" class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-4 py-2 text-xs font-bold text-white shadow-sm hover:bg-emerald-700">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
            Valider et affecter la sélection
        </button>
    </div>

    {{-- Table des propositions --}}
    <div class="mt-4 rounded-2xl border border-slate-200/80 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-left text-xs dark:divide-slate-800">
                <thead class="bg-slate-50 font-bold uppercase tracking-wider text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                    <tr>
                        <th class="px-3.5 py-3 w-10 text-center">
                            <input type="checkbox" :checked="tousCoches" @change="basculerTous($event.target.checked)" class="rounded border-slate-300 text-primary-600 focus:ring-primary-500" />
                        </th>
                        <th class="px-3.5 py-3">Étudiant</th>
                        <th class="px-3.5 py-3">Niveau & Formation</th>
                        <th class="px-3.5 py-3">Groupe proposé</th>
                        <th class="px-3.5 py-3 text-center">Score /100</th>
                        <th class="px-3.5 py-3">Certitude</th>
                        <th class="px-3.5 py-3">Justifications</th>
                        <th class="px-3.5 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800/60">
                    @forelse($propositions as $prop)
                        <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/40">
                            <td class="px-3.5 py-3 text-center">
                                @if($prop->estEnAttente())
                                    <input type="checkbox" value="{{ $prop->id }}" x-model="selections" class="rounded border-slate-300 text-primary-600 focus:ring-primary-500" />
                                @endif
                            </td>
                            <td class="px-3.5 py-3">
                                <div class="font-bold text-slate-900 dark:text-white">{{ $prop->student?->nom_complet }}</div>
                                <div class="text-[11px] text-slate-500">
                                    CIN: <span class="font-mono">{{ ($cinProposition = $prop->student?->cin) ? \App\Services\Io\Exports\ExportColumnPolicy::masquerCin($cinProposition, auth()->user()) : '—' }}</span>
                                    • N°: <span class="font-mono">{{ $prop->student?->num_inscription }}</span>
                                </div>
                            </td>
                            <td class="px-3.5 py-3 text-slate-700 dark:text-slate-300">
                                <span class="font-bold text-primary-700 dark:text-primary-300">{{ $prop->student?->niveau }}</span>
                                <span class="text-slate-400">/</span>
                                <span>{{ $prop->student?->programmeRef?->nom ?? 'Général' }}</span>
                            </td>
                            <td class="px-3.5 py-3">
                                <span class="rounded-lg bg-slate-100 px-2 py-1 font-mono font-bold text-slate-900 dark:bg-slate-800 dark:text-white">
                                    {{ $prop->proposedGroup?->code }}
                                </span>
                            </td>
                            <td class="px-3.5 py-3 text-center">
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-black tabular-nums @if($prop->score >= 85) bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-400 @elseif($prop->score >= 70) bg-amber-50 text-amber-700 dark:bg-amber-950/50 dark:text-amber-400 @else bg-rose-50 text-rose-700 dark:bg-rose-950/50 dark:text-rose-400 @endif">
                                    {{ $prop->score }}/100
                                </span>
                            </td>
                            <td class="px-3.5 py-3">
                                @if($prop->certitude === 'elevee')
                                    <span class="inline-flex items-center gap-1 rounded-md bg-emerald-100 px-2 py-0.5 text-[10px] font-bold text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300">
                                        ✓ Élevée
                                    </span>
                                @elseif($prop->certitude === 'moyenne')
                                    <span class="inline-flex items-center gap-1 rounded-md bg-amber-100 px-2 py-0.5 text-[10px] font-bold text-amber-800 dark:bg-amber-950/60 dark:text-amber-300">
                                        ⚠ Moyenne
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 rounded-md bg-rose-100 px-2 py-0.5 text-[10px] font-bold text-rose-800 dark:bg-rose-950/60 dark:text-rose-300">
                                        ! Ambiguë
                                    </span>
                                @endif
                            </td>
                            <td class="px-3.5 py-3 text-[11px] text-slate-600 dark:text-slate-400 max-w-xs">
                                <ul class="list-disc list-inside space-y-0.5 truncate">
                                    @foreach($prop->reasons ?? [] as $r)
                                        <li class="truncate">{{ $r }}</li>
                                    @endforeach
                                </ul>
                            </td>
                            <td class="px-3.5 py-3 text-right">
                                @if($prop->estEnAttente())
                                    <div class="inline-flex items-center gap-1.5">
                                        {{-- Bouton Accepter --}}
                                        <form method="POST" action="{{ route('admin.affectations.propositions.accepter', $prop) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="rounded-lg bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-950/50 dark:text-emerald-400">
                                                Valider
                                            </button>
                                        </form>

                                        {{-- Bouton Modifier --}}
                                        <button type="button" @click="ouvrirModifier({{ Js::from(['id' => $prop->id, 'proposed_group_id' => $prop->proposed_group_id, 'metadata' => $prop->metadata]) }})" class="rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300">
                                            Modifier
                                        </button>

                                        {{-- Bouton Rejeter --}}
                                        <button type="button" @click="ouvrirRejeter({{ Js::from(['id' => $prop->id]) }})" class="rounded-lg bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-100 dark:bg-rose-950/50 dark:text-rose-400">
                                            ✕
                                        </button>
                                    </div>
                                @else
                                    <span class="text-[11px] font-medium text-slate-400 capitalize">
                                        {{ $prop->statut }}
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-8 text-center text-slate-500">
                                Aucune proposition d'affectation pour ces critères.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($propositions->hasPages())
            <div class="p-4 border-t border-slate-200/80 dark:border-slate-800">
                {{ $propositions->links() }}
            </div>
        @endif
    </div>

    {{-- MODALE MODIFIER LE GROUPE --}}
    <div x-show="modaleModifier" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/60" @click="modaleModifier = false"></div>
        <div class="relative w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-slate-900 border border-slate-200 dark:border-slate-800">
            <h3 class="text-base font-bold text-slate-900 dark:text-white">Modifier le groupe cible</h3>
            <p class="text-xs text-slate-500 mt-1">Choisissez un groupe alternatif compatible pour cet étudiant.</p>

            <form method="POST" :action="'/admin/affectations/propositions/' + (proposalCourant ? proposalCourant.id : 0) + '/modifier'" class="mt-4 space-y-4">
                @csrf
                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">Nouveau groupe cible :</label>
                    <template x-if="aCompatiblesCourants()">
                        <select name="nouveau_groupe_id" x-model="nouveauGroupeId" required class="w-full rounded-xl border border-slate-200 p-2 text-xs dark:border-slate-700 dark:bg-slate-800">
                            <template x-for="(libelle, gid) in groupesCompatiblesCourants()" :key="gid">
                                <option :value="gid" x-text="libelle"></option>
                            </template>
                        </select>
                    </template>
                    <template x-if="! aCompatiblesCourants()">
                        <input type="number" name="nouveau_groupe_id" x-model="nouveauGroupeId" required class="w-full rounded-xl border border-slate-200 p-2 text-xs dark:border-slate-700 dark:bg-slate-800" placeholder="ID du groupe">
                    </template>
                    <template x-if="alternativesCourantes().length > 0">
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <template x-for="alt in alternativesCourantes()" :key="alt.group_id">
                                <button type="button" @click="nouveauGroupeId = alt.group_id" class="rounded-lg border border-slate-200 px-2 py-1 text-[11px] font-semibold text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300" x-text="alt.code + ' — ' + alt.score + '/100'"></button>
                            </template>
                        </div>
                    </template>
                    <p class="mt-1 text-[11px] text-slate-400">Seuls les groupes compatibles sont proposés ; la contrainte (niveau, programme, capacité) est revérifiée serveur avant écriture.</p>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">Motif de modification :</label>
                    <input type="text" name="motif" x-model="motifModif" required class="w-full rounded-xl border border-slate-200 p-2 text-xs dark:border-slate-700 dark:bg-slate-800">
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="modaleModifier = false" class="rounded-xl border px-3 py-1.5 text-xs text-slate-700">Annuler</button>
                    <button type="submit" class="rounded-xl bg-primary-600 px-4 py-1.5 text-xs font-bold text-white">Confirmer le changement</button>
                </div>
            </form>
        </div>
    </div>

    {{-- MODALE REJETER LA PROPOSITION --}}
    <div x-show="modaleRejeter" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/60" @click="modaleRejeter = false"></div>
        <div class="relative w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-slate-900 border border-slate-200 dark:border-slate-800">
            <h3 class="text-base font-bold text-slate-900 dark:text-white">Rejeter la proposition</h3>
            <p class="text-xs text-slate-500 mt-1">L'étudiant restera sans affectation pour ce groupe.</p>

            <form method="POST" :action="'/admin/affectations/propositions/' + (proposalCourant ? proposalCourant.id : 0) + '/rejeter'" class="mt-4 space-y-4">
                @csrf
                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">Motif du refus :</label>
                    <textarea name="motif_refus" x-model="motifRefus" required rows="3" class="w-full rounded-xl border border-slate-200 p-2 text-xs dark:border-slate-700 dark:bg-slate-800" placeholder="Précisez la raison administrative..."></textarea>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="modaleRejeter = false" class="rounded-xl border px-3 py-1.5 text-xs text-slate-700">Annuler</button>
                    <button type="submit" class="rounded-xl bg-rose-600 px-4 py-1.5 text-xs font-bold text-white">Rejeter définitivement</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
