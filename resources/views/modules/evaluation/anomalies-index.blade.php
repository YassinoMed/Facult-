@extends('layouts.app')

@section('title', 'Centre de contrôle des anomalies — Évaluations')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 py-8" x-data="{
        selection: [],
        toutSelectionner: false,
        toggleTous(valideIds) {
            this.selection = this.toutSelectionner ? [...valideIds] : [];
        }
    }">
        <x-page-header titre="Centre de contrôle des anomalies"
            description="Détection, diagnostic et résolution centralisée des anomalies de notes, épreuves et délibérations."
            :fil="['Évaluations' => route('admin.evaluations.index'), 'Anomalies' => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('admin.evaluations.anomalies.export', request()->query()) }}">
                    Exporter CSV
                </x-btn>
                <x-btn variant="ghost" href="{{ route('admin.evaluations.anomalies.index') }}">
                    Actualiser
                </x-btn>
            </x-slot:actions>
        </x-page-header>

        @if(session('succes'))
            <x-alert type="success" class="mt-4">
                {{ session('succes') }}
            </x-alert>
        @endif

        @if($errors->any())
            <x-alert type="danger" class="mt-4">
                <ul class="list-disc pl-5">
                    @foreach($errors->all() as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </x-alert>
        @endif

        {{-- Compteurs KPI --}}
        <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div class="ds-card p-4">
                <p class="text-2xl font-black tabular-nums text-slate-900">{{ $kpis['total'] }}</p>
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Total anomalies</p>
            </div>
            <div class="ds-card p-4 border-l-4 border-l-rose-500">
                <p class="text-2xl font-black tabular-nums text-rose-600">{{ $kpis['bloquante'] }}</p>
                <p class="text-[11px] font-bold uppercase tracking-wider text-rose-600">Bloquantes (à corriger)</p>
            </div>
            <div class="ds-card p-4 border-l-4 border-l-amber-500">
                <p class="text-2xl font-black tabular-nums text-amber-600">{{ $kpis['avertissement'] }}</p>
                <p class="text-[11px] font-bold uppercase tracking-wider text-amber-600">Avertissements</p>
            </div>
            <div class="ds-card p-4 border-l-4 border-l-sky-500">
                <p class="text-2xl font-black tabular-nums text-sky-600">{{ $kpis['info'] }}</p>
                <p class="text-[11px] font-bold uppercase tracking-wider text-sky-600">Informatifs</p>
            </div>
        </div>

        {{-- Filtres de recherche --}}
        <div class="mt-4 ds-card p-4 sm:p-5">
            <form method="GET" action="{{ route('admin.evaluations.anomalies.index') }}" class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-end">
                <div>
                    <label for="recherche" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">Recherche</label>
                    <input type="text" id="recherche" name="recherche" value="{{ $filtres['recherche'] ?? '' }}"
                           placeholder="Étudiant, épreuve, libellé..."
                           class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-primary-700 focus:outline-none focus:ring-4 focus:ring-primary-500/20 transition">
                </div>
                <div>
                    <label for="gravite" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">Gravité</label>
                    <select id="gravite" name="gravite" class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-sm text-slate-900 focus:border-primary-700 focus:outline-none focus:ring-4 focus:ring-primary-500/20 transition">
                        <option value="">Toutes les gravités</option>
                        <option value="bloquante" @selected(($filtres['gravite'] ?? '') === 'bloquante')>Bloquante</option>
                        <option value="avertissement" @selected(($filtres['gravite'] ?? '') === 'avertissement')>Avertissement</option>
                        <option value="info" @selected(($filtres['gravite'] ?? '') === 'info')>Information</option>
                    </select>
                </div>
                <div>
                    <label for="type" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">Type d'anomalie</label>
                    <select id="type" name="type" class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-sm text-slate-900 focus:border-primary-700 focus:outline-none focus:ring-4 focus:ring-primary-500/20 transition">
                        <option value="">Tous les types</option>
                        <option value="candidat_sans_note" @selected(($filtres['type'] ?? '') === 'candidat_sans_note')>Candidats sans note</option>
                        <option value="note_hors_bornes" @selected(($filtres['type'] ?? '') === 'note_hors_bornes')>Notes hors barème</option>
                        <option value="note_en_attente" @selected(($filtres['type'] ?? '') === 'note_en_attente')>Notes en attente</option>
                        <option value="note_desynchronisee" @selected(($filtres['type'] ?? '') === 'note_desynchronisee')>Notes désynchronisées</option>
                        <option value="absent_avec_note" @selected(($filtres['type'] ?? '') === 'absent_avec_note')>Absents avec note</option>
                        <option value="coefficient_incoherent" @selected(($filtres['type'] ?? '') === 'coefficient_incoherent')>Coefficients incohérents</option>
                    </select>
                </div>
                <div class="flex items-center gap-2">
                    <x-btn type="submit" variant="primary" size="md" class="flex-1">
                        Filtrer
                    </x-btn>
                    @if(!empty($filtres['recherche']) || !empty($filtres['gravite']) || !empty($filtres['type']))
                        <x-btn variant="secondary" size="md" href="{{ route('admin.evaluations.anomalies.index') }}">
                            Réinitialiser
                        </x-btn>
                    @endif
                </div>
            </form>
        </div>

        {{-- Tableau et Actions groupées --}}
        <form method="POST" action="{{ route('admin.evaluations.anomalies.resoudre-groupee') }}" class="mt-4">
            @csrf

            <div class="mb-3 flex flex-wrap items-center justify-between gap-3 p-3.5 rounded-2xl border transition-all duration-200"
                 :class="selection.length > 0 ? 'border-primary-300 bg-primary-50/70 shadow-xs' : 'border-slate-200 bg-white'">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-600">Actions groupées :</span>
                    <select name="action" class="rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-800 focus:border-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500/20 disabled:opacity-50"
                            :disabled="selection.length === 0">
                        <option value="valider">Valider les notes soumises sélectionnées</option>
                        <option value="synchroniser">Resynchroniser les notes validées sélectionnées</option>
                    </select>
                    <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-3.5 py-1.5 text-xs font-bold text-white hover:bg-slate-800 active:scale-[0.98] transition-all disabled:opacity-50 disabled:cursor-not-allowed shadow-xs"
                            :disabled="selection.length === 0">
                        Appliquer à <span class="mx-1 font-mono font-black" x-text="selection.length"></span> élément(s)
                    </button>
                    <button type="button" x-show="selection.length > 0" x-cloak @click="selection = []; toutSelectionner = false"
                            class="text-xs font-semibold text-slate-500 hover:text-slate-800 underline underline-offset-2 ml-1">
                        Désélectionner tout
                    </button>
                </div>
                <div class="text-xs font-semibold text-slate-500 tabular-nums">
                    Affichage de {{ $anomalies->firstItem() ?? 0 }} à {{ $anomalies->lastItem() ?? 0 }} sur {{ $anomalies->total() }} anomalie(s)
                </div>
            </div>

            <div class="ds-card overflow-hidden">
                @if($anomalies->isEmpty())
                    <x-empty-state
                        titre="Aucune anomalie détectée"
                        message="Toutes les données d'évaluation du périmètre sont cohérentes et synchronisées." />
                @else
                    <div class="overflow-x-auto max-h-[650px] relative">
                        <table class="w-full text-sm">
                            <thead class="sticky top-0 z-10 bg-slate-50/95 backdrop-blur-xs text-left text-[11px] uppercase tracking-wider text-slate-500 border-b border-slate-200 shadow-xs">
                                <tr>
                                    <th class="w-10 px-4 py-3">
                                        <input type="checkbox" x-model="toutSelectionner"
                                               @change="toggleTous({{ json_encode(collect($anomalies->items())->where('resoluble_automatiquement', true)->pluck('id')->values()->all()) }})"
                                               class="rounded border-slate-300 text-primary-800 focus:ring-primary-500">
                                    </th>
                                    <th class="px-4 py-3">Gravité</th>
                                    <th class="px-4 py-3">Périmètre / ECUE</th>
                                    <th class="px-4 py-3">Étudiant / Référence</th>
                                    <th class="px-4 py-3">Diagnostic</th>
                                    <th class="px-4 py-3 text-right">Action directe</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($anomalies as $anomalie)
                                    <tr class="hover:bg-primary-50/30 transition-colors">
                                        <td class="px-4 py-3">
                                            @if($anomalie['resoluble_automatiquement'])
                                                <input type="checkbox" name="anomalie_ids[]" value="{{ $anomalie['id'] }}"
                                                       x-model="selection"
                                                       class="rounded border-slate-300 text-primary-800 focus:ring-primary-500">
                                            @else
                                                <span class="inline-block h-4 w-4 text-slate-300 text-center text-xs">—</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            @if($anomalie['gravite'] === 'bloquante')
                                                <span class="inline-flex items-center gap-1.5 rounded-full bg-rose-50 px-2.5 py-0.5 text-xs font-bold text-rose-700 border border-rose-200">
                                                    <span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span>
                                                    Bloquante
                                                </span>
                                            @elseif($anomalie['gravite'] === 'avertissement')
                                                <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-bold text-amber-700 border border-amber-200">
                                                    <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                                                    Avertissement
                                                </span>
                                            @else
                                                <span class="inline-flex items-center gap-1.5 rounded-full bg-sky-50 px-2.5 py-0.5 text-xs font-bold text-sky-700 border border-sky-200">
                                                    <span class="h-1.5 w-1.5 rounded-full bg-sky-500"></span>
                                                    Info
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 font-semibold text-slate-900">
                                            {{ $anomalie['perimetre'] }}
                                        </td>
                                        <td class="px-4 py-3 text-xs text-slate-600">
                                            {{ $anomalie['etudiant_ref'] ?? 'Global épreuve' }}
                                        </td>
                                        <td class="px-4 py-3 text-xs text-slate-700">
                                            {{ $anomalie['libelle'] }}
                                        </td>
                                        <td class="px-4 py-3 text-right whitespace-nowrap">
                                            <a href="{{ $anomalie['action_url'] }}"
                                               class="inline-flex items-center gap-1.5 rounded-xl border border-primary-200 bg-primary-50/80 px-3 py-1.5 text-xs font-bold text-primary-800 hover:bg-primary-100 hover:text-primary-900 active:scale-[0.98] transition-all">
                                                <span>Corriger</span>
                                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="px-4 py-3 border-t border-slate-100">
                        {{ $anomalies->links() }}
                    </div>
                @endif
            </div>
        </form>
    </div>
@endsection
