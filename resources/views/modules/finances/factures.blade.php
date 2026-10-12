@extends('layouts.app')

@section('title', 'Factures')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Factures" description="Jamais supprimées : annulation tracée."
            :fil="['Finances' => route('admin.finances.index'), 'Factures' => '#']" />

        <div class="mt-4 ds-card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500">
                    <tr><th class="px-4 py-2">Référence</th><th class="px-4 py-2">Étudiant</th><th class="px-4 py-2 text-right">Net</th><th class="px-4 py-2">Statut</th><th class="px-4 py-2"></th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($factures as $facture)
                        <tr>
                            <td class="px-4 py-2 font-mono font-bold">{{ $facture->reference }}</td>
                            <td class="px-4 py-2">{{ $facture->etudiant->nom_complet }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ \App\Modules\Finances\Models\Facture::formater($facture->netCentimes()) }}</td>
                            <td class="px-4 py-2"><x-status-badge :statut="$facture->statut" /></td>
                            <td class="px-4 py-2 text-right">
                                <a href="{{ route('admin.finances.factures.show', $facture) }}" class="text-xs font-bold text-primary-800 underline underline-offset-2">Détail</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-6 text-center text-slate-500">Aucune facture.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="px-4 py-3">{{ $factures->links() }}</div>
        </div>
    </div>
@endsection
