@extends('layouts.app')

@section('title', 'Génération documentaire groupée — Évaluations')

@section('content')
<div class="mx-auto max-w-6xl px-4 sm:px-6 py-8" x-data="documentBatchForm()">
    <x-page-header titre="Génération documentaire groupée"
        description="Production d'archives complètes (émargements, feuilles de saisie, PVs) pour une session ou un périmètre."
        :fil="['Évaluations' => route('admin.evaluations.index'), 'Documents groupés' => '#']">
        <x-slot:actions>
            <x-btn variant="secondary" href="{{ route('admin.evaluations.index') }}">Retour tableau de bord</x-btn>
        </x-slot:actions>
    </x-page-header>

    @if(session('status'))
        <div class="mt-4 p-4 rounded-md bg-emerald-50 border border-emerald-200 text-emerald-800 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                <span>{{ session('status') }}</span>
            </div>
            @if(session('lot_telechargeable'))
                <a href="{{ route('admin.evaluations.documents-batch.telecharger', session('lot_telechargeable')) }}"
                   class="inline-flex items-center px-3 py-1.5 rounded bg-emerald-600 text-white font-semibold text-xs hover:bg-emerald-700 shadow-sm transition">
                    Télécharger l'archive ZIP
                </a>
            @endif
        </div>
    @endif

    @if(session('error'))
        <div class="mt-4 p-4 rounded-md bg-rose-50 border border-rose-200 text-rose-800 flex items-center gap-2">
            <svg class="w-5 h-5 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            <span>{{ session('error') }}</span>
        </div>
    @endif

    <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Formulaire de paramétrage -->
        <div class="lg:col-span-2 ds-card p-6 sm:p-7">
            <h2 class="text-base font-black text-slate-900 border-b border-slate-100 pb-3 mb-5">Paramétrage du lot documentaire</h2>

            <form action="{{ route('admin.evaluations.documents-batch.generer') }}" method="POST" x-data="{ envoi: false }" @submit="envoi = true">
                @csrf

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Session d'examen</label>
                        <select name="session_id" x-model="sessionId" @change="reinitialiserApercu()"
                                class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-700 focus:outline-none focus:ring-4 focus:ring-primary-500/20 transition">
                            <option value="">-- Sélectionner une session --</option>
                            @foreach($sessions as $session)
                                <option value="{{ $session->id }}">{{ $session->libelle }} ({{ $session->code }})</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Groupe ciblé (optionnel)</label>
                        <select name="groupe_id" x-model="groupeId" @change="reinitialiserApercu()"
                                class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-700 focus:outline-none focus:ring-4 focus:ring-primary-500/20 transition">
                            <option value="">-- Tous les groupes --</option>
                            @foreach($groupes as $groupe)
                                <option value="{{ $groupe->id }}">{{ $groupe->code }} — {{ $groupe->nom }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="mt-5">
                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">Types de documents à inclure</label>
                    <div class="space-y-2.5">
                        @foreach($typesDocuments as $typeCle => $typeLibelle)
                            <label class="flex items-center gap-3.5 p-3 rounded-xl border border-slate-200/90 hover:bg-slate-50/80 transition cursor-pointer">
                                <input type="checkbox" name="types[]" value="{{ $typeCle }}" x-model="types" @change="reinitialiserApercu()"
                                       class="rounded border-slate-300 text-primary-800 focus:ring-primary-500">
                                <div>
                                    <p class="text-sm font-bold text-slate-900">{{ $typeLibelle }}</p>
                                    <p class="text-xs text-slate-500">
                                        @if($typeCle === 'emargement')
                                            Listes avec signatures, matricules, salles et places pour surveillants.
                                        @elseif($typeCle === 'feuille_notes')
                                            Fiches officielles pour saisie et vérification des notes avec barème.
                                        @elseif($typeCle === 'pv_deliberation')
                                            PVs consolidés de session (réservé aux délibérations validées ou publiées).
                                        @endif
                                    </p>
                                </div>
                            </label>
                        @endforeach
                    </div>
                </div>

                <!-- Aperçu dynamique -->
                <div class="mt-6 border-t border-slate-100 pt-5">
                    <div class="flex items-center justify-between mb-3">
                        <button type="button" @click="calculerApercu()" :disabled="chargementApercu || types.length === 0"
                                class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl border border-slate-300 text-xs font-bold text-slate-700 bg-white hover:bg-slate-50 active:scale-[0.98] disabled:opacity-50 transition shadow-xs">
                            <span x-show="!chargementApercu">Prévisualiser le volume</span>
                            <span x-show="chargementApercu" class="flex items-center gap-1.5">
                                <svg class="animate-spin h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                Calcul en cours...
                            </span>
                        </button>
                    </div>

                    <div x-show="erreurApercu" x-cloak class="p-3 rounded-xl bg-rose-50 border border-rose-200 text-xs text-rose-800 font-semibold mb-3">
                        <span x-text="erreurApercu"></span>
                    </div>

                    <div x-show="apercuDisponible" x-cloak class="p-4 rounded-2xl bg-slate-50/80 border border-slate-200/80 space-y-3">
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5 text-center">
                            <div class="bg-white p-3 rounded-xl border border-slate-200/80 shadow-xs">
                                <p class="text-xl font-black text-slate-900 tabular-nums" x-text="apercuData.nombre_epreuves"></p>
                                <p class="text-[10px] text-slate-500 uppercase font-bold tracking-wider">Épreuves</p>
                            </div>
                            <div class="bg-white p-3 rounded-xl border border-slate-200/80 shadow-xs">
                                <p class="text-xl font-black text-slate-900 tabular-nums" x-text="apercuData.nombre_deliberations"></p>
                                <p class="text-[10px] text-slate-500 uppercase font-bold tracking-wider">Délibérations</p>
                            </div>
                            <div class="bg-white p-3 rounded-xl border border-slate-200/80 shadow-xs">
                                <p class="text-xl font-black text-slate-900 tabular-nums" x-text="apercuData.nombre_candidats"></p>
                                <p class="text-[10px] text-slate-500 uppercase font-bold tracking-wider">Candidats</p>
                            </div>
                            <div class="bg-white p-3 rounded-xl border border-primary-200 bg-primary-50/40 shadow-xs">
                                <p class="text-xl font-black text-primary-800 tabular-nums" x-text="apercuData.nombre_documents_estimes"></p>
                                <p class="text-[10px] text-primary-700 uppercase font-bold tracking-wider">Fichiers PDF</p>
                            </div>
                        </div>

                        <template x-if="apercuData.avertissements && apercuData.avertissements.length > 0">
                            <div class="mt-2 text-xs text-amber-800 bg-amber-50 p-3 rounded-xl border border-amber-200/80 space-y-1">
                                <p class="font-bold">Avertissements :</p>
                                <template x-for="avert in apercuData.avertissements" :key="avert">
                                    <p x-text="'• ' + avert"></p>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-3 border-t border-slate-100 pt-5">
                    <button type="submit" :disabled="types.length === 0 || envoi"
                            class="inline-flex items-center px-5 py-2.5 rounded-xl bg-primary-800 text-white font-bold text-sm hover:bg-primary-700 focus:outline-none focus:ring-4 focus:ring-primary-500/20 active:scale-[0.98] disabled:opacity-50 shadow-sm transition-all duration-150">
                        <span x-show="!envoi">Lancer la génération du lot</span>
                        <span x-show="envoi" class="flex items-center gap-2">
                            <svg class="animate-spin h-4 w-4 text-white" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            Génération en cours...
                        </span>
                    </button>
                </div>
            </form>
        </div>

        <!-- Historique des lots récents -->
        <div class="ds-card p-6 sm:p-7">
            <h2 class="text-base font-black text-slate-900 border-b border-slate-100 pb-3 mb-4">Archives récentes générées</h2>

            @if($recentsRapports->isEmpty())
                <x-empty-state message="Aucun lot documentaire généré récemment." />
            @else
                <div class="space-y-3">
                    @foreach($recentsRapports as $rapport)
                        @php $lotId = $rapport->preview['lot_id'] ?? null; @endphp
                        <div class="p-3.5 rounded-2xl border border-slate-200/80 bg-slate-50/60 flex flex-col gap-2 transition hover:bg-white hover:shadow-xs">
                            <div class="flex items-center justify-between text-xs gap-2">
                                <span class="font-bold text-slate-900 truncate" title="{{ $rapport->filename }}">{{ $rapport->filename }}</span>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase shrink-0
                                    {{ $rapport->status === 'completed' ? 'bg-emerald-100 text-emerald-800 border border-emerald-200' : 'bg-amber-100 text-amber-800 border border-amber-200' }}">
                                    {{ $rapport->status }}
                                </span>
                            </div>
                            <p class="text-[11px] text-slate-500 tabular-nums">
                                {{ $rapport->valid_rows }} document(s) · {{ $rapport->created_at->format('d/m/Y H:i') }}
                            </p>
                            @if($lotId)
                                <a href="{{ route('admin.evaluations.documents-batch.telecharger', $lotId) }}"
                                   class="mt-1 text-xs font-bold text-primary-800 hover:text-primary-900 inline-flex items-center gap-1.5 transition">
                                    <span>Télécharger le ZIP</span>
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                </a>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>

<script>
function documentBatchForm() {
    return {
        sessionId: '',
        groupeId: '',
        types: ['emargement', 'feuille_notes'],
        chargementApercu: false,
        apercuDisponible: false,
        erreurApercu: null,
        apercuData: {
            nombre_epreuves: 0,
            nombre_deliberations: 0,
            nombre_candidats: 0,
            nombre_documents_estimes: 0,
            avertissements: []
        },
        reinitialiserApercu() {
            this.apercuDisponible = false;
            this.erreurApercu = null;
        },
        calculerApercu() {
            if (this.types.length === 0) return;
            this.chargementApercu = true;
            this.erreurApercu = null;

            fetch('{{ route('admin.evaluations.documents-batch.previsualiser') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    session_id: this.sessionId || null,
                    groupe_id: this.groupeId || null,
                    types: this.types
                })
            })
            .then(res => {
                if (!res.ok) throw new Error('Erreur de communication serveur');
                return res.json();
            })
            .then(data => {
                this.apercuData = data;
                this.apercuDisponible = true;
            })
            .catch(err => {
                this.erreurApercu = 'Échec de prévisualisation : impossible de calculer le volume documentaire pour ces critères.';
            })
            .finally(() => {
                this.chargementApercu = false;
            });
        }
    };
}
</script>
@endsection
