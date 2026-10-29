@extends('layouts.app')

@section('title', 'Remboursements')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Remboursements" description="Demandes de remboursement : aucune suppression, transitions tracées."
            :fil="['Finances' => route('admin.finances.index'), 'Remboursements' => '#']" />

        @if(session('success'))
            <div class="mt-4"><x-alert type="success">{{ session('success') }}</x-alert></div>
        @endif
        @if($errors->any())
            <div class="mt-4"><x-alert type="error">{{ $errors->first() }}</x-alert></div>
        @endif

        <div class="mt-4 ds-card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-4 py-2">Demande</th>
                        <th class="px-4 py-2">Étudiant</th>
                        <th class="px-4 py-2">Facture</th>
                        <th class="px-4 py-2 text-right">Montant</th>
                        <th class="px-4 py-2">Statut</th>
                        <th class="px-4 py-2">Motif</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($remboursements as $remboursement)
                        @php($paiement = $remboursement->paiement)
                        @php($facture = $paiement?->facture)
                        <tr>
                            <td class="px-4 py-2 font-mono font-bold">#{{ $remboursement->id }}</td>
                            <td class="px-4 py-2">{{ $facture?->etudiant?->nom_complet ?? '—' }}</td>
                            <td class="px-4 py-2">
                                @if($facture)
                                    <a href="{{ route('admin.finances.factures.show', $facture) }}"
                                       class="font-mono text-xs font-bold text-primary-800 underline underline-offset-2">{{ $facture->reference }}</a>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ \App\Modules\Finances\Models\Facture::formater($remboursement->montant_centimes) }}</td>
                            <td class="px-4 py-2"><x-status-badge :statut="$remboursement->statut" /></td>
                            <td class="px-4 py-2 text-slate-600">{{ $remboursement->motif }}</td>
                            <td class="px-4 py-2 text-right">
                                @can('finances.manage')
                                    <span class="flex flex-wrap justify-end gap-1.5">
                                        @foreach($transitions[$remboursement->statut] ?? [] as $cible)
                                            <form method="post" action="{{ route('admin.finances.remboursements.traiter', $remboursement) }}" class="inline">
                                                @csrf
                                                <input type="hidden" name="statut" value="{{ $cible }}">
                                                <x-btn size="sm" :variant="$cible === 'rejected' ? 'secondary' : 'primary'" type="submit">
                                                    {{ ['approved' => 'Approuver', 'rejected' => 'Rejeter', 'processed' => 'Marquer traité', 'completed' => 'Clôturer'][$cible] ?? $cible }}
                                                </x-btn>
                                            </form>
                                        @endforeach
                                    </span>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-6 text-center text-slate-500">Aucun remboursement.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="px-4 py-3">{{ $remboursements->links() }}</div>
        </div>
    </div>
@endsection
