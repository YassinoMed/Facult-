@extends('layouts.app')

@section('title', 'Demande #' . $demande->reference)

@section('content')
@php
    $libellesStatut = [
        'soumise' => ['En attente d’approbation', 'bg-amber-50 text-amber-700 border-amber-200'],
        'approuvee' => ['Approuvée', 'bg-emerald-50 text-emerald-700 border-emerald-200'],
        'rejetee' => ['Rejetée', 'bg-rose-50 text-rose-700 border-rose-200'],
        'annulee' => ['Annulée', 'bg-slate-100 text-slate-500 border-slate-200'],
        'expiree' => ['Expirée', 'bg-slate-100 text-slate-500 border-slate-200'],
    ];
    [$libelleStatut, $classesStatut] = $libellesStatut[$demande->statut] ?? [ucfirst($demande->statut), 'bg-slate-100 text-slate-600 border-slate-200'];
    $payloadAffiche = collect($demande->payload ?? [])
        ->reject(fn ($valeur, $cle) => is_string($cle) && str_starts_with($cle, '__'))
        ->all();
@endphp

<div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
    <x-page-header titre="Demande à quatre yeux"
        :description="$demande->libelleType() . ' — ' . ($demande->profil?->nom ?? 'objet supprimé')"
        :fil="['Gouvernance IAM' => '#', 'Rôles' => route('admin.roles.index'), 'Circuit 4 yeux' => route('admin.roles.demandes.index'), 'Demande' => '#']">
        <x-slot:actions>
            <a href="{{ route('admin.roles.demandes.index') }}"
               class="rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50">
                ← Retour au circuit
            </a>
        </x-slot:actions>
    </x-page-header>

    @if(session('success'))
        <div class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50/80 px-5 py-3.5 text-sm font-semibold text-emerald-800">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div class="mt-6 rounded-2xl border border-rose-200 bg-rose-50/90 p-4" role="alert">
            <h2 class="text-sm font-bold text-rose-900">La décision n’a pas été enregistrée :</h2>
            <ul class="mt-2 list-inside list-disc space-y-1 text-xs text-rose-700">
                @foreach($errors->all() as $erreur)
                    <li>{{ $erreur }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="mt-6 flex flex-wrap items-center gap-3">
        <span class="inline-flex rounded-full border px-3 py-1 text-[11px] font-black {{ $classesStatut }}">{{ $libelleStatut }}</span>
        <span class="font-mono text-[11px] text-slate-500">{{ $demande->reference }}</span>
        <span class="text-[11px] text-slate-400">Créée le {{ $demande->created_at?->format('d/m/Y à H:i') }}</span>
        @if($demande->decide_le)
            <span class="text-[11px] text-slate-400">Clôturée le {{ $demande->decide_le->format('d/m/Y à H:i') }}</span>
        @endif
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <section class="ds-card p-6 lg:col-span-2" aria-labelledby="detail-demande">
            <h2 id="detail-demande" class="text-sm font-black text-slate-900">Détail de la demande</h2>

            <dl class="mt-4 grid grid-cols-1 gap-4 text-xs sm:grid-cols-2">
                <div>
                    <dt class="font-bold uppercase tracking-wider text-slate-400">Type</dt>
                    <dd class="mt-1 font-semibold text-slate-800">{{ $demande->libelleType() }}</dd>
                </div>
                <div>
                    <dt class="font-bold uppercase tracking-wider text-slate-400">Rôle visé</dt>
                    <dd class="mt-1 font-semibold text-slate-800">
                        @if($demande->profil)
                            <a href="{{ route('admin.roles.show', $demande->profil->id) }}" class="text-primary-800 hover:underline">
                                {{ $demande->profil->nom }} ({{ $demande->profil->code }})
                            </a>
                        @else
                            —
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="font-bold uppercase tracking-wider text-slate-400">Initiateur</dt>
                    <dd class="mt-1 font-semibold text-slate-800">{{ $demande->initiateur?->name ?? 'Système' }}</dd>
                </div>
                <div>
                    <dt class="font-bold uppercase tracking-wider text-slate-400">Bénéficiaire</dt>
                    <dd class="mt-1 font-semibold text-slate-800">{{ $demande->cible?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="font-bold uppercase tracking-wider text-slate-400">Faculté</dt>
                    <dd class="mt-1 font-semibold text-slate-800">{{ $demande->faculte?->nom ?? 'Globale' }}</dd>
                </div>
                <div>
                    <dt class="font-bold uppercase tracking-wider text-slate-400">Approbations requises</dt>
                    <dd class="mt-1 font-semibold text-slate-800">{{ max(1, (int) config('iam.approbateurs_requis', 1)) }}</dd>
                </div>
            </dl>

            <div class="mt-5">
                <h3 class="text-[11px] font-black uppercase tracking-wider text-slate-400">Motif</h3>
                <p class="mt-1 rounded-xl border border-slate-200 bg-slate-50/60 px-3 py-2.5 text-xs text-slate-700">{{ $demande->motif }}</p>
            </div>

            @if($payloadAffiche !== [])
                <div class="mt-5">
                    <h3 class="text-[11px] font-black uppercase tracking-wider text-slate-400">Contenu soumis</h3>
                    <pre class="mt-1 overflow-x-auto rounded-xl border border-slate-200 bg-slate-900 px-3 py-2.5 text-[11px] text-slate-100">{{ json_encode($payloadAffiche, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                </div>
            @endif
        </section>

        <aside class="space-y-6">
            {{-- Décision --}}
            <section class="ds-card p-6" aria-labelledby="decision-demande">
                <h2 id="decision-demande" class="text-sm font-black text-slate-900">Décision</h2>

                @if($peutDecider)
                    <form method="POST" action="{{ route('admin.roles.demandes.decider', $demande->id) }}" class="mt-4 space-y-4">
                        @csrf
                        <div>
                            <label for="decision" class="mb-1.5 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Votre décision</label>
                            <select id="decision" name="decision" required
                                    class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs text-slate-900 focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                                <option value="">— Sélectionnez —</option>
                                <option value="approuve" @selected(old('decision') === 'approuve')>Approuver</option>
                                <option value="rejete" @selected(old('decision') === 'rejete')>Rejeter</option>
                            </select>
                        </div>
                        <div>
                            <label for="commentaire" class="mb-1.5 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Commentaire</label>
                            <textarea id="commentaire" name="commentaire" rows="3" maxlength="500"
                                      class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs text-slate-900 focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">{{ old('commentaire') }}</textarea>
                        </div>
                        <button type="submit"
                                class="w-full rounded-xl bg-primary-800 px-4 py-2.5 text-xs font-black text-white shadow-sm hover:bg-primary-700">
                            Enregistrer la décision
                        </button>
                    </form>
                @else
                    <p class="mt-3 rounded-xl border border-slate-200 bg-slate-50/60 px-3 py-2.5 text-xs text-slate-600">
                        @if($demande->statut !== 'soumise')
                            Cette demande est clôturée : aucune décision supplémentaire n’est possible.
                        @elseif($estInitiateur)
                            Vous êtes l’initiateur de cette demande : la règle des quatre yeux interdit l’auto-approbation.
                        @elseif($demande->cible_user_id !== null && (int) $demande->cible_user_id === (int) auth()->id())
                            Vous êtes le bénéficiaire de cette demande : vous ne pouvez pas l’approuver.
                        @else
                            Votre périmètre ne vous permet pas de statuer sur cette demande.
                        @endif
                    </p>
                @endif

                @if($peutAnnuler)
                    <form method="POST" action="{{ route('admin.roles.demandes.annuler', $demande->id) }}" class="mt-4 border-t border-slate-100 pt-4"
                          onsubmit="return confirm('Annuler cette demande ? Elle ne pourra plus être approuvée.');">
                        @csrf
                        <label for="motif-annulation" class="mb-1.5 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Motif d’annulation</label>
                        <input type="text" id="motif-annulation" name="motif" maxlength="500" placeholder="Annulation par l’initiateur"
                               class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs text-slate-900 focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                        <button type="submit" class="mt-2 w-full rounded-xl border border-rose-200 bg-white px-4 py-2 text-xs font-bold text-rose-700 hover:bg-rose-50">
                            Annuler la demande
                        </button>
                    </form>
                @endif
            </section>

            {{-- Approbations --}}
            <section class="ds-card p-6" aria-labelledby="approbations-demande">
                <h2 id="approbations-demande" class="text-sm font-black text-slate-900">Approbations enregistrées</h2>

                <div class="mt-3 space-y-2">
                    @forelse($demande->approbations as $approbation)
                        <div class="rounded-xl border border-slate-200 px-3 py-2.5 text-xs">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-bold text-slate-800">{{ $approbation->approbateur?->name ?? 'Compte supprimé' }}</span>
                                <span class="rounded-full px-2 py-0.5 text-[10px] font-bold
                                             {{ $approbation->decision === 'approuve' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' }}">
                                    {{ $approbation->decision === 'approuve' ? 'Approuvé' : 'Rejeté' }}
                                </span>
                            </div>
                            <p class="mt-1 text-[10px] text-slate-400">{{ $approbation->decide_le?->format('d/m/Y H:i') }}</p>
                            @if($approbation->commentaire)
                                <p class="mt-1 text-[11px] text-slate-600">{{ $approbation->commentaire }}</p>
                            @endif
                        </div>
                    @empty
                        <p class="rounded-xl border border-dashed border-slate-200 bg-slate-50/60 px-3 py-6 text-center text-xs text-slate-500">
                            Aucune approbation enregistrée.
                        </p>
                    @endforelse
                </div>
            </section>
        </aside>
    </div>
</div>
@endsection
