@extends('layouts.app')

@section('title', 'Facture ' . $facture->reference)

@section('content')
    <div class="mx-auto max-w-4xl px-4 sm:px-6 py-8">
        <x-page-header :titre="'Facture ' . $facture->reference" :description="$facture->etudiant->nom_complet . ' · Net : ' . \App\Modules\Finances\Models\Facture::formater($facture->netCentimes()) . ' · Payé : ' . \App\Modules\Finances\Models\Facture::formater($facture->payeCentimes())"
            :fil="['Factures' => route('admin.finances.factures.index'), $facture->reference => '#']">
            <x-slot:actions><x-status-badge :statut="$facture->statut" /></x-slot:actions>
        </x-page-header>

        @if($errors->any())
            <div class="mt-4"><x-alert type="error">{{ $errors->first() }}</x-alert></div>
        @endif

        <section class="mt-4 ds-card p-5">
            <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Lignes</h2>
            <ul class="mt-2 divide-y divide-slate-100 text-sm">
                @foreach($facture->lignes as $ligne)
                    <li class="flex justify-between py-1.5"><span>{{ $ligne->libelle }}</span><span class="tabular-nums">{{ \App\Modules\Finances\Models\Facture::formater($ligne->montant_centimes) }}</span></li>
                @endforeach
            </ul>
        </section>

        <section class="mt-4 ds-card p-5">
            <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Paiements (reçu après confirmation uniquement)</h2>
            <ul class="mt-2 divide-y divide-slate-100 text-sm">
                @foreach($facture->paiements as $paiement)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span><strong>{{ $paiement->reference }}</strong> · {{ \App\Modules\Finances\Models\Facture::formater($paiement->montant_centimes) }} · {{ $paiement->methode }} · <x-status-badge :statut="$paiement->statut" /></span>
                        <span class="flex gap-1.5">
                            @if($paiement->statut === 'pending')
                                @can('finances.confirm')
                                    <form method="post" action="{{ route('admin.finances.confirmer', $paiement) }}" class="inline">
                                        @csrf
                                        <x-btn size="sm" type="submit">Confirmer</x-btn>
                                    </form>
                                @endcan
                            @endif
                            @if($paiement->estConfirme())
                                <x-btn size="sm" variant="secondary" href="{{ route('admin.finances.recu', $paiement) }}">Reçu PDF</x-btn>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
            @can('finances.manage')
                @if($facture->resteCentimes() > 0 && !in_array($facture->statut, ['cancelled', 'refunded'], true))
                    <form method="post" action="{{ route('admin.finances.payer', $facture) }}" class="mt-3 flex flex-wrap items-end gap-2">
                        @csrf
                        <div>
                            <label class="text-xs font-bold text-slate-600" for="montant">Montant (reste : {{ \App\Modules\Finances\Models\Facture::formater($facture->resteCentimes()) }})</label>
                            <input id="montant" name="montant" type="number" step="0.01" min="0.01" required class="mt-1 w-40 rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="text-xs font-bold text-slate-600" for="methode">Méthode</label>
                            <select id="methode" name="methode" class="mt-1 rounded-xl border border-slate-200 px-3 py-2 text-sm">
                                @foreach(\App\Modules\Finances\Models\Paiement::METHODES as $methode)
                                    <option value="{{ $methode }}">{{ $methode }}</option>
                                @endforeach
                            </select>
                        </div>
                        <x-btn size="sm" type="submit">Enregistrer</x-btn>
                    </form>
                @endif
            @endcan
        </section>
    </div>
@endsection
