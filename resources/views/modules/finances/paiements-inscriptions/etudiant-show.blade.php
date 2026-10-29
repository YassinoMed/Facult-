@extends('layouts.app')

@section('title', 'Paiement de l’inscription')

@section('content')
    <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6">
        @php
            $statut = $dossier?->statut ?? \App\Modules\Finances\Models\DossierPaiement::NON_PAYE;
        @endphp

        <x-page-header
            titre="Paiement de l’inscription"
            :description="($inscription->anneeUniversitaire?->libelle ?? '').' — '.($inscription->niveau ?? '').' — '.($inscription->programmeRef?->nom ?? $inscription->filiere ?? '')"
            :fil="['Mes paiements' => route('etudiant.paiements.index'), 'Dossier' => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('etudiant.paiements.index') }}">Retour à mes paiements</x-btn>
            </x-slot:actions>
        </x-page-header>

        @if(session('success'))
            <div class="mt-4"><x-alert type="success" :message="session('success')" /></div>
        @endif

        @if($errors->any())
            <div role="alert"
                 class="mt-4 rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-xs text-rose-900 sm:text-sm">
                <p class="font-black">✕ Le dépôt n’a pas été enregistré</p>
                <ul class="mt-1 list-disc pl-5">
                    @foreach($errors->all() as $erreur)
                        <li>{{ $erreur }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($statut === \App\Modules\Finances\Models\DossierPaiement::REFUSE)
            <div class="mt-4">
                <x-alert type="error" titre="Reçu refusé"
                         :message="$dernier?->motif_refus ?? 'Le reçu a été refusé. Consultez le motif et déposez un nouveau reçu conforme.'" />
            </div>
        @elseif($statut === \App\Modules\Finances\Models\DossierPaiement::EN_VERIFICATION || $statut === \App\Modules\Finances\Models\DossierPaiement::RECU_DEPOSE)
            <div class="mt-4">
                <x-alert type="warning" titre="Reçu en cours de vérification"
                         message="Votre reçu a bien été déposé. La scolarité vérifie le montant, la référence et la date. Aucun paiement n’est confirmé avant sa validation." />
            </div>
        @elseif($statut === \App\Modules\Finances\Models\DossierPaiement::VALIDE)
            <div class="mt-4">
                <x-alert type="success" titre="Paiement confirmé"
                         :message="'Votre reçu a été validé le '.($dossier?->decide_le?->format('d/m/Y H:i') ?? '—').'.'" />
            </div>
        @else
            <div class="mt-4">
                <x-alert type="info" titre="Aucun paiement confirmé"
                         message="Déposez votre reçu ci-dessous. Le dépôt ne vaut pas paiement : il sera vérifié par la scolarité." />
            </div>
        @endif

        <div class="mt-5 grid gap-4 sm:grid-cols-3">
            <div class="ds-card p-4">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Statut</p>
                <div class="mt-2"><x-status-badge :statut="$statut" /></div>
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
                    <p class="mt-0.5 text-xs text-slate-500">La scolarité doit définir le tarif officiel.</p>
                @endif
            </div>
            <div class="ds-card p-4">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Échéance</p>
                <p class="mt-1 text-xl font-black text-slate-900">
                    {{ $montant['echeance']?->format('d/m/Y') ?? 'Non configurée' }}
                </p>
                <p class="mt-0.5 text-xs text-slate-500">Issue de la facture officielle, si elle existe</p>
            </div>
        </div>

        @if($montant['details'] !== [])
            <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-4">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Détail des frais</p>
                <ul class="mt-2 divide-y divide-slate-100 text-sm">
                    @foreach($montant['details'] as $detail)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <span class="text-slate-700"><strong>{{ $detail['code'] }}</strong> — {{ $detail['libelle'] }}</span>
                            <span class="font-bold tabular-nums text-slate-900">
                                {{ \App\Modules\Finances\Models\Facture::formater((int) $detail['montant_centimes']) }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="mt-4">
            <x-alert type="info" titre="Instructions" :message="config('inscriptions-paiements.instructions')" />
        </div>

        @if($dossier === null || $dossier->peutDeposerRecu())
            <section class="ds-card mt-5 p-5">
                <h2 class="text-base font-black text-slate-900">
                    {{ $statut === \App\Modules\Finances\Models\DossierPaiement::REFUSE ? 'Déposer un nouveau reçu' : 'Déposer mon reçu' }}
                </h2>
                <p class="mt-1 text-sm text-slate-600">
                    Formats acceptés : PDF, JPG, PNG ou WEBP. Taille maximale :
                    {{ number_format(((int) config('student-dossiers.taille_max', 10 * 1024 * 1024)) / (1024 * 1024), 0, ',', ' ') }} Mo.
                </p>

                <form method="post" action="{{ route('etudiant.paiements.recus.store', $inscription) }}"
                      enctype="multipart/form-data" class="mt-4 space-y-4">
                    @csrf

                    <x-input id="recu" name="recu" type="file" label="Reçu officiel" required
                             accept=".pdf,.jpg,.jpeg,.png,.webp" />

                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-input id="montant" name="montant" type="number" step="0.01" min="0.01"
                                 label="Montant payé (DH)" required
                                 :value="old('montant')"
                                 placeholder="{{ $montant['centimes'] !== null ? number_format($montant['centimes'] / 100, 2, '.', '') : '0.00' }}" />

                        <x-input id="date_paiement" name="date_paiement" type="date" label="Date du paiement" required
                                 :value="old('date_paiement')" max="{{ now()->format('Y-m-d') }}" />

                        <div class="sm:col-span-2">
                            <x-input id="reference_paiement" name="reference_paiement" label="Référence du paiement" required
                                     :value="old('reference_paiement')"
                                     placeholder="Référence bancaire ou numéro du reçu" />
                        </div>
                    </div>

                    <label class="flex items-start gap-2 text-xs text-slate-600">
                        <input type="checkbox" name="engagement" value="1" required
                               class="mt-0.5 h-4 w-4 rounded border-slate-300 text-primary-800 focus:ring-primary-500">
                        <span>
                            Je certifie que ce reçu correspond à un paiement réel effectué pour cette inscription annuelle.
                            Je comprends qu’il sera vérifié et qu’un faux reçu peut entraîner des poursuites.
                        </span>
                    </label>
                    @error('engagement')
                        <p class="text-xs font-semibold text-rose-700" role="alert">⚠ {{ $message }}</p>
                    @enderror

                    <x-btn type="submit" variant="primary">Déposer le reçu</x-btn>
                </form>
            </section>
        @endif

        <section class="ds-card mt-5 p-5">
            <h2 class="text-base font-black text-slate-900">Reçu le plus récent</h2>

            @if($dernier === null)
                <p class="mt-3 text-sm text-slate-500">Aucun reçu n’a été déposé pour cette inscription.</p>
            @else
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Version</p>
                        <p class="mt-0.5 text-sm font-bold text-slate-900">v{{ $dernier->version }}</p>
                    </div>
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Statut</p>
                        <div class="mt-1"><x-status-badge :statut="$dernier->statut" /></div>
                    </div>
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Montant déclaré</p>
                        <p class="mt-0.5 text-sm font-black tabular-nums text-slate-900">{{ $dernier->montantDeclareFormate() }}</p>
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
                </div>

                @if($dernier->motif_refus)
                    <div class="mt-3"><x-alert type="error" titre="Motif du refus" :message="$dernier->motif_refus" /></div>
                @endif

                @if($dernier->commentaire)
                    <div class="mt-3"><x-alert type="info" titre="Commentaire de la scolarité" :message="$dernier->commentaire" /></div>
                @endif

                <div class="mt-4 flex flex-wrap gap-2">
                    <x-btn variant="secondary" target="_blank" rel="noopener"
                           href="{{ route('etudiant.paiements.recus.apercu', [$inscription, $dernier]) }}">
                        Aperçu
                    </x-btn>
                    <x-btn variant="secondary"
                           href="{{ route('etudiant.paiements.recus.telecharger', [$inscription, $dernier]) }}">
                        Télécharger
                    </x-btn>
                </div>
            @endif
        </section>

        <section class="ds-card mt-5 p-5">
            <h2 class="text-base font-black text-slate-900">Historique des reçus et décisions</h2>

            @if($recus->isEmpty())
                <p class="mt-3 text-sm text-slate-500">Aucun reçu dans l’historique.</p>
            @else
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="text-left text-[11px] font-bold uppercase tracking-wider text-slate-500">
                            <tr>
                                <th scope="col" class="py-2 pr-3">Version</th>
                                <th scope="col" class="py-2 pr-3">Déposé le</th>
                                <th scope="col" class="py-2 pr-3">Référence</th>
                                <th scope="col" class="py-2 pr-3">Montant</th>
                                <th scope="col" class="py-2 pr-3">Statut</th>
                                <th scope="col" class="py-2 pr-3">Motif / commentaire</th>
                                <th scope="col" class="py-2 pr-3 text-right">Fichier</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($recus as $recu)
                                <tr class="align-top">
                                    <td class="py-2 pr-3 font-bold text-slate-800">v{{ $recu->version }}</td>
                                    <td class="py-2 pr-3 text-xs text-slate-600">{{ $recu->depose_le?->format('d/m/Y H:i') }}</td>
                                    <td class="py-2 pr-3 break-all text-xs text-slate-700">{{ $recu->reference_paiement }}</td>
                                    <td class="py-2 pr-3 font-semibold tabular-nums text-slate-800">{{ $recu->montantDeclareFormate() }}</td>
                                    <td class="py-2 pr-3"><x-status-badge :statut="$recu->statut" /></td>
                                    <td class="py-2 pr-3 text-xs text-slate-600">{{ $recu->motif_refus ?? $recu->commentaire ?? '—' }}</td>
                                    <td class="py-2 pr-3 text-right">
                                        <a class="text-xs font-bold text-primary-800 underline underline-offset-2"
                                           href="{{ route('etudiant.paiements.recus.telecharger', [$inscription, $recu]) }}">
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
@endsection
