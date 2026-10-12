@extends('layouts.app')

@section('title', 'Centre documentaire')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Centre documentaire" description="Documents officiels : génération, vérification, révocation, lots." />

        <div class="mt-4 grid gap-4 sm:grid-cols-3 lg:grid-cols-6">
            <x-stat-card libelle="Valides" :valeur="$aEmettre" />
            <x-stat-card libelle="En échec" :valeur="$enEchec" />
            <x-stat-card libelle="Révoqués" :valeur="$revoques" />
            <x-stat-card libelle="Expirant < 30 j" :valeur="$expirant" />
            <x-stat-card libelle="À approuver" :valeur="$aApprouver" />
            <x-stat-card libelle="À émettre" :valeur="$aEmettre" />
        </div>

        <form method="GET" class="mt-4 flex gap-2">
            <x-input id="filtre-type" label="Type" name="type" :value="request('type')" placeholder="Type (ex. attestation_inscription)" />
            <x-input id="filtre-statut" label="Statut" name="statut" :value="request('statut')" placeholder="Statut" />
            <x-btn type="submit">Filtrer</x-btn>
        </form>

        <div class="mt-4 ds-card overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr><th class="text-left p-2">Numéro</th><th class="text-left p-2">Type</th><th class="text-left p-2">Statut</th><th class="text-left p-2">Émis le</th><th class="p-2"></th></tr></thead>
                <tbody>
                    @forelse($documents as $document)
                        <tr class="border-t">
                            <td class="p-2 font-mono">{{ $document->numero ?? $document->reference }}</td>
                            <td class="p-2">{{ $document->type }}</td>
                            <td class="p-2"><x-status-badge :statut="$document->statut" /></td>
                            <td class="p-2">{{ $document->emis_le?->format('d/m/Y') ?? $document->created_at?->format('d/m/Y') }}</td>
                            <td class="p-2 text-right"><a class="underline" href="{{ route('admin.documents-center.show', $document) }}">Ouvrir</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="p-4"><x-empty-state message="Aucun document — lancez une génération depuis la fiche étudiant." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $documents->links() }}</div>
    </div>
@endsection
