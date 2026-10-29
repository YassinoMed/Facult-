@extends('layouts.app')

@section('title', 'Contrôle dossier inscription — ' . $etudiant->nom_complet)

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    {{-- Fil d'ariane & En-tête --}}
    <div class="mb-4">
        <a href="{{ route('admin.centre-travail.index') }}" class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-500 hover:text-slate-800 transition focus:outline-none focus:ring-2 focus:ring-primary-500 rounded-lg px-2 py-1">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/></svg>
            Retour au Centre de travail
        </a>
    </div>

    <x-page-header
        titre="Contrôle inscription — {{ $etudiant->nom_complet }}"
        description="Diagnostic décisionnel explicable en lecture seule : analyse d'éligibilité, matrice documentaire, moteur d'anomalies et workflow 4-eyes.">
        <x-slot:actions>
            <div class="flex items-center gap-2">
                @can('archives.update')
                    @if($inscription->statut === 'en_cours')
                        @if(($evaluation['eligible'] ?? false) && ($evaluation['verdict'] ?? '') !== 'BLOCAGE')
                            <form method="POST" action="{{ route('admin.centre-travail.valider', $inscription) }}" onsubmit="return confirm('Confirmez-vous la validation définitive de cette inscription académique ?');" class="inline">
                                @csrf
                                <button type="submit" class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-3.5 py-2 text-xs font-bold text-white hover:bg-emerald-700 transition focus:outline-none focus:ring-2 focus:ring-emerald-500 shadow-xs">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    Valider l'inscription
                                </button>
                            </form>
                        @else
                            <button type="button" disabled title="Validation bloquée par des anomalies ou pièces manquantes" class="inline-flex items-center gap-1.5 rounded-xl bg-slate-100 border border-slate-200 px-3.5 py-2 text-xs font-bold text-slate-400 cursor-not-allowed">
                                <svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m0 0v2m0-2h2m-2 0H10m11-3.5a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                Validation bloquée
                            </button>
                        @endif

                        <button type="button" onclick="document.getElementById('modal-ajourner').classList.remove('hidden')" class="inline-flex items-center gap-1.5 rounded-xl border border-rose-200 bg-white px-3.5 py-2 text-xs font-bold text-rose-700 hover:bg-rose-50 transition focus:outline-none focus:ring-2 focus:ring-rose-500 shadow-xs">
                            <svg class="h-4 w-4 text-rose-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            Ajourner / Refuser
                        </button>
                    @endif
                @endcan
                @can('archives.view')
                <a href="{{ route('admin.inscriptions.file-traitement', ['search' => $etudiant->cin ?: $etudiant->nom]) }}"
                   class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 transition focus:outline-none focus:ring-2 focus:ring-primary-500">
                    <svg class="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    File de traitement
                </a>
                <a href="{{ route('admin.verification.show', $etudiant) }}"
                   class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 transition focus:outline-none focus:ring-2 focus:ring-primary-500">
                    <svg class="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    Vérification dossier
                </a>
                @endcan
            </div>
        </x-slot:actions>
    </x-page-header>

    {{-- Avertissement institutionnel de lecture seule --}}
    <div class="mt-4 rounded-xl border border-blue-200 bg-blue-50/70 p-4 text-xs text-blue-900 shadow-xs flex items-start gap-3">
        <svg class="h-5 w-5 flex-shrink-0 text-blue-600 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <div>
            <p class="font-bold text-blue-950">Photographie décisionnelle temps réel (Lecture seule stricte)</p>
            <p class="mt-0.5 text-blue-800">
                Cette évaluation explicable est une composition en lecture seule des moteurs canoniques de UniRegistre (périodes, pièces, anomalies, MENS).
                L'ouverture et la consultation de cet écran ne modifient aucun état, n'assignent aucune tâche et ne réservent pas le dossier. La validation administrative relève exclusivement d'un acte humain distinct.
            </p>
        </div>
    </div>

    {{-- ── 1. Fiche Récapitulative du Dossier ── --}}
    <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-xs">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-100 pb-4">
            <div>
                <div class="flex items-center gap-2">
                    <h2 class="text-lg font-black text-slate-900">{{ $etudiant->nom_complet }}</h2>
                    @php
                        $badgeStatutInscription = match($inscription->statut) {
                            'valide' => 'bg-emerald-100 text-emerald-800 border-emerald-200',
                            'en_cours' => 'bg-amber-100 text-amber-800 border-amber-200',
                            'rejete' => 'bg-rose-100 text-rose-800 border-rose-200',
                            default => 'bg-slate-100 text-slate-800 border-slate-200',
                        };
                    @endphp
                    <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-bold uppercase tracking-wider {{ $badgeStatutInscription }}">
                        Inscription : {{ $inscription->statut }}
                    </span>
                </div>
                <p class="text-xs text-slate-500 mt-0.5">
                    CIN : <span class="font-mono font-bold text-slate-700">{{ $etudiant->cin ?: 'Non renseigné' }}</span>
                    · Matricule : <span class="font-mono font-bold text-slate-700">{{ $etudiant->matricule }}</span>
                    · Inscription #{{ $inscription->id }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2 text-xs">
                <div class="rounded-xl bg-slate-50 border border-slate-200 px-3 py-1.5 text-slate-700">
                    <span class="text-slate-400 block text-[10px] font-bold uppercase">Faculté</span>
                    <span class="font-semibold text-slate-900">{{ $etudiant->faculte?->code }} — {{ $etudiant->faculte?->nom }}</span>
                </div>
                <div class="rounded-xl bg-slate-50 border border-slate-200 px-3 py-1.5 text-slate-700">
                    <span class="text-slate-400 block text-[10px] font-bold uppercase">Cursus &amp; Niveau</span>
                    <span class="font-semibold text-slate-900">{{ $inscription->programmeRef?->nom ?? $inscription->filiere }} ({{ $inscription->niveau }})</span>
                </div>
                <div class="rounded-xl bg-slate-50 border border-slate-200 px-3 py-1.5 text-slate-700">
                    <span class="text-slate-400 block text-[10px] font-bold uppercase">Année Universitaire</span>
                    <span class="font-semibold text-slate-900">{{ $inscription->anneeUniversitaire?->code ?? '2026-2027' }}</span>
                </div>
            </div>
        </div>

        {{-- Cursus 360° & Autres Inscriptions Académiques --}}
        @if($autresInscriptions->isNotEmpty())
            <div class="mt-3 pt-3 border-t border-slate-100">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-2">Historique du Cursus &amp; Autres Inscriptions ({{ $autresInscriptions->count() }})</p>
                <div class="flex flex-wrap gap-2">
                    @foreach($autresInscriptions as $ai)
                        <div class="inline-flex items-center gap-2 rounded-lg bg-slate-50 border border-slate-200 px-2.5 py-1 text-xs">
                            <span class="font-bold text-slate-900">{{ $ai->anneeUniversitaire?->code ?? 'Année précédente' }}</span>
                            <span class="text-slate-600">{{ $ai->filiere }} ({{ $ai->niveau }})</span>
                            <span class="inline-flex items-center rounded px-1.5 py-0.2 text-[10px] font-bold uppercase {{ $ai->statut === 'valide' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700' }}">
                                {{ $ai->statut }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- ── 2. Bannière du Verdict Canonique d'Éligibilité ── --}}
        @php
            $verdict = $evaluation['verdict'] ?? 'BLOCAGE';
            $verdictClasses = match($verdict) {
                'ELIGIBLE' => [
                    'border' => 'border-emerald-300 bg-emerald-50/70',
                    'badge' => 'bg-emerald-600 text-white',
                    'title' => 'Dossier Éligible à l\'instruction',
                    'message' => 'L\'ensemble des vérifications automatiques (période, pièces obligatoires, moteur d\'anomalies et identité) sont validées.',
                    'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
                ],
                'AVERTISSEMENT' => [
                    'border' => 'border-amber-300 bg-amber-50/70',
                    'badge' => 'bg-amber-600 text-white',
                    'title' => 'Points de vigilance détectés',
                    'message' => 'Le dossier est admissible mais présente des avertissements non bloquants qui requièrent l\'attention du gestionnaire.',
                    'icon' => 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z',
                ],
                default => [
                    'border' => 'border-rose-300 bg-rose-50/70',
                    'badge' => 'bg-rose-600 text-white',
                    'title' => 'Blocage administratif avéré',
                    'message' => 'Le dossier ne peut pas être validé en l\'état : des pièces obligatoires manquent ou des anomalies bloquantes ont été identifiées.',
                    'icon' => 'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z',
                ],
            };
        @endphp

        <div class="mt-4 rounded-xl border p-4.5 {{ $verdictClasses['border'] }} shadow-xs">
            <div class="flex items-start gap-3.5">
                <svg class="h-6 w-6 flex-shrink-0 text-slate-800 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $verdictClasses['icon'] }}" />
                </svg>
                <div class="flex-1">
                    <div class="flex items-center gap-2">
                        <span class="inline-flex items-center rounded-lg px-2.5 py-0.5 text-xs font-black uppercase tracking-wider {{ $verdictClasses['badge'] }}">
                            Verdict : {{ $verdict }}
                        </span>
                        <h3 class="font-bold text-slate-900 text-sm">{{ $verdictClasses['title'] }}</h3>
                    </div>
                    <p class="mt-1 text-xs text-slate-700 leading-relaxed">{{ $verdictClasses['message'] }}</p>

                    @if(!empty($evaluation['actions_proposees']))
                        <div class="mt-3 border-t border-slate-200/60 pt-2.5">
                            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-600">Actions recommandées :</p>
                            <ul class="mt-1 list-disc list-inside text-xs text-slate-800 space-y-0.5">
                                @foreach($evaluation['actions_proposees'] as $action)
                                    <li>{{ $action }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- ── 3. Synthèse des 4 Contrôles Canoniques ── --}}
    <div class="mt-6">
        <h3 class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-3">Diagnostic des 4 piliers de contrôle</h3>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach($evaluation['controles'] as $ctrl)
                @php
                    $ctrlStyle = match($ctrl['statut']) {
                        'pass' => ['border' => 'border-emerald-200 bg-white', 'badge' => 'bg-emerald-100 text-emerald-800', 'lib' => 'Conforme'],
                        'avertissement' => ['border' => 'border-amber-200 bg-white', 'badge' => 'bg-amber-100 text-amber-800', 'lib' => 'Avertissement'],
                        default => ['border' => 'border-rose-200 bg-white', 'badge' => 'bg-rose-100 text-rose-800', 'lib' => 'Non conforme'],
                    };
                @endphp
                <div class="rounded-2xl border p-4 shadow-xs {{ $ctrlStyle['border'] }} flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between gap-2 mb-2">
                            <span class="text-[11px] font-black uppercase tracking-wider text-slate-500">{{ $ctrl['libelle'] }}</span>
                            <span class="inline-flex items-center rounded-md px-1.5 py-0.5 text-[10px] font-bold uppercase {{ $ctrlStyle['badge'] }}">
                                {{ $ctrlStyle['lib'] }}
                            </span>
                        </div>
                        <p class="text-xs text-slate-800 leading-relaxed">{{ $ctrl['message'] }}</p>
                    </div>
                    <p class="mt-3 text-[10px] text-slate-400 font-mono">Code : {{ $ctrl['code'] }}</p>
                </div>
            @endforeach
        </div>
    </div>

    {{-- ── 4. Matrice Détaillée des Pièces Justificatives (GED) ── --}}
    <div class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-xs overflow-hidden">
        <div class="bg-slate-50/80 px-5 py-3.5 border-b border-slate-100 flex items-center justify-between">
            <div>
                <h3 class="text-sm font-bold text-slate-900">Matrice des pièces justificatives obligatoires</h3>
                <p class="text-xs text-slate-500 mt-0.5">Vérification des pièces requises pour le type d'inscription ({{ $inscription->type ?? 'première inscription' }}).</p>
            </div>
            @if(($evaluation['documents']['complet'] ?? false))
                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 text-emerald-800 px-2.5 py-0.5 text-xs font-bold">
                    Dossier documentaire complet
                </span>
            @else
                <span class="inline-flex items-center gap-1 rounded-full bg-rose-100 text-rose-800 px-2.5 py-0.5 text-xs font-bold">
                    Pièces manquantes ou non validées
                </span>
            @endif
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-100 text-left text-sm" aria-label="Liste des pièces justificatives">
                <thead class="bg-slate-50 text-[11px] font-bold uppercase tracking-wider text-slate-600">
                    <tr>
                        <th scope="col" class="px-5 py-3">Pièce requise</th>
                        <th scope="col" class="px-5 py-3">Format &amp; Consignes</th>
                        <th scope="col" class="px-5 py-3">État GED actuel</th>
                        <th scope="col" class="px-5 py-3">Statut contrôle</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($evaluation['documents']['pieces'] ?? [] as $codePiece => $infoPiece)
                        @php
                            $etatGed = $infoPiece['etat'] ?? 'MANQUANT';
                            $badgeGed = match($etatGed) {
                                'VALIDE' => 'bg-emerald-100 text-emerald-800 border-emerald-200',
                                'EN_ATTENTE' => 'bg-blue-100 text-blue-800 border-blue-200',
                                'REJETE' => 'bg-rose-100 text-rose-800 border-rose-200',
                                default => 'bg-amber-100 text-amber-800 border-amber-200',
                            };
                        @endphp
                        <tr class="hover:bg-slate-50/50">
                            <td class="px-5 py-3 font-semibold text-slate-900">
                                {{ $infoPiece['libelle'] ?? $codePiece }}
                                <span class="block text-[11px] font-mono text-slate-400">Code : {{ $codePiece }}</span>
                            </td>
                            <td class="px-5 py-3 text-xs text-slate-600">
                                {{ $infoPiece['description'] ?? 'Document justificatif obligatoire.' }}
                            </td>
                            <td class="px-5 py-3 whitespace-nowrap">
                                <span class="inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-bold {{ $badgeGed }}">
                                    {{ $etatGed }}
                                </span>
                            </td>
                            <td class="px-5 py-3 whitespace-nowrap text-xs">
                                @if($etatGed === 'VALIDE')
                                    <span class="text-emerald-700 font-semibold">✓ Conforme</span>
                                @elseif($etatGed === 'EN_ATTENTE')
                                    <span class="text-blue-700 font-semibold">⏳ En attente de revue</span>
                                @elseif($etatGed === 'REJETE')
                                    <span class="text-rose-700 font-semibold">✗ Pièce rejetée</span>
                                @else
                                    <span class="text-amber-700 font-semibold">⚠ Pièce manquante</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-5 py-6 text-center text-xs text-slate-500">
                                Aucune pièce spécifique exigée par la configuration pour ce type d'inscription.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── 5. Anomalies Détectées (Bloquantes & Avertissements) ── --}}
    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        {{-- Anomalies Bloquantes --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-xs">
            <div class="flex items-center gap-2 mb-3">
                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-rose-100 text-rose-700 text-xs font-black">!</span>
                <h3 class="text-sm font-bold text-slate-900">Anomalies bloquantes ({{ count($evaluation['blocages'] ?? []) }})</h3>
            </div>
            @forelse($evaluation['blocages'] ?? [] as $b)
                <div class="mb-3 rounded-xl border border-rose-200 bg-rose-50/50 p-3 text-xs">
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-bold text-rose-900">{{ $b['titre'] }}</span>
                        <span class="font-mono text-[10px] text-rose-600 bg-rose-100 px-1.5 py-0.5 rounded">{{ $b['code'] }}</span>
                    </div>
                    @if(!empty($b['constat']))
                        <p class="mt-1 text-slate-700 leading-relaxed">{{ $b['constat'] }}</p>
                    @endif
                    @if(!empty($b['recommandation']))
                        <p class="mt-1.5 text-rose-800 font-semibold text-[11px]">Action : {{ $b['recommandation'] }}</p>
                    @endif
                </div>
            @empty
                <p class="text-xs text-slate-500 italic py-2">Aucune anomalie bloquante détectée sur ce dossier.</p>
            @endforelse
        </div>

        {{-- Avertissements --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-xs">
            <div class="flex items-center gap-2 mb-3">
                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-amber-100 text-amber-800 text-xs font-black">i</span>
                <h3 class="text-sm font-bold text-slate-900">Avertissements &amp; Vigilance ({{ count($evaluation['avertissements'] ?? []) }})</h3>
            </div>
            @forelse($evaluation['avertissements'] ?? [] as $a)
                <div class="mb-3 rounded-xl border border-amber-200 bg-amber-50/50 p-3 text-xs">
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-bold text-amber-900">{{ $a['titre'] }}</span>
                        <span class="font-mono text-[10px] text-amber-700 bg-amber-100 px-1.5 py-0.5 rounded">{{ $a['code'] }}</span>
                    </div>
                    @if(!empty($a['constat']))
                        <p class="mt-1 text-slate-700 leading-relaxed">{{ $a['constat'] }}</p>
                    @endif
                    @if(!empty($a['recommandation']))
                        <p class="mt-1.5 text-amber-800 font-semibold text-[11px]">Recommandation : {{ $a['recommandation'] }}</p>
                    @endif
                </div>
            @empty
                <p class="text-xs text-slate-500 italic py-2">Aucun avertissement particulier sur ce dossier.</p>
            @endforelse
        </div>
    </div>

    {{-- ── 6. Workflow à Quatre Yeux (Corrections en Attente de Décision) ── --}}
    <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-xs">
        <div class="flex items-center justify-between gap-4 mb-4">
            <div>
                <h3 class="text-sm font-bold text-slate-900">Workflow à quatre yeux — Propositions de correction</h3>
                <p class="text-xs text-slate-500 mt-0.5">Principe de double regard : l'agent ayant proposé une modification ne peut pas la valider lui-même.</p>
            </div>
            <span class="inline-flex items-center rounded-md bg-purple-50 text-purple-800 border border-purple-200 px-2 py-0.5 text-xs font-bold">
                {{ $correctionsEnAttente->count() }} proposition(s) en attente
            </span>
        </div>

        @if($correctionsEnAttente->isNotEmpty())
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-left text-xs" aria-label="Propositions de correction à quatre yeux">
                    <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-wider text-slate-600">
                        <tr>
                            <th scope="col" class="px-4 py-2.5">Champ</th>
                            <th scope="col" class="px-4 py-2.5">Valeur actuelle</th>
                            <th scope="col" class="px-4 py-2.5">Nouvelle valeur proposée</th>
                            <th scope="col" class="px-4 py-2.5">Motif &amp; Règle</th>
                            <th scope="col" class="px-4 py-2.5">Proposée par</th>
                            <th scope="col" class="px-4 py-2.5">Statut décision</th>
                            <th scope="col" class="px-4 py-2.5 text-right">Actions (4-eyes)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($correctionsEnAttente as $c)
                            @php
                                $estAuteur = (int) $c->propose_par === (int) auth()->id();
                            @endphp
                            <tr class="hover:bg-slate-50/50">
                                <td class="px-4 py-2.5 font-bold text-slate-900 font-mono">{{ $c->champ }}</td>
                                <td class="px-4 py-2.5 text-slate-600 font-mono">{{ $c->ancienne_valeur ?? '∅' }}</td>
                                <td class="px-4 py-2.5 text-emerald-800 font-bold font-mono">{{ $c->nouvelle_valeur }}</td>
                                <td class="px-4 py-2.5 text-slate-700">
                                    <span class="font-semibold">{{ $c->motif }}</span>
                                    <span class="block text-[10px] text-slate-400">Règle : {{ $c->regle }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-slate-600 whitespace-nowrap">
                                    Agent #{{ $c->propose_par }}
                                    @if($estAuteur)
                                        <span class="inline-block text-[10px] text-amber-700 font-bold">(Vous)</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 whitespace-nowrap">
                                    @if($estAuteur)
                                        <span class="inline-flex items-center rounded-md bg-amber-50 text-amber-800 border border-amber-200 px-2 py-0.5 text-[10px] font-bold">
                                            Second agent requis (4-eyes)
                                        </span>
                                    @else
                                        <span class="inline-flex items-center rounded-md bg-indigo-50 text-indigo-800 border border-indigo-200 px-2 py-0.5 text-[10px] font-bold">
                                            Décision autorisée
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 whitespace-nowrap text-right">
                                    @if($estAuteur)
                                        <span class="inline-flex items-center rounded-md bg-slate-50 text-slate-400 border border-slate-200 px-2 py-0.5 text-[10px] font-bold cursor-not-allowed" title="Auto-approbation interdite">
                                            Auto-approbation interdite
                                        </span>
                                    @else
                                        @can('archives.update')
                                        <div class="inline-flex items-center gap-1.5">
                                            <form method="POST" action="{{ route('admin.centre-travail.corrections.decider', [$inscription, $c]) }}" class="inline" onsubmit="return confirm('Confirmez-vous l\'approbation de cette correction ?');">
                                                @csrf
                                                <input type="hidden" name="decision" value="approuver">
                                                <button type="submit" class="rounded-lg bg-emerald-600 px-2 py-1 text-[11px] font-bold text-white hover:bg-emerald-700 transition">
                                                    Approuver
                                                </button>
                                            </form>
                                            <form method="POST" action="{{ route('admin.centre-travail.corrections.decider', [$inscription, $c]) }}" class="inline" onsubmit="return confirm('Confirmez-vous le rejet de cette correction ?');">
                                                @csrf
                                                <input type="hidden" name="decision" value="rejeter">
                                                <input type="hidden" name="motif" value="Rejetée depuis le Centre de travail">
                                                <button type="submit" class="rounded-lg border border-rose-200 bg-white px-2 py-1 text-[11px] font-bold text-rose-700 hover:bg-rose-50 transition">
                                                    Rejeter
                                                </button>
                                            </form>
                                        </div>
                                        @else
                                        <span class="text-slate-400 text-[10px]">Lecture seule</span>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-xs text-slate-500 italic py-2">Aucune proposition de correction en attente pour cet étudiant.</p>
        @endif

        {{-- Historique des décisions 4-yeux antérieures --}}
        @if($correctionsHistorique->isNotEmpty())
            <div class="mt-4 pt-4 border-t border-slate-100" x-data="{ open: false }">
                <button type="button" @click="open = !open" class="flex items-center justify-between w-full text-left text-xs font-bold text-slate-700 hover:text-slate-900">
                    <span class="flex items-center gap-1.5">
                        <svg class="h-3.5 w-3.5 text-slate-500 transition-transform" :class="{ 'rotate-90': open }" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        Historique des corrections décidées ({{ $correctionsHistorique->count() }})
                    </span>
                    <span class="text-[11px] text-primary-700 underline" x-text="open ? 'Masquer' : 'Afficher'"></span>
                </button>

                <div x-show="open" x-cloak class="mt-3 overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-100 text-left text-xs">
                        <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-wider text-slate-500">
                            <tr>
                                <th class="px-3 py-2">Champ</th>
                                <th class="px-3 py-2">Ancienne → Nouvelle valeur</th>
                                <th class="px-3 py-2">Motif</th>
                                <th class="px-3 py-2">Décision</th>
                                <th class="px-3 py-2">Décidée par</th>
                                <th class="px-3 py-2">Date</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($correctionsHistorique as $ch)
                                <tr class="hover:bg-slate-50/50">
                                    <td class="px-3 py-2 font-mono font-bold text-slate-800">{{ $ch->champ }}</td>
                                    <td class="px-3 py-2 text-slate-600 font-mono">{{ $ch->ancienne_valeur ?? '∅' }} → <span class="font-bold text-slate-900">{{ $ch->nouvelle_valeur }}</span></td>
                                    <td class="px-3 py-2 text-slate-600">{{ $ch->motif }}</td>
                                    <td class="px-3 py-2">
                                        <span class="inline-flex rounded px-1.5 py-0.5 text-[10px] font-bold uppercase {{ $ch->statut === 'approuvee' ? 'bg-emerald-100 text-emerald-800' : ($ch->statut === 'rejetee' ? 'bg-rose-100 text-rose-800' : 'bg-slate-100 text-slate-700') }}">
                                            {{ $ch->statut }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 text-slate-600">Agent #{{ $ch->decide_par ?? '—' }}</td>
                                    <td class="px-3 py-2 text-slate-400 text-[11px]">{{ $ch->decide_le?->format('d/m/Y H:i') ?? $ch->updated_at?->format('d/m/Y H:i') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>

    {{-- ── 7. Dossier Financier & Affectation de Groupe ── --}}
    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        {{-- Section E : Finances & Rapprochement de Paiement (Fail-Soft) --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-xs">
            <div class="flex items-center justify-between mb-3">
                <div class="flex items-center gap-2">
                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-emerald-100 text-emerald-800 text-xs font-black">€</span>
                    <h3 class="text-sm font-bold text-slate-900">Dossier financier &amp; Paiement</h3>
                </div>
                @if($dossierPaiement)
                    @php
                        $badgePaiement = match($dossierPaiement->statut) {
                            'VALIDE' => 'bg-emerald-100 text-emerald-800 border-emerald-200',
                            'EN_VERIFICATION', 'RECU_DEPOSE' => 'bg-amber-100 text-amber-800 border-amber-200',
                            'REFUSE' => 'bg-rose-100 text-rose-800 border-rose-200',
                            default => 'bg-slate-100 text-slate-800 border-slate-200',
                        };
                    @endphp
                    <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-[11px] font-bold uppercase tracking-wider {{ $badgePaiement }}">
                        {{ $dossierPaiement->statut }}
                    </span>
                @else
                    <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-[10px] font-bold text-slate-600">
                        Fail-soft (non requis)
                    </span>
                @endif
            </div>

            @if($dossierPaiement)
                <div class="space-y-3 text-xs">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-2">
                        <span class="text-slate-500">Statut du dossier :</span>
                        <span class="font-bold text-slate-900">{{ $dossierPaiement->statut }}</span>
                    </div>
                    @if($dossierPaiement->recus && $dossierPaiement->recus->isNotEmpty())
                        <div class="mt-2">
                            <span class="block text-[11px] font-bold uppercase text-slate-500 mb-1.5">Reçus de paiement déclarés :</span>
                            <div class="space-y-2">
                                @foreach($dossierPaiement->recus as $recu)
                                    <div class="rounded-xl border border-slate-100 bg-slate-50 p-2.5 flex items-center justify-between">
                                        <div>
                                            <p class="font-mono font-bold text-slate-900">{{ $recu->reference_paiement ?? $recu->reference_recu ?? 'Reçu #' . $recu->id }}</p>
                                            <p class="text-[11px] text-slate-500">
                                                Montant déclaré : <span class="font-semibold text-slate-700">{{ number_format(($recu->montant_declare_centimes ?? 0) / 100, 2) }} TND</span>
                                                @if($recu->banque_emettrice) · {{ $recu->banque_emettrice }} @endif
                                            </p>
                                        </div>
                                        <span class="inline-flex items-center rounded px-2 py-0.5 text-[10px] font-bold uppercase {{ $recu->statut === 'VALIDE' ? 'bg-emerald-100 text-emerald-800' : ($recu->statut === 'REFUSE' ? 'bg-rose-100 text-rose-800' : 'bg-amber-100 text-amber-800') }}">
                                            {{ $recu->statut }}
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <p class="text-xs text-slate-500 italic py-1">Aucun reçu déposé pour ce dossier.</p>
                    @endif
                </div>
            @else
                <p class="text-xs text-slate-500 py-2">
                    Le module de paiement n'a détecté aucun dossier financier actif pour cette inscription ou les droits d'inscription sont dispensés.
                </p>
            @endif
        </div>

        {{-- Section F : Affectation Académique & Groupe --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-xs">
            <div class="flex items-center justify-between mb-3">
                <div class="flex items-center gap-2">
                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-blue-100 text-blue-800 text-xs font-black">G</span>
                    <h3 class="text-sm font-bold text-slate-900">Affectation de groupe &amp; Filière</h3>
                </div>
                @if($etudiant->groupe)
                    <span class="inline-flex items-center rounded-full border border-blue-200 bg-blue-50 px-2.5 py-0.5 text-[11px] font-bold text-blue-800">
                        {{ $etudiant->groupe->nom ?? $etudiant->groupe->code }}
                    </span>
                @else
                    <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-[10px] font-bold text-slate-500">
                        Sans groupe
                    </span>
                @endif
            </div>

            <div class="space-y-3 text-xs">
                <div class="flex items-center justify-between border-b border-slate-100 pb-2">
                    <span class="text-slate-500">Groupe actuel :</span>
                    <span class="font-bold text-slate-900">{{ $etudiant->groupe?->nom ?? 'Aucun groupe assigné' }}</span>
                </div>
                <div class="flex items-center justify-between border-b border-slate-100 pb-2">
                    <span class="text-slate-500">Filière / Niveau :</span>
                    <span class="font-semibold text-slate-800">{{ $inscription->filiere }} — {{ $inscription->niveau }}</span>
                </div>

                @if($historiqueAffectations->isNotEmpty())
                    <div class="mt-2">
                        <span class="block text-[11px] font-bold uppercase text-slate-500 mb-1.5">Historique des affectations :</span>
                        <div class="space-y-1.5">
                            @foreach($historiqueAffectations as $aff)
                                <div class="rounded-lg bg-slate-50 p-2 border border-slate-100 flex items-center justify-between text-xs">
                                    <div>
                                        <span class="font-semibold text-slate-900">{{ $aff->groupe?->nom ?? 'Groupe #' . $aff->groupe_id }}</span>
                                        @if($aff->motif)<span class="block text-[10px] text-slate-500">{{ $aff->motif }}</span>@endif
                                    </div>
                                    <div class="text-right">
                                        <span class="text-[10px] font-mono text-slate-500">{{ $aff->date_debut?->format('d/m/Y') }}</span>
                                        <span class="block text-[10px] font-bold text-slate-700">{{ $aff->statut }}</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @else
                    <p class="text-xs text-slate-500 italic py-1">Aucun historique d'affectation de groupe enregistré.</p>
                @endif
            </div>
        </div>
    </div>

    {{-- ── 8. Suivi de la Tâche & Journal d'Audit ── --}}
    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Tâche Administrative --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-xs">
            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-3">Tâche administrative</h3>
            @if($tache)
                <div class="space-y-2 text-xs">
                    <div>
                        <span class="text-slate-400 block text-[10px] font-bold uppercase">Clé unique</span>
                        <span class="font-mono text-slate-900 font-semibold">{{ $tache->cle_unique }}</span>
                    </div>
                    <div>
                        <span class="text-slate-400 block text-[10px] font-bold uppercase">Statut actuel</span>
                        <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-bold uppercase bg-slate-100 text-slate-800 mt-0.5">
                            {{ $tache->statut }}
                        </span>
                    </div>
                    <div>
                        <span class="text-slate-400 block text-[10px] font-bold uppercase">Priorité</span>
                        <span class="font-bold text-slate-900 uppercase">{{ $tache->priorite }}</span>
                    </div>
                    <div>
                        <span class="text-slate-400 block text-[10px] font-bold uppercase">Assigné à</span>
                        <span class="text-slate-900">{{ $tache->assigne?->name ?? 'Non assignée' }}</span>
                    </div>
                    <div>
                        <span class="text-slate-400 block text-[10px] font-bold uppercase">Date de création</span>
                        <span class="text-slate-700">{{ $tache->created_at?->format('d/m/Y H:i') }} ({{ $tache->created_at?->diffForHumans() }})</span>
                    </div>
                </div>
            @else
                <p class="text-xs text-slate-500 italic">Aucune tâche active dans la file pour cette inscription.</p>
            @endif
        </div>

        {{-- Journal d'Audit Académique Récent --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-xs lg:col-span-2">
            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-3">Journal d'audit académique récent</h3>
            @if($historique->isNotEmpty())
                <div class="space-y-2.5 max-h-60 overflow-y-auto pr-1">
                    @foreach($historique as $log)
                        <div class="rounded-xl bg-slate-50 p-2.5 text-xs border border-slate-100 flex items-start justify-between gap-3">
                            <div>
                                <span class="font-semibold text-slate-900">{{ $log->action_libelle }}</span>
                                <span class="block text-[11px] text-slate-500">
                                    Par {{ $log->utilisateur?->name ?? 'Système' }} · Entité : {{ $log->entite }} #{{ $log->entite_id }}
                                </span>
                            </div>
                            <span class="text-[10px] text-slate-400 whitespace-nowrap">{{ $log->created_at?->format('d/m/Y H:i') }}</span>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-xs text-slate-500 italic">Aucun événement d'audit récent pour ce dossier.</p>
            @endif
        </div>
    </div>

    @can('archives.update')
    @if($inscription->statut === 'en_cours')
    <div id="modal-ajourner" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
        <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl border border-slate-200">
            <h3 class="text-base font-bold text-slate-900">Ajourner / Refuser l'inscription</h3>
            <p class="mt-1 text-xs text-slate-500">Précisez le motif officiel d'ajournement. Ce motif sera enregistré dans le dossier de l'étudiant et audité.</p>
            <form method="POST" action="{{ route('admin.centre-travail.rejeter', $inscription) }}" class="mt-4 space-y-4">
                @csrf
                <div>
                    <label for="motif_rejet" class="block text-xs font-bold text-slate-700 mb-1">Motif officiel d'ajournement <span class="text-rose-500">*</span></label>
                    <textarea name="motif" id="motif_rejet" rows="3" required minlength="5" maxlength="500" placeholder="Ex. Pièces justificatives non conformes, prérequis académiques non satisfaits..." class="w-full rounded-xl border border-slate-200 p-2.5 text-xs text-slate-900 focus:border-rose-500 focus:ring-2 focus:ring-rose-500/20 focus:outline-none"></textarea>
                </div>
                <div class="flex items-center justify-end gap-2 pt-2">
                    <button type="button" onclick="document.getElementById('modal-ajourner').classList.add('hidden')" class="rounded-xl border border-slate-200 px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 transition">Annuler</button>
                    <button type="submit" class="rounded-xl bg-rose-600 px-3.5 py-2 text-xs font-bold text-white hover:bg-rose-700 shadow-xs transition">Confirmer l'ajournement</button>
                </div>
            </form>
        </div>
    </div>
    @endif
    @endcan
</div>
@endsection
