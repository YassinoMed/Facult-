@extends('layouts.app')

@section('title', 'Dossier de paiement')

@section('content')
    <div class="mx-auto max-w-6xl px-4 py-8 sm:px-6">
        <x-page-header
            titre="Dossier de paiement"
            :description="($dossier->inscription?->etudiant?->nom ?? '').' '.($dossier->inscription?->etudiant?->prenom ?? '').' — '.($dossier->inscription?->anneeUniversitaire?->libelle ?? '').' — '.($dossier->inscription?->niveau ?? '')"
            :fil="[
                'Finances' => route('admin.finances.index'),
                'Reçus d’inscription' => route('admin.finances.paiements.index'),
                'Dossier' => '#',
            ]">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('admin.finances.paiements.index') }}">Retour à la file</x-btn>
            </x-slot:actions>
        </x-page-header>

        @if(session('success'))
            <div class="mt-4"><x-alert type="success" :message="session('success')" /></div>
        @endif

        @if($errors->has('paiement'))
            <div class="mt-4"><x-alert type="error" :message="$errors->first('paiement')" /></div>
        @endif

        @if($dossier->inscription?->paye && $dossier->statut !== \App\Modules\Finances\Models\DossierPaiement::VALIDE)
            <div class="mt-4">
                <x-alert type="warning" titre="Indicateur historique à Oui"
                         message="L’indicateur de paiement historique de l’inscription est à Oui, mais aucun reçu n’a été validé dans ce dossier. Il peut provenir d’un règlement externe saisi manuellement : vérifiez l’origine avant toute décision." />
            </div>
        @endif

        <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="ds-card p-4">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Statut du dossier</p>
                <div class="mt-2"><x-status-badge :statut="$dossier->statut" /></div>
            </div>
            <div class="ds-card p-4">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Montant attendu</p>
                @if($montant['centimes'] !== null)
                    <p class="mt-1 text-xl font-black tabular-nums text-slate-900">
                        {{ \App\Modules\Finances\Models\Facture::formater($montant['centimes']) }}
                    </p>
                    <p class="mt-0.5 text-xs text-slate-500">{{ $montant['source_libelle'] }}</p>
                @else
                    <p class="mt-1 text-xl font-black text-rose-700">Non configuré</p>
                    <p class="mt-0.5 text-xs text-slate-500">Aucun tarif officiel pour cette inscription</p>
                @endif
            </div>
            <div class="ds-card p-4">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Échéance</p>
                <p class="mt-1 text-xl font-black text-slate-900">
                    {{ $montant['echeance']?->format('d/m/Y') ?? 'Non configurée' }}
                </p>
                <p class="mt-0.5 text-xs text-slate-500">Issue de la facture, si elle existe</p>
            </div>
            <div class="ds-card p-4">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Indicateur historique</p>
                <p class="mt-1 text-xl font-black {{ $dossier->inscription?->paye ? 'text-emerald-700' : 'text-slate-500' }}">
                    {{ $dossier->inscription?->paye ? 'Oui' : 'Non' }}
                </p>
                <p class="mt-0.5 text-xs text-slate-500">Champ `paye` existant, non preuve par reçu</p>
            </div>
        </div>

        @if($montant['details'] !== [])
            <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-4">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Détail des tarifs officiels</p>
                <ul class="mt-2 divide-y divide-slate-100 text-sm">
                    @foreach($montant['details'] as $detail)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <span class="text-slate-700">
                                <strong>{{ $detail['code'] }}</strong> — {{ $detail['libelle'] }}
                            </span>
                            <span class="font-bold tabular-nums text-slate-900">
                                {{ \App\Modules\Finances\Models\Facture::formater((int) $detail['montant_centimes']) }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="mt-4">
            <x-alert type="info" titre="Règle de sécurité"
                     :message="config('inscriptions-paiements.instructions')" />
        </div>

        <div class="mt-5 grid gap-5 lg:grid-cols-3">
            <div class="space-y-5 lg:col-span-2">
                <section class="ds-card p-5">
                    <h2 class="text-base font-black text-slate-900">Reçu le plus récent</h2>

                    @if($dernier === null)
                        <p class="mt-3 text-sm text-slate-500">Aucun reçu n’a encore été déposé pour cette inscription.</p>
                    @else
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <div>
                                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Version</p>
                                <p class="mt-0.5 text-sm font-bold text-slate-900">v{{ $dernier->version }}</p>
                            </div>
                            <div>
                                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Statut du reçu</p>
                                <div class="mt-1"><x-status-badge :statut="$dernier->statut" /></div>
                            </div>
                            <div>
                                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Montant déclaré</p>
                                <p class="mt-0.5 text-sm font-black tabular-nums text-slate-900">{{ $dernier->montantDeclareFormate() }}</p>
                            </div>
                            <div>
                                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Montant attendu (instantané)</p>
                                <p class="mt-0.5 text-sm font-bold tabular-nums text-slate-900">
                                    {{ $dernier->montantAttenduFormate() ?? 'Non configuré au dépôt' }}
                                </p>
                            </div>
                            <div>
                                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Référence</p>
                                <p class="mt-0.5 break-all text-sm font-semibold text-slate-900">{{ $dernier->reference_paiement }}</p>
                            </div>
                            <div>
                                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Date du paiement</p>
                                <p class="mt-0.5 text-sm font-semibold text-slate-900">{{ $dernier->date_paiement?->format('d/m/Y') }}</p>
                            </div>
                            <div>
                                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Déposé le</p>
                                <p class="mt-0.5 text-sm text-slate-700">{{ $dernier->depose_le?->format('d/m/Y H:i') }}</p>
                            </div>
                            <div>
                                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Fichier</p>
                                <p class="mt-0.5 break-all text-sm text-slate-700">
                                    {{ $dernier->original_filename }}
                                    <span class="text-xs text-slate-500">({{ $dernier->mime_type }}, {{ number_format($dernier->size / 1024, 1, ',', ' ') }} Ko)</span>
                                </p>
                            </div>
                        </div>

                        @if($dernier->motif_refus)
                            <div class="mt-3">
                                <x-alert type="error" titre="Motif du refus" :message="$dernier->motif_refus" />
                            </div>
                        @endif

                        @if($dernier->commentaire)
                            <div class="mt-3">
                                <x-alert type="info" titre="Commentaire de décision" :message="$dernier->commentaire" />
                            </div>
                        @endif

                        @if($dernier->pris_en_charge_par !== null || $dernier->traite_par !== null)
                            <p class="mt-3 text-xs text-slate-500">
                                @if($dernier->prisEnChargePar)
                                    Pris en charge par {{ $dernier->prisEnChargePar->name }}
                                    le {{ $dernier->pris_en_charge_le?->format('d/m/Y H:i') }}.
                                @endif
                                @if($dernier->traitePar)
                                    Décision par {{ $dernier->traitePar->name }}
                                    le {{ $dernier->traite_le?->format('d/m/Y H:i') }}.
                                @endif
                            </p>
                        @endif

                        <div class="mt-4 flex flex-wrap gap-2">
                            <x-btn variant="secondary" target="_blank" rel="noopener"
                                   href="{{ route('admin.finances.paiements.recu.apercu', [$dossier, $dernier]) }}">
                                Aperçu
                            </x-btn>
                            <x-btn variant="secondary"
                                   href="{{ route('admin.finances.paiements.recu.telecharger', [$dossier, $dernier]) }}">
                                Télécharger
                            </x-btn>
                        </div>
                    @endif
                </section>

                <section class="ds-card p-5">
                    <h2 class="text-base font-black text-slate-900">Historique des reçus et décisions</h2>
                    @if($dossier->recus->isEmpty())
                        <p class="mt-3 text-sm text-slate-500">Aucun reçu dans l’historique.</p>
                    @else
                        <div class="mt-3 overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="text-left text-[11px] font-bold uppercase tracking-wider text-slate-500">
                                    <tr>
                                        <th scope="col" class="py-2 pr-3">Version</th>
                                        <th scope="col" class="py-2 pr-3">Déposé le</th>
                                        <th scope="col" class="py-2 pr-3">Référence</th>
                                        <th scope="col" class="py-2 pr-3">Montant déclaré</th>
                                        <th scope="col" class="py-2 pr-3">Statut</th>
                                        <th scope="col" class="py-2 pr-3">Motif</th>
                                        <th scope="col" class="py-2 pr-3 text-right">Fichier</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach($dossier->recus as $recu)
                                        <tr class="align-top">
                                            <td class="py-2 pr-3 font-bold text-slate-800">v{{ $recu->version }}</td>
                                            <td class="py-2 pr-3 text-xs text-slate-600">{{ $recu->depose_le?->format('d/m/Y H:i') }}</td>
                                            <td class="py-2 pr-3 break-all text-xs text-slate-700">{{ $recu->reference_paiement }}</td>
                                            <td class="py-2 pr-3 font-semibold tabular-nums text-slate-800">{{ $recu->montantDeclareFormate() }}</td>
                                            <td class="py-2 pr-3"><x-status-badge :statut="$recu->statut" /></td>
                                            <td class="py-2 pr-3 text-xs text-slate-600">{{ $recu->motif_refus ?? '—' }}</td>
                                            <td class="py-2 pr-3 text-right">
                                                <a class="text-xs font-bold text-primary-800 underline underline-offset-2"
                                                   href="{{ route('admin.finances.paiements.recu.telecharger', [$dossier, $recu]) }}">
                                                    Télécharger
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            </div>

            <aside class="space-y-4">
                <section class="ds-card p-5">
                    <h2 class="text-base font-black text-slate-900">Décision</h2>

                    @if($dossier->statut === \App\Modules\Finances\Models\DossierPaiement::RECU_DEPOSE)
                        <p class="mt-2 text-sm text-slate-600">
                            Le reçu est déposé mais n’a pas encore été pris en charge. Aucun paiement n’est confirmé.
                        </p>
                        <form method="post" action="{{ route('admin.finances.paiements.prendre', $dossier) }}" class="mt-3">
                            @csrf
                            <x-btn type="submit" variant="primary">Prendre en vérification</x-btn>
                        </form>
                    @elseif($dossier->statut === \App\Modules\Finances\Models\DossierPaiement::EN_VERIFICATION)
                        <p class="mt-2 text-sm text-slate-600">
                            Vérifiez le fichier, la référence, la date et le montant. La validation confirme le paiement de cette inscription.
                        </p>

                        <form method="post" action="{{ route('admin.finances.paiements.valider', $dossier) }}" class="mt-4 space-y-3">
                            @csrf
                            <x-textarea id="commentaire" name="commentaire" label="Commentaire de validation (optionnel)"
                                        rows="2">{{ old('commentaire') }}</x-textarea>
                            <p class="text-xs text-slate-500">
                                Obligatoire si le montant déclaré diffère du montant attendu.
                            </p>
                            <x-btn type="submit" variant="primary">Valider le reçu</x-btn>
                        </form>

                        <hr class="my-4 border-slate-200">

                        <form method="post" action="{{ route('admin.finances.paiements.refuser', $dossier) }}" class="space-y-3">
                            @csrf
                            <x-textarea id="motif" name="motif" label="Motif du refus" rows="3" required
                                        placeholder="Expliquez précisément pourquoi le reçu est refusé.">{{ old('motif') }}</x-textarea>
                            <x-btn type="submit" variant="danger">Refuser le reçu</x-btn>
                        </form>
                    @elseif($dossier->statut === \App\Modules\Finances\Models\DossierPaiement::VALIDE)
                        <div class="mt-3">
                            <x-alert type="success" titre="Paiement confirmé"
                                     :message="'Reçu validé le '.($dossier->decide_le?->format('d/m/Y H:i') ?? '—')" />
                        </div>
                    @elseif($dossier->statut === \App\Modules\Finances\Models\DossierPaiement::REFUSE)
                        <div class="mt-3">
                            <x-alert type="warning" titre="Reçu refusé"
                                     message="L’étudiant peut déposer un nouveau reçu. L’ancien reçu et son motif restent conservés dans l’historique." />
                        </div>
                    @else
                        <div class="mt-3">
                            <x-alert type="info" titre="Aucun reçu"
                                     message="Aucun reçu n’a été déposé. Aucune décision n’est possible tant que l’étudiant n’a pas déposé de fichier." />
                        </div>
                    @endif
                </section>

                <section class="ds-card p-5">
                    <h2 class="text-base font-black text-slate-900">Étudiant</h2>
                    <p class="mt-2 text-sm font-bold text-slate-900">
                        {{ $dossier->inscription?->etudiant?->nom }} {{ $dossier->inscription?->etudiant?->prenom }}
                    </p>
                    <p class="mt-1 text-xs text-slate-600">CIN : {{ $dossier->inscription?->etudiant?->cin ?? '—' }}</p>
                    <p class="mt-1 text-xs text-slate-600">
                        Faculté : {{ $dossier->inscription?->etudiant?->faculte?->nom ?? '—' }}
                    </p>
                    <p class="mt-1 text-xs text-slate-600">
                        Inscription : {{ $dossier->inscription?->anneeUniversitaire?->libelle ?? '—' }}
                        — {{ $dossier->inscription?->niveau ?? '—' }}
                    </p>
                </section>
            </aside>
        </div>
    </div>
@endsection
