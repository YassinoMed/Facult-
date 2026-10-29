@extends('layouts.app')

@section('title', 'Reçus d’inscription')

@section('content')
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6">
        <x-page-header
            titre="Reçus d’inscription"
            description="Vérification des reçus déposés pour chaque inscription annuelle. Un dépôt n’est jamais un paiement confirmé."
            :fil="['Finances' => route('admin.finances.index'), 'Reçus d’inscription' => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('admin.finances.index') }}">Tableau de bord</x-btn>
            </x-slot:actions>
        </x-page-header>

        @if(session('success'))
            <div class="mt-4"><x-alert type="success" :message="session('success')" /></div>
        @endif

        @if($errors->has('paiement'))
            <div class="mt-4"><x-alert type="error" :message="$errors->first('paiement')" /></div>
        @endif

        <nav aria-label="Filtres par statut" class="mt-5 flex flex-wrap gap-2">
            @php
                $onglets = [
                    'a_traiter' => 'À traiter',
                    'RECU_DEPOSE' => 'Reçus déposés',
                    'EN_VERIFICATION' => 'En vérification',
                    'REFUSE' => 'Refusés',
                    'VALIDE' => 'Validés',
                    'NON_PAYE' => 'Non payés',
                    'tous' => 'Tous',
                ];
            @endphp
            @foreach($onglets as $code => $libelle)
                <a href="{{ route('admin.finances.paiements.index', ['statut' => $code]) }}"
                   @if($filtre === $code) aria-current="page" @endif
                   class="inline-flex items-center gap-2 rounded-full border px-3.5 py-2 text-xs font-bold transition focus:outline-none focus-visible:ring-4 focus-visible:ring-primary-500/20
                          {{ $filtre === $code ? 'border-primary-800 bg-primary-800 text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">
                    {{ $libelle }}
                    <span class="rounded-full px-1.5 py-0.5 text-[10px] tabular-nums {{ $filtre === $code ? 'bg-white/20' : 'bg-slate-100 text-slate-600' }}">
                        {{ $compteurs[$code] ?? 0 }}
                    </span>
                </a>
            @endforeach
        </nav>

        <form method="get" action="{{ route('admin.finances.paiements.index') }}"
              class="mt-4 grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:grid-cols-[1fr_14rem_auto] sm:items-end">
            <input type="hidden" name="statut" value="{{ $filtre }}">
            <x-input id="q" name="q" label="Rechercher un étudiant" :value="request('q')"
                     placeholder="Nom, prénom ou CIN" />
            <x-select id="annee" name="annee" label="Année universitaire" :valeur="request('annee')"
                      :options="$annees->pluck('libelle', 'id')->all()" vide="Toutes les années" />
            <x-btn type="submit" variant="secondary">Filtrer</x-btn>
        </form>

        <div class="mt-5 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            @if($lignes->isEmpty())
                <div class="p-8 text-center">
                    <p class="text-sm font-bold text-slate-700">Aucun dossier dans ce filtre.</p>
                    <p class="mt-1 text-xs text-slate-500">Les nouveaux reçus apparaissent ici dès leur dépôt.</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-[11px] font-bold uppercase tracking-wider text-slate-500">
                            <tr>
                                <th scope="col" class="px-4 py-3">Étudiant</th>
                                <th scope="col" class="px-4 py-3">Inscription</th>
                                <th scope="col" class="px-4 py-3">Montant attendu</th>
                                <th scope="col" class="px-4 py-3">Dernier reçu</th>
                                <th scope="col" class="px-4 py-3">Statut</th>
                                <th scope="col" class="px-4 py-3 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($lignes as $ligne)
                                @php
                                    $dossier = $ligne['dossier'];
                                    $montant = $ligne['montant'];
                                    $dernier = $ligne['dernier'];
                                    $etudiant = $dossier->inscription?->etudiant;
                                @endphp
                                <tr class="align-top hover:bg-slate-50/70">
                                    <td class="px-4 py-3">
                                        <p class="font-bold text-slate-900">
                                            {{ $etudiant?->nom }} {{ $etudiant?->prenom }}
                                        </p>
                                        <p class="mt-0.5 text-xs text-slate-500">
                                            CIN {{ $etudiant?->cin ?? '—' }}
                                            @if($etudiant?->faculte)
                                                · {{ $etudiant->faculte->code ?? $etudiant->faculte->nom }}
                                            @endif
                                        </p>
                                    </td>
                                    <td class="px-4 py-3">
                                        <p class="font-semibold text-slate-800">
                                            {{ $dossier->inscription?->anneeUniversitaire?->libelle ?? '—' }}
                                        </p>
                                        <p class="mt-0.5 text-xs text-slate-500">
                                            {{ $dossier->inscription?->niveau ?? '—' }}
                                            @if($dossier->inscription?->programmeRef)
                                                · {{ $dossier->inscription->programmeRef->nom }}
                                            @endif
                                        </p>
                                    </td>
                                    <td class="px-4 py-3">
                                        @if($montant['centimes'] !== null)
                                            <p class="font-bold tabular-nums text-slate-900">
                                                {{ \App\Modules\Finances\Models\Facture::formater($montant['centimes']) }}
                                            </p>
                                            <p class="mt-0.5 text-xs text-slate-500">{{ $montant['source_libelle'] }}</p>
                                        @else
                                            <p class="font-bold text-rose-700">Non configuré</p>
                                            <p class="mt-0.5 text-xs text-slate-500">Aucun tarif officiel</p>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        @if($dernier)
                                            <p class="font-semibold tabular-nums text-slate-800">
                                                {{ $dernier->montantDeclareFormate() }}
                                            </p>
                                            <p class="mt-0.5 text-xs text-slate-500">
                                                {{ $dernier->date_paiement?->format('d/m/Y') }}
                                                · v{{ $dernier->version }}
                                                · {{ $dernier->reference_paiement }}
                                            </p>
                                        @else
                                            <span class="text-xs text-slate-400">Aucun reçu</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <x-status-badge :statut="$dossier->statut" />
                                        <p class="mt-1 text-[11px] text-slate-500">
                                            {{ $dossier->updated_at?->format('d/m/Y H:i') }}
                                        </p>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <x-btn size="sm" variant="secondary"
                                               href="{{ route('admin.finances.paiements.show', $dossier) }}">
                                            Ouvrir
                                        </x-btn>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="mt-4">{{ $dossiers->links() }}</div>
    </div>
@endsection
