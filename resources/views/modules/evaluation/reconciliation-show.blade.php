@extends('layouts.app')

@section('title', 'Examens — Dossier d’écart')

@section('content')
    <div class="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6">
        <x-page-header
            titre="Dossier d’écart de réconciliation"
            :description="'Instantané des trois sources et circuit à quatre yeux : instruction puis validation par une personne distincte.'"
            :fil="[
                'Évaluation' => route('admin.evaluations.index'),
                'Réconciliation' => route('admin.evaluations.reconciliation.index', ['epreuve_id' => $ecart->epreuve_id]),
                'Dossier #'.$ecart->id => '#',
            ]"
        />

        @if(session('succes'))
            <x-alert type="success">{{ session('succes') }}</x-alert>
        @endif

        @if($errors->any())
            <x-alert type="error">{{ $errors->first() }}</x-alert>
        @endif

        {{-- Identité du dossier --}}
        <div class="ds-card p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Candidat</p>
                    <p class="mt-1 text-lg font-black text-slate-900">
                        {{ trim(($ecart->candidat?->etudiant?->nom ?? '') . ' ' . ($ecart->candidat?->etudiant?->prenom ?? '')) ?: 'Candidat #'.$ecart->candidat_id }}
                    </p>
                    <p class="text-xs text-slate-500">{{ $ecart->candidat?->numero_anonyme }}</p>
                </div>
                <div class="text-right">
                    <span @class([
                        'inline-flex rounded-full px-3 py-1 text-xs font-bold',
                        'bg-amber-100 text-amber-900' => $ecart->estOuvert(),
                        'bg-sky-100 text-sky-900' => $ecart->estInstruit(),
                        'bg-emerald-100 text-emerald-900' => $ecart->estValide(),
                    ])>{{ $ecart->libelleStatut() }}</span>
                    <p class="mt-2 text-[11px] text-slate-500">
                        {{ $ecart->epreuve?->code }} — {{ $ecart->epreuve?->matiere?->nom }}
                    </p>
                    <p class="text-[11px] text-slate-500">{{ $ecart->epreuve?->date?->format('d/m/Y') }}</p>
                </div>
            </div>
        </div>

        {{-- Instantané tri-sources --}}
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-2xl border border-amber-200 bg-amber-50/60 p-4">
                <p class="text-[11px] font-bold uppercase tracking-wider text-amber-800">Source papier</p>
                <p class="mt-2"><code class="rounded bg-white px-2 py-1 text-xs font-bold text-slate-800">{{ $ecart->statut_papier }}</code></p>
                <p class="mt-2 text-[11px] text-amber-900/80">Feuille d’émargement signée.</p>
            </div>
            <div class="rounded-2xl border border-sky-200 bg-sky-50/60 p-4">
                <p class="text-[11px] font-bold uppercase tracking-wider text-sky-800">Source numérique</p>
                <p class="mt-2"><code class="rounded bg-white px-2 py-1 text-xs font-bold text-slate-800">{{ $ecart->statut_numerique }}</code></p>
                <p class="mt-2 text-[11px] text-sky-900/80">Statut serveur au moment de la détection.</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-600">Journal horodaté</p>
                <p class="mt-2 text-xs font-bold text-slate-800">
                    {{ $ecart->journal_horodate?->format('d/m/Y H:i:s') ?? 'Aucune trace' }}
                </p>
                <p class="mt-2 text-[11px] text-slate-500">Troisième source, jamais fusionnée avec les autres.</p>
            </div>
        </div>

        <div class="ds-card space-y-3 p-5">
            <div>
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Nature de la divergence</p>
                <p class="mt-1 text-sm text-slate-800">{{ $ecart->nature_divergence }}</p>
            </div>
            <div class="grid gap-3 text-xs text-slate-600 sm:grid-cols-2">
                <p>
                    <span class="font-bold text-slate-700">Détecté</span>
                    le {{ $ecart->detecte_at?->format('d/m/Y H:i') }}
                    @if($ecart->detecteur) par {{ $ecart->detecteur->name }} @endif
                </p>
                <p>
                    <span class="font-bold text-slate-700">Empreinte source</span>
                    <code class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px]">{{ substr((string) $ecart->empreinte_source, 0, 16) }}…</code>
                </p>
            </div>
        </div>

        {{-- Instruction (première paire de yeux) --}}
        @if($ecart->estOuvert())
            <div class="ds-card p-5">
                <h2 class="text-base font-bold text-slate-900">1. Instruction de l’écart</h2>
                <p class="mt-1 text-xs text-slate-500">
                    Le vérificateur choisit le statut retenu et motive sa décision. La validation par une personne distincte reste obligatoire.
                </p>

                @can('evaluations.update')
                    <form method="post" action="{{ route('admin.evaluations.reconciliation.instruire', $ecart) }}" class="mt-4 space-y-3">
                        @csrf
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="text-xs font-bold text-slate-600">
                                Statut retenu
                                <select name="statut_retenu" required class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                                    <option value="">Sélectionner…</option>
                                    @foreach($statutsCandidat as $statut)
                                        <option value="{{ $statut }}" @selected(old('statut_retenu') === $statut)>{{ $statut }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="text-xs font-bold text-slate-600">
                                Référence de preuve (optionnelle)
                                <input type="text" name="preuve_reference" maxlength="500" value="{{ old('preuve_reference') }}"
                                       class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
                                       placeholder="PV de la commission, émargement contresigné…">
                            </label>
                        </div>
                        <label class="block text-xs font-bold text-slate-600">
                            Motif d’instruction
                            <textarea name="motif_instruction" rows="3" required minlength="5" maxlength="2000"
                                      class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
                                      placeholder="Décrivez la vérification effectuée et la source retenue.">{{ old('motif_instruction') }}</textarea>
                        </label>
                        <div class="flex justify-end">
                            <x-btn type="submit" size="sm">Enregistrer l’instruction</x-btn>
                        </div>
                    </form>
                @else
                    <div class="mt-4">
                        <x-alert type="info">Vous consultez ce dossier sans permission d’instruction.</x-alert>
                    </div>
                @endcan
            </div>
        @endif

        {{-- Validation (seconde paire de yeux) --}}
        @if($ecart->estInstruit())
            <div class="ds-card p-5">
                <h2 class="text-base font-bold text-slate-900">2. Validation par une personne distincte</h2>
                <p class="mt-1 text-xs text-slate-500">
                    Instruction de {{ $ecart->instructeur?->name ?? 'l’instructeur' }}
                    le {{ $ecart->instruit_at?->format('d/m/Y H:i') }} —
                    statut retenu : <code class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px]">{{ $ecart->statut_retenu }}</code>.
                </p>
                <p class="mt-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                    {{ $ecart->motif_instruction }}
                </p>

                @can('evaluations.update')
                    @if((int) auth()->id() === (int) $ecart->instructeur_id)
                        <div class="mt-4">
                            <x-alert type="warning">Séparation des responsabilités : l’instructeur ne peut pas valider son propre dossier.</x-alert>
                        </div>
                    @else
                        <form method="post" action="{{ route('admin.evaluations.reconciliation.valider', $ecart) }}" class="mt-4 space-y-3">
                            @csrf
                            <label class="block text-xs font-bold text-slate-600">
                                Commentaire de validation (optionnel)
                                <textarea name="commentaire_validation" rows="2" maxlength="1000"
                                          class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">{{ old('commentaire_validation') }}</textarea>
                            </label>
                            <div class="flex justify-end">
                                <x-btn type="submit" size="sm">Valider et appliquer le statut</x-btn>
                            </div>
                        </form>
                    @endif
                @else
                    <div class="mt-4">
                        <x-alert type="info">Vous consultez ce dossier sans permission de validation.</x-alert>
                    </div>
                @endcan
            </div>
        @endif

        {{-- Dossier validé --}}
        @if($ecart->estValide())
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50/70 p-5">
                <h2 class="text-base font-bold text-emerald-950">Dossier validé et scellé</h2>
                <p class="mt-1 text-xs text-emerald-900/90">
                    Statut appliqué : <code class="rounded bg-white px-1.5 py-0.5 text-[11px] font-bold">{{ $ecart->statut_retenu }}</code>.
                    Validé par {{ $ecart->valideur?->name ?? 'un valideur' }}
                    le {{ $ecart->valide_at?->format('d/m/Y H:i') }}.
                    L’instantané des sources reste conservé : aucun écart n’est supprimé.
                </p>
                @if($ecart->commentaire_validation)
                    <p class="mt-2 rounded-xl bg-white/80 px-3 py-2 text-xs text-emerald-950">{{ $ecart->commentaire_validation }}</p>
                @endif
            </div>
        @endif

        <div class="flex justify-between">
            <x-btn :href="route('admin.evaluations.reconciliation.index', ['epreuve_id' => $ecart->epreuve_id])" variant="ghost" size="sm">
                ← Retour aux dossiers
            </x-btn>
        </div>
    </div>
@endsection
