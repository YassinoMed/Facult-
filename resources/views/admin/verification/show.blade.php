@extends('layouts.app')

@section('title', 'Vérification du dossier — ' . $etudiant->nom_complet)

@section('content')
<div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
    <x-page-header
        :titre="'Vérification intelligente — ' . $etudiant->nom_complet"
        :description="'Matricule : ' . ($etudiant->num_inscription ?? '—') . ' · ' . ($etudiant->faculte?->nom ?? 'Faculté non assignée')"
        :fil="[
            'Étudiants' => route('admin.etudiants.index'),
            $etudiant->nom_complet => route('admin.etudiants.show', $etudiant),
            'Vérification' => '#'
        ]">
        <x-slot:actions>
            <div class="flex items-center gap-2">
                <a href="{{ route('admin.etudiants.show', $etudiant) }}" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 transition-smooth">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    Fiche 360°
                </a>
                <form method="post" action="{{ route('admin.verification.verifier', $etudiant) }}" class="inline">
                    @csrf
                    <button type="submit" class="inline-flex items-center gap-1.5 rounded-xl bg-primary-600 px-4 py-2 text-xs font-bold text-white shadow-xs hover:bg-primary-700 transition-smooth">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        Relancer l'analyse
                    </button>
                </form>
            </div>
        </x-slot:actions>
    </x-page-header>

    @if(session('status'))
        <div class="mt-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900 shadow-xs">
            {{ session('status') }}
        </div>
    @endif

    {{-- ── Tableau de bord de vérification ── --}}
    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {{-- Verdict principal --}}
        <div class="rounded-2xl border p-5 shadow-xs transition-smooth {{ match($resultat->verdict) {
            'CONFORME' => 'border-emerald-200 bg-gradient-to-br from-emerald-50/80 to-white text-emerald-900',
            'BLOQUANT' => 'border-rose-200 bg-gradient-to-br from-rose-50/80 to-white text-rose-900',
            default => 'border-amber-200 bg-gradient-to-br from-amber-50/80 to-white text-amber-900',
        } }}">
            <p class="text-[11px] font-black uppercase tracking-wider text-slate-500">Verdict global</p>
            <div class="mt-2 flex items-center gap-2">
                @if($resultat->verdict === 'CONFORME')
                    <span class="inline-flex h-3 w-3 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span class="text-xl font-black text-emerald-700">CONFORME</span>
                @elseif($resultat->verdict === 'BLOQUANT')
                    <span class="inline-flex h-3 w-3 rounded-full bg-rose-500 animate-pulse"></span>
                    <span class="text-xl font-black text-rose-700">BLOQUANT</span>
                @else
                    <span class="inline-flex h-3 w-3 rounded-full bg-amber-500 animate-pulse"></span>
                    <span class="text-xl font-black text-amber-700">À VÉRIFIER</span>
                @endif
            </div>
            <p class="mt-1 text-xs text-slate-500">
                {{ match($resultat->verdict) {
                    'CONFORME' => 'Dossier validé par toutes les règles actives.',
                    'BLOQUANT' => 'Blocage administratif ou académique critique.',
                    default => 'Anomalies non bloquantes nécessitant révision.',
                } }}
            </p>
        </div>

        {{-- Score de conformité --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-xs">
            <p class="text-[11px] font-black uppercase tracking-wider text-slate-500">Score de conformité</p>
            <div class="mt-2 flex items-baseline gap-2">
                <span class="text-3xl font-black tabular-nums {{ $resultat->score >= 80 ? 'text-emerald-600' : ($resultat->score >= 50 ? 'text-amber-600' : 'text-rose-600') }}">
                    {{ $resultat->score }}
                </span>
                <span class="text-sm font-bold text-slate-400">/ 100</span>
            </div>
            <div class="mt-2 h-1.5 w-full rounded-full bg-slate-100 overflow-hidden">
                <div class="h-full rounded-full transition-all duration-500 {{ $resultat->score >= 80 ? 'bg-emerald-500' : ($resultat->score >= 50 ? 'bg-amber-500' : 'bg-rose-500') }}"
                     style="width: {{ $resultat->score }}%"></div>
            </div>
        </div>

        {{-- Règles exécutées --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-xs">
            <p class="text-[11px] font-black uppercase tracking-wider text-slate-500">Règles de contrôle</p>
            <p class="mt-2 text-2xl font-black tabular-nums text-slate-900">
                {{ $resultat->rulesPassed }} <span class="text-sm font-semibold text-slate-400">/ {{ $resultat->rulesChecked }} validées</span>
            </p>
            <p class="mt-1 text-xs text-slate-500">
                Temps d'exécution : <span class="font-bold tabular-nums">{{ $resultat->dureeMs }} ms</span>
            </p>
        </div>

        {{-- Anomalies relevées --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-xs">
            <p class="text-[11px] font-black uppercase tracking-wider text-slate-500">Anomalies détectées</p>
            <p class="mt-2 text-2xl font-black tabular-nums text-slate-900">
                {{ $resultat->nombreAnomalies() }}
            </p>
            <div class="mt-1 flex flex-wrap gap-2 text-[11px] font-bold">
                @if($resultat->nombreBloquants() > 0)
                    <span class="text-rose-600">{{ $resultat->nombreBloquants() }} bloquant(s)</span>
                @endif
                @if($resultat->nombreErreurs() > 0)
                    <span class="text-amber-600">{{ $resultat->nombreErreurs() }} erreur(s)</span>
                @endif
                @if($resultat->nombreAvertissements() > 0)
                    <span class="text-slate-500">{{ $resultat->nombreAvertissements() }} avertissement(s)</span>
                @endif
            </div>
        </div>
    </div>

    {{-- ── Liste détaillée des anomalies ── --}}
    <div class="mt-8">
        <h2 class="text-base font-black text-slate-900">Détail des anomalies & recommandations</h2>
        <p class="mt-0.5 text-xs text-slate-500">Chaque anomalie identifie la règle, la preuve technique, et une suggestion d'action administrative.</p>

        @if(empty($resultat->anomalies))
            <div class="mt-4 rounded-2xl border border-emerald-200 bg-emerald-50/60 p-8 text-center">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                </div>
                <h3 class="mt-3 text-sm font-black text-emerald-900">Dossier conforme et complet</h3>
                <p class="mt-1 text-xs text-emerald-700">Aucune incohérence, document manquant ni donnée orpheline détectée pour cet étudiant.</p>
            </div>
        @else
            <div class="mt-4 space-y-4">
                @foreach($resultat->anomalies as $index => $anomalie)
                    <div class="rounded-2xl border bg-white p-5 shadow-xs transition-smooth {{ match($anomalie->gravite) {
                        'bloquant' => 'border-rose-200 bg-rose-50/20',
                        'erreur' => 'border-amber-200 bg-amber-50/20',
                        default => 'border-slate-200',
                    } }}">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="flex items-center gap-2">
                                <span class="inline-flex items-center rounded-lg px-2.5 py-1 text-[11px] font-black uppercase tracking-wider {{ match($anomalie->gravite) {
                                    'bloquant' => 'bg-rose-100 text-rose-800',
                                    'erreur' => 'bg-amber-100 text-amber-800',
                                    'avertissement' => 'bg-slate-100 text-slate-800',
                                    default => 'bg-blue-100 text-blue-800',
                                } }}">
                                    {{ $anomalie->gravite }}
                                </span>
                                <span class="text-xs font-bold text-slate-400">·</span>
                                <span class="text-xs font-mono font-semibold text-slate-500">{{ $anomalie->regle }}</span>
                                <span class="text-xs font-bold text-slate-400">·</span>
                                <span class="rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600">{{ $anomalie->categorie }}</span>
                            </div>

                            @if($anomalie->champ && auth()->user()->can('archives.update'))
                                <button type="button"
                                        onclick="document.getElementById('modal-corriger-{{ $index }}').classList.remove('hidden')"
                                        class="inline-flex items-center gap-1 rounded-lg border border-primary-200 bg-primary-50 px-3 py-1.5 text-xs font-bold text-primary-700 hover:bg-primary-100 transition-smooth">
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                    Correction assistée
                                </button>
                            @endif
                        </div>

                        <p class="mt-2 text-sm font-black text-slate-900">{{ $anomalie->message }}</p>

                        <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2 rounded-xl bg-slate-50 p-3 text-xs">
                            <div>
                                <span class="font-bold text-slate-500 uppercase tracking-wider text-[10px]">Preuve / Justification :</span>
                                <p class="mt-0.5 text-slate-700">{{ $anomalie->justification }}</p>
                            </div>
                            <div>
                                <span class="font-bold text-slate-500 uppercase tracking-wider text-[10px]">Recommandation :</span>
                                <p class="mt-0.5 font-medium text-primary-700">{{ $anomalie->recommandation }}</p>
                            </div>
                        </div>

                        {{-- Modale de correction assistée --}}
                        @if($anomalie->champ && auth()->user()->can('archives.update'))
                            <div id="modal-corriger-{{ $index }}" class="fixed inset-0 z-50 hidden flex items-center justify-center bg-slate-900/40 backdrop-blur-xs p-4">
                                <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl border border-slate-100">
                                    <h3 class="text-base font-black text-slate-900">Correction assistée — {{ $anomalie->champ }}</h3>
                                    <p class="mt-1 text-xs text-slate-500">Toute modification administrative est transactionnelle et auditée (§15-16).</p>

                                    <form method="post" action="{{ route('admin.verification.corriger', $etudiant) }}" class="mt-4 space-y-3">
                                        @csrf
                                        <input type="hidden" name="champ" value="{{ $anomalie->champ }}">

                                        <div>
                                            <label class="block text-xs font-bold text-slate-700">Valeur actuelle</label>
                                            <input type="text" readonly value="{{ $etudiant->{$anomalie->champ} ?? '—' }}" class="mt-1 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500">
                                        </div>

                                        <div>
                                            <label class="block text-xs font-bold text-slate-700">Nouvelle valeur proposée <span class="text-rose-500">*</span></label>
                                            <input type="text" name="nouvelle_valeur" required value="{{ old('nouvelle_valeur') }}" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none">
                                            @error('nouvelle_valeur')<p class="mt-1 text-[11px] font-bold text-rose-600">{{ $message }}</p>@enderror
                                        </div>

                                        <div>
                                            <label class="block text-xs font-bold text-slate-700">Motif administratif de la correction <span class="text-rose-500">*</span></label>
                                            <textarea name="motif" required rows="2" placeholder="Ex. Mise en conformité d'après la pièce d'identité officielle..." class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none">{{ old('motif') }}</textarea>
                                            @error('motif')<p class="mt-1 text-[11px] font-bold text-rose-600">{{ $message }}</p>@enderror
                                        </div>

                                        <div class="mt-5 flex items-center justify-end gap-2">
                                            <button type="button" onclick="document.getElementById('modal-corriger-{{ $index }}').classList.add('hidden')" class="rounded-xl px-4 py-2 text-xs font-bold text-slate-600 hover:bg-slate-100">Annuler</button>
                                            <button type="submit" formaction="{{ route('admin.verification.proposer', $etudiant) }}" class="rounded-xl border border-primary-200 bg-primary-50 px-4 py-2 text-xs font-bold text-primary-700 hover:bg-primary-100">Proposer pour validation</button>
                                            <button type="submit" class="rounded-xl bg-primary-600 px-4 py-2 text-xs font-bold text-white hover:bg-primary-700 shadow-xs">Valider et ré-analyser</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- ── Corrections en cours (workflow validé §15) ── --}}
    @if(! empty($corrections) && $corrections->isNotEmpty())
        <div class="mt-8 rounded-2xl border border-slate-200 bg-white p-5 shadow-xs">
            <h3 class="text-xs font-black uppercase tracking-wider text-slate-500">Corrections proposées (validation requise)</h3>
            <ul class="mt-3 space-y-2 text-sm">
                @foreach($corrections as $correction)
                    <li class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-100 px-3 py-2">
                        <span><strong>#{{ $correction->id }} {{ $correction->estPourInscription() ? 'Correction d’inscription (niveau/programme/type)' : $correction->champ }}</strong>
                            <span class="text-slate-500">« {{ $correction->ancienne_valeur ?? '—' }} » → « {{ $correction->nouvelle_valeur ?? '—' }} »</span>
                            <span class="ml-1 rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-bold">{{ $correction->statut }}</span></span>
                        @if($correction->statut === 'proposee' && auth()->user()->can('archives.update'))
                            <form method="post" action="{{ route('admin.verification.decider', $correction) }}">
                                @csrf
                                <input type="hidden" name="decision" value="approuver">
                                <button class="rounded-lg bg-emerald-600 px-3 py-1 text-xs font-bold text-white">Approuver</button>
                            </form>
                        @endif
                        @if($correction->statut === 'approuvee' && auth()->user()->can('archives.update'))
                            <form method="post" action="{{ route('admin.verification.decider', $correction) }}">
                                @csrf
                                <input type="hidden" name="decision" value="appliquer">
                                <button class="rounded-lg bg-primary-600 px-3 py-1 text-xs font-bold text-white">Appliquer</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- ── Liste des règles exécutées ── --}}
    @if($derniereInscription !== null && $blocages !== null)
    {{-- ── Synthèse d'éligibilité (façade §6, lecture seule) ── --}}
    @if(!empty($syntheseEligibilite))
    <div class="mt-8 rounded-2xl border {{ $syntheseEligibilite['eligible'] ? 'border-emerald-200 bg-emerald-50/40' : 'border-rose-200 bg-rose-50/40' }} p-5 shadow-xs" aria-label="Synthèse d'éligibilité du dossier">
        <h3 class="text-xs font-black uppercase tracking-wider text-slate-500">
            Synthèse d'éligibilité — {{ $syntheseEligibilite['verdict'] }}
        </h3>

        <div class="mt-3 flex flex-wrap gap-2">
            @foreach($syntheseEligibilite['controles'] as $controle)
                <span class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-[11px] font-bold {{ $controle['statut'] === 'pass' ? 'border-emerald-200 bg-white text-emerald-700' : ($controle['statut'] === 'avertissement' ? 'border-amber-200 bg-white text-amber-700' : 'border-rose-300 bg-white text-rose-700') }}" title="{{ $controle['message'] }}">
                    {{ $controle['statut'] === 'pass' ? '✓' : ($controle['statut'] === 'avertissement' ? '⚠' : '⛔') }} {{ $controle['libelle'] }}
                </span>
            @endforeach
        </div>

        @if($syntheseEligibilite['actions_proposees'] !== [])
            <ul class="mt-3 space-y-1 text-sm text-slate-700">
                @foreach($syntheseEligibilite['actions_proposees'] as $action)
                    <li class="flex gap-2"><span aria-hidden="true">→</span><span>{{ $action }}</span></li>
                @endforeach
            </ul>
        @endif

        <p class="mt-3 text-xs text-slate-500">
            Évaluation en lecture seule (période, blocages, pièces) — la validation administrative reste une décision humaine, jamais automatique.
        </p>
    </div>
    @endif

    <div class="mt-8 rounded-2xl border border-slate-200 bg-white p-5 shadow-xs">
        <h3 class="text-xs font-black uppercase tracking-wider text-slate-500">
            Inscription {{ $derniereInscription->anneeUniversitaire?->libelle }} ({{ $derniereInscription->niveau }}, {{ $derniereInscription->statut }}) — {{ $blocages['verdict'] }}
        </h3>

        @forelse($blocages['items'] as $index => $blocage)
            <div class="mt-3 rounded-xl border px-4 py-3 text-sm {{ $blocage['gravite'] === 'bloquant' ? 'border-rose-200 bg-rose-50/60' : 'border-amber-200 bg-amber-50/60' }}" role="alert">
                <p class="font-bold">{{ $blocage['gravite'] === 'bloquant' ? '🔴' : '🟠' }} Blocage #{{ $index + 1 }} — {{ $blocage['code'] }}</p>
                <p class="mt-1 font-semibold">{{ $blocage['titre'] }}</p>
                <p class="mt-1 text-xs text-slate-600">Constat : {{ $blocage['constat'] }}</p>
                <p class="mt-1 text-xs text-slate-600">Source : {{ $blocage['source'] }} · Action : {{ $blocage['recommandation'] }}</p>
            </div>
        @empty
            <p class="mt-3 text-sm text-emerald-700">Aucun blocage : inscription validable.</p>
        @endforelse

        <form method="post" action="{{ route('admin.inscriptions.corriger', $derniereInscription) }}" class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
            @csrf
            <div>
                <label for="corr-programme" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Programme</label>
                <select name="programme_id" id="corr-programme" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    <option value="">— inchangé —</option>
                    @foreach($programmes as $programme)
                        <option value="{{ $programme->id }}">{{ $programme->nom }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="corr-niveau" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Niveau</label>
                <select name="niveau_id" id="corr-niveau" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    <option value="">— inchangé —</option>
                    @foreach($niveaux as $niveau)
                        <option value="{{ $niveau->id }}">{{ $niveau->code }} — {{ $niveau->nom }}</option>
                    @endforeach
                </select>
            </div>
            <div class="sm:col-span-2">
                <label for="corr-motif" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Motif *</label>
                <textarea name="motif" id="corr-motif" rows="2" required minlength="10" maxlength="500" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" placeholder="Justification officielle (ex. décision de délibération)"></textarea>
            </div>
            <div class="sm:col-span-2">
                <x-btn type="submit">Corriger puis re-vérifier</x-btn>
            </div>
        </form>
    </div>
    @endif

    <div class="mt-8 rounded-2xl border border-slate-200 bg-white p-5 shadow-xs">
        <h3 class="text-xs font-black uppercase tracking-wider text-slate-500">Règles exécutées ({{ count($resultat->reglesExecutees) }})</h3>
        <div class="mt-3 flex flex-wrap gap-2">
            @foreach($resultat->reglesExecutees as $code)
                @php
                    $aEchoue = in_array($code, array_map(fn($a) => $a->regle, $resultat->anomalies), true);
                @endphp
                <span class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs font-semibold {{ $aEchoue ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-slate-200 bg-slate-50 text-slate-700' }}">
                    <span>{{ $aEchoue ? '⚠' : '✓' }}</span>
                    <span>{{ $code }}</span>
                </span>
            @endforeach
        </div>
    </div>
</div>
@endsection
