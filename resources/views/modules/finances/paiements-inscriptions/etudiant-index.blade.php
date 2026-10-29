@extends('layouts.app')

@section('title', 'Mes paiements')

@section('content')
    <div class="paiements-page mx-auto max-w-6xl px-4 py-8 sm:px-6">
        <x-page-header
            titre="Mes paiements"
            description="Un dossier de paiement par inscription annuelle. Un reçu n’est jamais réutilisé d’une année à l’autre."
            :fil="['Espace étudiant' => route('etudiant.dashboard'), 'Mes paiements' => '#']" />

        <div class="paiements-note mt-4">
            <x-alert type="info" titre="Dépôt ≠ paiement confirmé"
                     message="Le dépôt d’un reçu lance une vérification par la scolarité. Votre paiement n’est confirmé qu’après validation. Vous pouvez suivre chaque étape et consulter l’historique de vos reçus." />
            <p class="mt-2 text-xs text-slate-500">
                Les factures et paiements historiques restent consultables séparément dans
                <a href="{{ route('etudiant.finances.index') }}" class="font-bold text-primary-800 underline underline-offset-2 hover:text-primary-600">Mes finances (facturation historique)</a>.
                Ce sont deux périmètres distincts : seul un reçu validé confirme le paiement de l’inscription.
            </p>
        </div>

        @if($lignes->isEmpty())
            <div class="paiement-empty mt-6 rounded-2xl border border-slate-200 bg-white p-8 text-center">
                <p class="text-sm font-bold text-slate-700">Aucune inscription annuelle n’est rattachée à votre dossier.</p>
                <p class="mt-1 text-xs text-slate-500">Contactez la scolarité si vous pensez qu’il s’agit d’une erreur.</p>
            </div>
        @else
            <div class="mt-6 space-y-4">
                @foreach($lignes as $ligne)
                    @php
                        $inscription = $ligne['inscription'];
                        $montant = $ligne['montant'];
                        $dernier = $ligne['dernier'];
                        $statut = $inscription->dossierPaiement?->statut ?? \App\Modules\Finances\Models\DossierPaiement::NON_PAYE;
                    @endphp
                    <article @class([
                        'ds-card p-5',
                        'paiement-card',
                        'paiement-card--valide' => $statut === \App\Modules\Finances\Models\DossierPaiement::VALIDE,
                        'paiement-card--attente' => in_array($statut, [\App\Modules\Finances\Models\DossierPaiement::RECU_DEPOSE, \App\Modules\Finances\Models\DossierPaiement::EN_VERIFICATION], true),
                        'paiement-card--refuse' => $statut === \App\Modules\Finances\Models\DossierPaiement::REFUSE,
                    ])>
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="min-w-0">
                                <h2 class="text-lg font-black text-slate-900">
                                    {{ $inscription->anneeUniversitaire?->libelle ?? 'Année non renseignée' }}
                                    <span class="text-slate-400">·</span>
                                    {{ $inscription->niveau ?? '—' }}
                                </h2>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ $inscription->programmeRef?->nom ?? $inscription->filiere ?? 'Programme non renseigné' }}
                                </p>
                            </div>
                            <x-status-badge :statut="$statut" />
                        </div>

                        <div class="paiement-metrics mt-4 grid gap-3 sm:grid-cols-3">
                            <div class="paiement-metric">
                                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Montant attendu</p>
                                @if($montant['centimes'] !== null)
                                    <p class="mt-0.5 text-lg font-black tabular-nums text-slate-900">
                                        {{ \App\Modules\Finances\Models\Facture::formater($montant['centimes']) }}
                                    </p>
                                    <p class="text-xs text-slate-500">{{ $montant['source_libelle'] }}</p>
                                @else
                                    <p class="mt-0.5 text-sm font-bold text-rose-700">Non configuré</p>
                                    <p class="text-xs text-slate-500">La scolarité doit définir le tarif officiel.</p>
                                @endif
                            </div>
                            <div class="paiement-metric">
                                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Dernier reçu</p>
                                @if($dernier)
                                    <p class="mt-0.5 text-sm font-bold text-slate-900">
                                        {{ $dernier->montantDeclareFormate() }}
                                        <span class="font-normal text-slate-500">· v{{ $dernier->version }}</span>
                                    </p>
                                    <p class="text-xs text-slate-500">
                                        {{ $dernier->date_paiement?->format('d/m/Y') }}
                                        · {{ $dernier->libelleStatut() }}
                                    </p>
                                @else
                                    <p class="mt-0.5 text-sm text-slate-500">Aucun reçu déposé</p>
                                @endif
                            </div>
                            <div class="paiement-metric sm:text-right">
                                <x-btn variant="primary" href="{{ route('etudiant.paiements.inscription', $inscription) }}">
                                    {{ $statut === \App\Modules\Finances\Models\DossierPaiement::NON_PAYE ? 'Déposer un reçu' : 'Voir le dossier' }}
                                </x-btn>
                            </div>
                        </div>

                        @if($statut === \App\Modules\Finances\Models\DossierPaiement::REFUSE && $dernier?->motif_refus)
                            <div class="mt-3">
                                <x-alert type="error" titre="Reçu refusé" :message="$dernier->motif_refus" />
                            </div>
                        @elseif($statut === \App\Modules\Finances\Models\DossierPaiement::EN_VERIFICATION || $statut === \App\Modules\Finances\Models\DossierPaiement::RECU_DEPOSE)
                            <div class="mt-3">
                                <x-alert type="warning" titre="Vérification en cours"
                                         message="Votre reçu est en cours de vérification. Aucun paiement n’est confirmé avant la décision de la scolarité." />
                            </div>
                        @elseif($statut === \App\Modules\Finances\Models\DossierPaiement::VALIDE)
                            <div class="mt-3">
                                <x-alert type="success" titre="Paiement confirmé"
                                         message="Votre reçu a été validé par la scolarité pour cette inscription." />
                            </div>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </div>
@endsection
