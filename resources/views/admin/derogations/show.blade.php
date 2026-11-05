@extends('layouts.app')

@section('title', 'Instruction Dérogations — ' . $etudiant->nom . ' ' . $etudiant->prenom)

@section('content')
<div class="mx-auto max-w-6xl px-4 sm:px-6 py-8 space-y-6">

    <div class="relative overflow-hidden rounded-3xl border border-slate-200/90 bg-white p-6 sm:p-8 shadow-sm">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <div class="flex flex-wrap items-center gap-2 mb-2">
                    <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold border border-blue-200 bg-blue-50 text-blue-900">
                        <span class="h-2 w-2 rounded-full bg-blue-500" aria-hidden="true"></span>
                        Instruction Dossier Dérogations (LOOP 146)
                    </span>
                    <span class="font-mono text-xs font-bold text-slate-500">
                        CIN : {{ $etudiant->cin }} · Faculté : {{ $etudiant->faculte?->nom ?? '—' }} ({{ $etudiant->niveau ?? 'L1' }})
                    </span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-black tracking-tight text-slate-900">
                    {{ $etudiant->nom }} {{ $etudiant->prenom }}
                </h1>
                <p class="mt-1 text-xs sm:text-sm text-slate-500">
                    Registre des actes d'instruction, constat d'échéance immuable, voies de recours et traçabilité zéro-PII.
                </p>
            </div>

            <div class="flex items-center gap-2">
                <a href="{{ route('admin.derogations.index') }}"
                   class="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2 text-xs font-bold text-slate-700 shadow-sm hover:bg-slate-50 transition-smooth min-h-[44px]">
                    ← Liste des dossiers
                </a>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-bold text-emerald-900">
            ✓ {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm font-bold text-rose-900">
            ⚠️ {{ $errors->first() }}
        </div>
    @endif

    {{-- Synthèse des demandes existantes --}}
    <section class="rounded-3xl border border-slate-200/90 bg-white p-6 sm:p-8 shadow-sm space-y-4" aria-labelledby="titre-demandes">
        <div class="flex items-center justify-between border-b border-slate-100 pb-4">
            <div>
                <h2 id="titre-demandes" class="text-lg font-black text-slate-900">Demandes de dérogation consignées</h2>
                <p class="text-xs text-slate-500">Historique chronologique scellé dans l'audit académique (zéro suppression, zéro écrasement).</p>
            </div>
            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-700">
                {{ count($demandes) }} demande(s)
            </span>
        </div>

        @forelse($demandes as $demande)
            <div class="rounded-2xl border border-slate-200 p-5 space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 border-b border-slate-100 pb-3">
                    <div class="flex items-center gap-3">
                        <span class="font-mono text-xs font-black text-slate-900">Réf : {{ $demande['demande_id'] }}</span>
                        @if($demande['statut'] === 'accordee')
                            <span class="inline-flex items-center rounded-full bg-emerald-100 text-emerald-800 text-xs px-2.5 py-0.5 font-bold border border-emerald-200">
                                ✓ Accordée
                            </span>
                        @elseif($demande['statut'] === 'refuse')
                            <span class="inline-flex items-center rounded-full bg-rose-100 text-rose-800 text-xs px-2.5 py-0.5 font-bold border border-rose-200">
                                ❌ Refusée
                            </span>
                        @elseif($demande['statut'] === 'ambigu')
                            <span class="inline-flex items-center rounded-full bg-sky-100 text-sky-800 text-xs px-2.5 py-0.5 font-bold border border-sky-200">
                                ❓ Décision ambiguë (en attente de clarification)
                            </span>
                        @elseif(!empty($demande['conteste']) || $demande['statut'] === 'conteste')
                            <span class="inline-flex items-center rounded-full bg-purple-100 text-purple-800 text-xs px-2.5 py-0.5 font-bold border border-purple-200">
                                ⚖️ Attente contestée
                            </span>
                        @else
                            <span class="inline-flex items-center rounded-full bg-amber-100 text-amber-800 text-xs px-2.5 py-0.5 font-bold border border-amber-200">
                                ⏳ En attente d’établissement de compétence
                            </span>
                        @endif
                    </div>
                    <span class="text-xs text-slate-400 font-mono">
                        Date dépôt : {{ \Carbon\Carbon::parse($demande['date_depot'])->format('d/m/Y à H:i') }}
                    </span>
                </div>

                @if(!empty($demande['motif']))
                    <div class="rounded-xl bg-slate-50 p-3 text-xs text-slate-700">
                        <span class="font-bold text-slate-900">Motif initial :</span> {{ $demande['motif'] }}
                    </div>
                @endif

                @if(!empty($demande['evenements']))
                    <div class="space-y-2">
                        <h3 class="text-xs font-black uppercase tracking-wider text-slate-400">Événements d'instruction</h3>
                        <ul class="divide-y divide-slate-100 rounded-xl border border-slate-100 bg-slate-50/50 p-3 text-xs space-y-1">
                            @foreach($demande['evenements'] as $ev)
                                <li class="pt-1.5 first:pt-0 flex items-start justify-between gap-3">
                                    <span class="font-medium text-slate-700 font-mono">
                                        {{ $ev['action'] }}
                                    </span>
                                    <span class="text-[11px] text-slate-400 font-mono">
                                        {{ \Carbon\Carbon::parse($ev['date'])->format('d/m/Y H:i') }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @empty
            <div class="rounded-2xl border border-dashed border-slate-200 p-6 text-center text-xs text-slate-500">
                Aucune demande de dérogation enregistrée pour cet étudiant.
            </div>
        @endforelse
    </section>

    {{-- Synthèse des dépassements d'échéances --}}
    @if(count($depassements) > 0)
        <section class="rounded-3xl border border-slate-200/90 bg-white p-6 sm:p-8 shadow-sm space-y-4" aria-labelledby="titre-constats">
            <div class="border-b border-slate-100 pb-4">
                <h2 id="titre-constats" class="text-lg font-black text-slate-900">Constats de dépassement d'échéance (Q135, Q136)</h2>
                <p class="text-xs text-slate-500">Règle HIST-007 : L'échéance initiale est immuable. Le dépassement est distinct de ses conséquences.</p>
            </div>
            <div class="space-y-3">
                @foreach($depassements as $depassement)
                    <div class="rounded-2xl border border-amber-200 bg-amber-50/50 p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                        <div>
                            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-bold bg-amber-100 text-amber-800">
                                ⚠️ Dépassement constaté
                            </span>
                            <span class="ml-2 text-xs font-bold text-slate-800">{{ $depassement['code_echeance'] }}</span>
                            <p class="mt-1 text-xs text-slate-600">
                                Échéance initiale : <strong class="font-mono">{{ $depassement['date_echeance'] ?? '—' }}</strong> · Constaté le {{ \Carbon\Carbon::parse($depassement['date_constat'])->format('d/m/Y') }}
                            </p>
                        </div>
                        <span class="text-[11px] font-bold text-slate-500 font-mono">Date scellée</span>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Outils d'instruction administrative collégiale (Q135 à Q146) --}}
    <section class="rounded-3xl border border-slate-200/90 bg-white p-6 sm:p-8 shadow-sm space-y-6" aria-labelledby="titre-outils">
        <div class="border-b border-slate-100 pb-4">
            <h2 id="titre-outils" class="text-lg font-black text-slate-900">Actes d’instruction administrative (LOOP 146)</h2>
            <p class="text-xs text-slate-500">Chaque acte est consigné sans mutation automatique de dossier et sans décision implicite déduite du silence.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

            {{-- 1. Constat de dépassement (Q135, Q136) --}}
            <div class="rounded-2xl border border-slate-200 p-5 space-y-3 bg-slate-50/50">
                <h3 class="text-xs font-black text-slate-900 uppercase tracking-wider">
                    1. Constater un dépassement d’échéance (Q135, Q136)
                </h3>
                <form method="POST" action="{{ route('admin.derogations.constater-depassement', $etudiant) }}" class="space-y-3">
                    @csrf
                    <div>
                        <label class="block text-xs font-bold text-slate-700">Type d'échéance</label>
                        <input type="text" name="type_echeance" required placeholder="Ex. depot_justificatif_bourse"
                               class="mt-1 w-full rounded-xl border border-slate-300 p-2 text-xs bg-white">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700">Échéance initiale</label>
                        <input type="datetime-local" name="echeance_initiale" required
                               class="mt-1 w-full rounded-xl border border-slate-300 p-2 text-xs bg-white">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700">Référence réglementaire (optionnelle)</label>
                        <input type="text" name="reference_reglementaire" placeholder="Ex. Reglement-Etudes-Art-22"
                               class="mt-1 w-full rounded-xl border border-slate-300 p-2 text-xs bg-white">
                    </div>
                    <button type="submit" class="w-full rounded-xl bg-slate-900 py-2 text-xs font-bold text-white hover:bg-slate-800 transition-smooth">
                        Consigner le constat d’échéance
                    </button>
                </form>
            </div>

            {{-- 2. Statuer officiellement (Q138) --}}
            <div class="rounded-2xl border border-slate-200 p-5 space-y-3 bg-slate-50/50">
                <h3 class="text-xs font-black text-slate-900 uppercase tracking-wider">
                    2. Statuer officiellement sur une dérogation (Q138)
                </h3>
                <form method="POST" action="{{ route('admin.derogations.statuer', $etudiant) }}" class="space-y-3">
                    @csrf
                    <div>
                        <label class="block text-xs font-bold text-slate-700">Réf. Demande</label>
                        <input type="text" name="demande_id" required placeholder="Ex. DEROG-2026-001"
                               class="mt-1 w-full rounded-xl border border-slate-300 p-2 text-xs bg-white">
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block text-xs font-bold text-slate-700">Décision</label>
                            <select name="decision" required class="mt-1 w-full rounded-xl border border-slate-300 p-2 text-xs bg-white">
                                <option value="accordee">Accordée</option>
                                <option value="refusee">Refusée</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700">Qualité autorité</label>
                            <input type="text" name="autorite_qualite" required placeholder="Ex. Doyen"
                                   class="mt-1 w-full rounded-xl border border-slate-300 p-2 text-xs bg-white">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700">Référence de l’acte officiel</label>
                        <input type="text" name="reference_acte_officiel" required placeholder="Ex. DEC-RECTORAT-2026-44"
                               class="mt-1 w-full rounded-xl border border-slate-300 p-2 text-xs bg-white">
                    </div>
                    <button type="submit" class="w-full rounded-xl bg-blue-600 py-2 text-xs font-bold text-white hover:bg-blue-700 transition-smooth">
                        Enregistrer la décision officielle
                    </button>
                </form>
            </div>

            {{-- 3. Relance institutionnelle (Q139) --}}
            <div class="rounded-2xl border border-slate-200 p-5 space-y-3 bg-slate-50/50">
                <h3 class="text-xs font-black text-slate-900 uppercase tracking-wider">
                    3. Relance institutionnelle (Q139)
                </h3>
                <form method="POST" action="{{ route('admin.derogations.relancer', $etudiant) }}" class="space-y-3">
                    @csrf
                    <div>
                        <label class="block text-xs font-bold text-slate-700">Réf. Demande</label>
                        <input type="text" name="demande_id" required placeholder="Ex. DEROG-2026-001"
                               class="mt-1 w-full rounded-xl border border-slate-300 p-2 text-xs bg-white">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700">Canal de relance officiel</label>
                        <input type="text" name="canal_relance" required placeholder="Ex. Courrier_Officiel_Rectorat"
                               class="mt-1 w-full rounded-xl border border-slate-300 p-2 text-xs bg-white">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700">Réf. preuve relance (optionnelle)</label>
                        <input type="text" name="reference_preuve_relance" placeholder="Ex. REL-2026-09"
                               class="mt-1 w-full rounded-xl border border-slate-300 p-2 text-xs bg-white">
                    </div>
                    <button type="submit" class="w-full rounded-xl bg-slate-800 py-2 text-xs font-bold text-white hover:bg-slate-700 transition-smooth">
                        Tracer la relance institutionnelle
                    </button>
                </form>
            </div>

            {{-- 4. Information attente prolongée (Q141) --}}
            <div class="rounded-2xl border border-slate-200 p-5 space-y-3 bg-slate-50/50">
                <h3 class="text-xs font-black text-slate-900 uppercase tracking-wider">
                    4. Information attente prolongée (Q141)
                </h3>
                <form method="POST" action="{{ route('admin.derogations.informer-attente', $etudiant) }}" class="space-y-3">
                    @csrf
                    <div>
                        <label class="block text-xs font-bold text-slate-700">Réf. Demande</label>
                        <input type="text" name="demande_id" required placeholder="Ex. DEROG-2026-001"
                               class="mt-1 w-full rounded-xl border border-slate-300 p-2 text-xs bg-white">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700">Démarches entreprises (une par ligne)</label>
                        <textarea name="demarches" rows="2" required placeholder="Transmission au secrétariat académique&#10;Relance de l'avis pédagogique"
                                  class="mt-1 w-full rounded-xl border border-slate-300 p-2 text-xs bg-white"></textarea>
                    </div>
                    <button type="submit" class="w-full rounded-xl bg-slate-800 py-2 text-xs font-bold text-white hover:bg-slate-700 transition-smooth">
                        Notifier l'information factuelle
                    </button>
                </form>
            </div>

            {{-- 5. Transmission réexamen (Q143) --}}
            <div class="rounded-2xl border border-slate-200 p-5 space-y-3 bg-slate-50/50">
                <h3 class="text-xs font-black text-slate-900 uppercase tracking-wider">
                    5. Transmission réexamen officiel (Q143)
                </h3>
                <form method="POST" action="{{ route('admin.derogations.transmettre-reexamen', $etudiant) }}" class="space-y-3">
                    @csrf
                    <div>
                        <label class="block text-xs font-bold text-slate-700">Réf. Demande</label>
                        <input type="text" name="demande_id" required placeholder="Ex. DEROG-2026-001"
                               class="mt-1 w-full rounded-xl border border-slate-300 p-2 text-xs bg-white">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700">Voie de transmission officielle</label>
                        <input type="text" name="voie_transmission" required placeholder="Ex. Commission_Recours_Facultaire"
                               class="mt-1 w-full rounded-xl border border-slate-300 p-2 text-xs bg-white">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700">Référence transmission</label>
                        <input type="text" name="reference_transmission" required placeholder="Ex. TRX-2026-001"
                               class="mt-1 w-full rounded-xl border border-slate-300 p-2 text-xs bg-white">
                    </div>
                    <button type="submit" class="w-full rounded-xl bg-slate-800 py-2 text-xs font-bold text-white hover:bg-slate-700 transition-smooth">
                        Enregistrer la transmission
                    </button>
                </form>
            </div>

            {{-- 6. Décision ambiguë (Q145) & Clarification (Q146) --}}
            <div class="rounded-2xl border border-slate-200 p-5 space-y-3 bg-slate-50/50">
                <h3 class="text-xs font-black text-slate-900 uppercase tracking-wider">
                    6. Décision ambiguë (Q145) ou Clarification (Q146)
                </h3>
                <details class="text-xs">
                    <summary class="font-bold text-blue-700 cursor-pointer">Enregistrer décision ambiguë (Q145)</summary>
                    <form method="POST" action="{{ route('admin.derogations.decision-ambigue', $etudiant) }}" class="mt-2 space-y-2">
                        @csrf
                        <input type="text" name="demande_id" required placeholder="Réf. Demande" class="w-full rounded-lg border border-slate-300 p-2 text-xs bg-white">
                        <textarea name="contenu_decision" rows="2" required placeholder="Contenu intégral de la décision ambiguë reçue" class="w-full rounded-lg border border-slate-300 p-2 text-xs bg-white"></textarea>
                        <input type="text" name="reference_acte" placeholder="Réf. acte (optionnelle)" class="w-full rounded-lg border border-slate-300 p-2 text-xs bg-white">
                        <button type="submit" class="w-full rounded-lg bg-sky-700 py-1.5 text-xs font-bold text-white">Figer comme ambiguë</button>
                    </form>
                </details>

                <details class="text-xs pt-2">
                    <summary class="font-bold text-purple-700 cursor-pointer">Appliquer clarification officielle (Q146)</summary>
                    <form method="POST" action="{{ route('admin.derogations.appliquer-clarification', $etudiant) }}" class="mt-2 space-y-2">
                        @csrf
                        <input type="text" name="demande_id" required placeholder="Réf. Demande" class="w-full rounded-lg border border-slate-300 p-2 text-xs bg-white">
                        <select name="issue_clarifiee" required class="w-full rounded-lg border border-slate-300 p-2 text-xs bg-white">
                            <option value="refus">Refus confirmé</option>
                            <option value="accord">Accord confirmé</option>
                        </select>
                        <input type="text" name="reference_clarification" required placeholder="Réf. clarification (ex. CLAR-2026-01)" class="w-full rounded-lg border border-slate-300 p-2 text-xs bg-white">
                        <input type="text" name="auteur_clarification" placeholder="Auteur officiel (ex. Recteur)" class="w-full rounded-lg border border-slate-300 p-2 text-xs bg-white">
                        <button type="submit" class="w-full rounded-lg bg-purple-700 py-1.5 text-xs font-bold text-white">Sceller la clarification</button>
                    </form>
                </details>
            </div>

        </div>
    </section>

</div>
@endsection

