@extends('layouts.app')

@section('title', 'Dérogations et Échéances — Espace Étudiant')
@section('meta_description', 'Consultez l’état factuel de vos demandes de dérogation, le calendrier officiel de vos échéances et les voies de recours académiques.')

@section('content')
<div class="mx-auto max-w-5xl px-4 sm:px-6 py-6 sm:py-8 space-y-6">

    <div class="relative overflow-hidden rounded-3xl border border-slate-200/90 bg-white p-6 sm:p-8 shadow-sm">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <div class="flex flex-wrap items-center gap-2 mb-2">
                    <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold border border-blue-200 bg-blue-50 text-blue-900">
                        <span class="h-2 w-2 rounded-full bg-blue-500" aria-hidden="true"></span>
                        Gouvernance & Échéances (LOOP 146)
                    </span>
                    <span class="font-mono text-xs font-bold text-slate-500">
                        Étudiant : {{ $etudiant->nom }} {{ $etudiant->prenom }} (CIN : {{ $etudiant->cin }})
                    </span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-black tracking-tight text-slate-900">
                    Dérogations et Échéances Académiques
                </h1>
                <p class="mt-1 text-xs sm:text-sm text-slate-500">
                    Information factuelle et transparente sur vos demandes, le suivi des délais officiels et les voies de recours institutionnelles.
                </p>
            </div>

            <div>
                <a href="{{ route('etudiant.dashboard') }}"
                   class="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2 text-xs font-bold text-slate-700 shadow-sm hover:bg-slate-50 transition-smooth min-h-[44px]">
                    ← Retour au tableau de bord
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

    {{-- Formulaire de dépôt de dérogation (Q137) --}}
    <details class="rounded-3xl border border-slate-200/90 bg-white p-6 shadow-sm">
        <summary class="cursor-pointer text-sm font-black text-slate-900 hover:text-blue-600 flex items-center justify-between">
            <span>➕ Déposer une nouvelle demande de dérogation administrative (Q137)</span>
            <span class="text-xs font-normal text-slate-400">Cliquez pour déplier</span>
        </summary>
        <form method="POST" action="{{ route('etudiant.derogations.deposer') }}" enctype="multipart/form-data" class="mt-4 space-y-4">
            @csrf
            <div>
                <label for="objet_derogation" class="block text-xs font-bold text-slate-700">Objet de la demande</label>
                <input type="text"
                       id="objet_derogation"
                       name="objet"
                       required
                       minlength="3"
                       maxlength="255"
                       placeholder="Ex. Demande de dérogation pour inscription tardive"
                       class="mt-1 w-full rounded-xl border border-slate-300 p-3 text-xs focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
            </div>
            <div>
                <label for="motif_derogation" class="block text-xs font-bold text-slate-700">Motif circonstancié (10 caractères minimum)</label>
                <textarea id="motif_derogation"
                          name="motif"
                          rows="3"
                          required
                          minlength="10"
                          maxlength="2000"
                          placeholder="Décrivez avec précision les faits et circonstances justifiant votre demande..."
                          class="mt-1 w-full rounded-xl border border-slate-300 p-3 text-xs focus:border-blue-500 focus:ring-1 focus:ring-blue-500"></textarea>
            </div>
            <div>
                <label for="piece_justificative" class="block text-xs font-bold text-slate-700">Pièce justificative (optionnelle, PDF ou image max 5 Mo)</label>
                <input type="file"
                       id="piece_justificative"
                       name="piece_justificative"
                       accept=".pdf,.jpg,.jpeg,.png"
                       class="mt-1 w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
            </div>
            <div class="flex items-center justify-between gap-4 pt-2">
                <p class="text-[11px] text-slate-500">
                    Règle EXC-001 : Votre demande est consignée avec empreinte probatoire. Elle ne produit aucun effet automatique ni forclusion sans décision formelle d’une autorité compétente.
                </p>
                <button type="submit"
                        class="rounded-xl bg-blue-600 px-5 py-2.5 text-xs font-bold text-white shadow-sm hover:bg-blue-700 transition-smooth whitespace-nowrap min-h-[40px]">
                    Soumettre la demande
                </button>
            </div>
        </form>
    </details>

    {{-- Section 1 : Demandes de dérogation --}}
    <section class="rounded-3xl border border-slate-200/90 bg-white p-6 sm:p-8 shadow-sm space-y-4" aria-labelledby="titre-demandes">
        <div class="flex items-center justify-between border-b border-slate-100 pb-4">
            <div>
                <h2 id="titre-demandes" class="text-lg font-black text-slate-900">Demandes de dérogation enregistrées</h2>
                <p class="text-xs text-slate-500">Suivi rigoureux et transparent de chaque demande sans présomption d'issue ni délai inventé.</p>
            </div>
            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-700">
                {{ count($demandes) }} dossier(s)
            </span>
        </div>

        @if(count($demandes) > 0)
            <div class="space-y-4">
                @foreach($demandes as $demande)
                    <div class="rounded-2xl border border-slate-200 p-5 space-y-4 hover:border-slate-300 transition-smooth">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 border-b border-slate-100 pb-3">
                            <div class="flex items-center gap-3">
                                <span class="font-mono text-xs font-black text-slate-900">Réf : {{ $demande['demande_id'] }}</span>
                                @if($demande['statut'] === 'accordee')
                                    <span class="inline-flex items-center rounded-full bg-emerald-100 text-emerald-800 text-xs px-2.5 py-0.5 font-bold border border-emerald-200">
                                        ✓ Dérogation accordée
                                    </span>
                                @elseif($demande['statut'] === 'refuse')
                                    <span class="inline-flex items-center rounded-full bg-rose-100 text-rose-800 text-xs px-2.5 py-0.5 font-bold border border-rose-200">
                                        ❌ Dérogation refusée
                                    </span>
                                @elseif($demande['statut'] === 'ambigu')
                                    <span class="inline-flex items-center rounded-full bg-sky-100 text-sky-800 text-xs px-2.5 py-0.5 font-bold border border-sky-200">
                                        ❓ Décision ambiguë reçue (en attente de clarification)
                                    </span>
                                @elseif(!empty($demande['conteste']) || $demande['statut'] === 'conteste')
                                    <span class="inline-flex items-center rounded-full bg-purple-100 text-purple-800 text-xs px-2.5 py-0.5 font-bold border border-purple-200">
                                        ⚖️ Attente contestée
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-amber-100 text-amber-800 text-xs px-2.5 py-0.5 font-bold border border-amber-200">
                                        ⏳ En cours d’instruction (attente de décision officielle)
                                    </span>
                                @endif
                            </div>
                            <span class="text-xs text-slate-400">
                                Déposée le {{ \Carbon\Carbon::parse($demande['date_depot'])->format('d/m/Y à H:i') }}
                            </span>
                        </div>

                        @if(!empty($demande['motif']))
                            <div class="rounded-xl bg-slate-50 p-3 text-xs text-slate-700">
                                <span class="font-bold text-slate-900">Motif circonstancié :</span> {{ $demande['motif'] }}
                            </div>
                        @endif

                        {{-- Chronologie des événements --}}
                        @if(!empty($demande['evenements']))
                            <div class="space-y-2">
                                <h3 class="text-xs font-black uppercase tracking-wider text-slate-400">Historique d'instruction</h3>
                                <ul class="divide-y divide-slate-100 rounded-xl border border-slate-100 bg-slate-50/50 p-3 text-xs space-y-2">
                                    @foreach($demande['evenements'] as $ev)
                                        <li class="pt-2 first:pt-0 flex items-start justify-between gap-3">
                                            <span class="font-medium text-slate-700">
                                                {{ match($ev['action']) {
                                                    'demande_derogation_deposee' => 'Dépôt formel de la demande de dérogation',
                                                    'relance_demande_derogation' => 'Relance institutionnelle effectuée',
                                                    'information_attente_prolongee' => 'Information factuelle transmise sur l’attente',
                                                    'contestation_attente_prolongee' => 'Contestation d’attente prolongée enregistrée',
                                                    'transmission_reexamen' => 'Transmission formelle pour réexamen officiel',
                                                    'decision_insuffisamment_motivee' => 'Décision verbatim enregistrée (motivation incomplète constatée)',
                                                    'decision_ambigue_enregistree' => 'Réception d’une décision ambiguë (issue non présumée)',
                                                    'clarification_decision_appliquee' => 'Clarification officielle de refus appliquée',
                                                    'statut_derogation_enregistre' => 'Décision officielle de dérogation consignée',
                                                    default => $ev['action'],
                                                } }}
                                            </span>
                                            <span class="text-[11px] text-slate-400 font-mono">
                                                {{ \Carbon\Carbon::parse($ev['date'])->format('d/m/Y H:i') }}
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        {{-- Formulaire de contestation d'attente prolongée (Q142) --}}
                        @if(in_array($demande['statut'], ['en_attente', 'conteste', 'ambigu'], true))
                            <details class="rounded-xl border border-amber-200 bg-amber-50/50 p-4">
                                <summary class="cursor-pointer text-xs font-bold text-amber-900 hover:text-amber-950">
                                    ⚖️ Déposer ou renouveler une contestation d’attente prolongée (Q142)
                                </summary>
                                <form method="POST" action="{{ route('etudiant.derogations.contester-attente') }}" class="mt-4 space-y-3">
                                    @csrf
                                    <input type="hidden" name="demande_id" value="{{ $demande['demande_id'] }}">
                                    <div>
                                        <label for="motif-{{ $demande['demande_id'] }}" class="block text-xs font-bold text-slate-700">
                                            Motif circonstancié de la contestation (10 caractères minimum)
                                        </label>
                                        <textarea id="motif-{{ $demande['demande_id'] }}"
                                                  name="motif_contestation"
                                                  rows="3"
                                                  required
                                                  minlength="10"
                                                  maxlength="2000"
                                                  placeholder="Indiquez avec précision les éléments justifiant votre contestation relative aux délais d'instruction..."
                                                  class="mt-1 w-full rounded-xl border border-slate-300 p-3 text-xs focus:border-amber-500 focus:ring-1 focus:ring-amber-500"></textarea>
                                    </div>
                                    <div class="flex items-center justify-between gap-4">
                                        <p class="text-[11px] text-slate-500">
                                            Règle RECOURS-005 : L’enregistrement de la contestation consigne vos arguments sans modifier automatiquement votre dossier ni créer de droit fictif.
                                        </p>
                                        <button type="submit"
                                                class="rounded-xl bg-amber-600 px-4 py-2 text-xs font-bold text-white shadow-sm hover:bg-amber-700 transition-smooth whitespace-nowrap min-h-[38px]">
                                            Enregistrer la contestation
                                        </button>
                                    </div>
                                </form>
                            </details>
                        @endif
                    </div>
                @endforeach
            </div>
        @else
            <div class="rounded-2xl border border-dashed border-slate-200 p-8 text-center text-xs text-slate-500">
                Aucune demande de dérogation n'a été enregistrée pour votre dossier.
            </div>
        @endif
    </section>

    {{-- Section 2 : Constats de dépassement d'échéances (Q135, Q136) --}}
    @if(count($depassements) > 0)
        <section class="rounded-3xl border border-slate-200/90 bg-white p-6 sm:p-8 shadow-sm space-y-4" aria-labelledby="titre-depassements">
            <div class="border-b border-slate-100 pb-4">
                <h2 id="titre-depassements" class="text-lg font-black text-slate-900">Constats de dépassement d’échéance</h2>
                <p class="text-xs text-slate-500">Règle HIST-007 & DELAI-002 : L’échéance initiale est strictement préservée sans forclusion arbitraire ni modification unilatérale.</p>
            </div>
            <div class="space-y-3">
                @foreach($depassements as $depassement)
                    <div class="rounded-2xl border border-amber-200 bg-amber-50/40 p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                        <div>
                            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-bold bg-amber-100 text-amber-800">
                                ⚠️ Dépassement constaté
                            </span>
                            <span class="ml-2 text-xs font-bold text-slate-800">Code échéance : {{ $depassement['code_echeance'] }}</span>
                            <p class="mt-1 text-xs text-slate-600">
                                Date cible initiale : <strong class="font-mono">{{ $depassement['date_echeance'] ?? 'Non fixée' }}</strong> · Signalement constaté le {{ \Carbon\Carbon::parse($depassement['date_constat'])->format('d/m/Y') }}
                            </p>
                        </div>
                        <span class="text-[11px] font-bold text-slate-500">Échéance initiale protégée</span>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Section 3 : Voies de recours officielles (Q142, Q143) --}}
    <section class="rounded-3xl border border-slate-200/90 bg-white p-6 sm:p-8 shadow-sm space-y-3" aria-labelledby="titre-recours">
        <h2 id="titre-recours" class="text-base font-black text-slate-900">Voies officielles de recours académiques</h2>
        <p class="text-xs text-slate-600 leading-relaxed">
            Conformément aux règlements universitaires et aux décisions de gouvernance, toute demande de contestation ou de réexamen doit être introduite exclusivement auprès des instances légalement instituées :
        </p>
        <ul class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2">
            @forelse($voiesRecours as $voie)
                <li class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4 flex items-center gap-3">
                    <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-primary-100 text-primary-800 font-bold text-xs">
                        🏛️
                    </span>
                    <span class="text-xs font-bold text-slate-800">
                        {{ str_replace('_', ' ', $voie) }}
                    </span>
                </li>
            @empty
                <li class="col-span-2 rounded-2xl border border-amber-200 bg-amber-50/60 p-4 text-xs text-amber-900">
                    Aucune voie de recours dérogatoire n’est actuellement publiée dans le référentiel officiel. Toute contestation déposée est conservée pour transmission dès publication des arrêtés de compétence.
                </li>
            @endforelse
        </ul>
        <p class="text-[11px] text-slate-400 pt-2">
            Aucun délai garanti de traitement ne peut être fixé arbitrairement par le système informatique en l'absence de décret ou arrêté rectoral exprès.
        </p>
    </section>

</div>
@endsection
