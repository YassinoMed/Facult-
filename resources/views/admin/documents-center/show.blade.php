@extends('layouts.app')

@section('title', 'Document ' . ($document->numero ?? $document->reference))

@section('content')
    <div class="mx-auto max-w-4xl px-4 sm:px-6 py-8">
        <x-page-header :titre="'Document ' . ($document->numero ?? $document->reference)" :description="$document->type . ' — ' . $document->statut" />

        <div class="mt-4 ds-card p-5">
            <dl class="grid sm:grid-cols-2 gap-2 text-sm">
                <div><dt class="text-slate-500">Statut</dt><dd><x-status-badge :statut="$document->statut" /></dd></div>
                <div><dt class="text-slate-500">Code de vérification</dt><dd class="font-mono">{{ $document->code_verification }}</dd></div>
                <div><dt class="text-slate-500">Émis le</dt><dd>{{ $document->emis_le?->format('d/m/Y H:i') ?? '—' }}</dd></div>
                <div><dt class="text-slate-500">Approuvé le</dt><dd>{{ $document->approuve_le?->format('d/m/Y H:i') ?? '—' }}</dd></div>
                <div><dt class="text-slate-500">Valide jusqu'au</dt><dd>{{ $document->valide_jusquau?->format('d/m/Y') ?? '—' }}</dd></div>
                <div><dt class="text-slate-500">Étudiant</dt><dd>{{ $document->dossier?->etudiant?->prenom }} {{ $document->dossier?->etudiant?->nom }}</dd></div>
                <div><dt class="text-slate-500">Hash SHA-256</dt><dd class="font-mono text-xs break-all">{{ $document->sha256 }}</dd></div>
                @if($document->statut === 'revoque')
                    <div><dt class="text-slate-500">Révoqué le</dt><dd class="text-rose-700 font-medium">{{ $document->revoque_le?->format('d/m/Y H:i') ?? '—' }}</dd></div>
                    <div><dt class="text-slate-500">Motif de révocation</dt><dd class="text-rose-700 font-medium">{{ $document->motif_revocation ?? 'Non spécifié' }}</dd></div>
                    @if($document->revoquePar)
                        <div><dt class="text-slate-500">Révoqué par</dt><dd class="font-medium">{{ $document->revoquePar->name }}</dd></div>
                    @endif
                @endif
            </dl>

            @if($document->statut === 'revoque')
                <div class="mt-4">
                    <x-alert type="warning">
                        <strong>Document révoqué</strong>
                        @if($document->revoque_le) le {{ $document->revoque_le->format('d/m/Y à H:i') }} @endif
                        @if($document->motif_revocation) — Motif : « {{ $document->motif_revocation }} » @endif.
                        Ce document officiel est caduc. Conformément aux règles de sécurité, son impression et son téléchargement sont désactivés.
                    </x-alert>
                </div>
            @endif

            @if($remplace)
                <div class="mt-4">
                    <x-alert type="info">Remplace le document {{ $remplace->numero ?? $remplace->reference }} (consultable, marqué remplacé).</x-alert>
                </div>
            @endif

            @if($chaine->isNotEmpty())
                <p class="mt-2 text-sm">Remplacé par : @foreach($chaine as $suivant)<a class="underline" href="{{ route('admin.documents-center.show', $suivant) }}">{{ $suivant->numero ?? $suivant->reference }}</a>@endforeach</p>
            @endif

            @if($document->statut !== 'revoque')
                <div class="mt-4 flex flex-wrap gap-2">
                    <x-btn variant="secondary" href="{{ route('admin.documents-center.telecharger', $document) }}">Télécharger</x-btn>
                    <x-btn target="_blank" rel="noopener" href="{{ route('admin.documents-center.imprimer', $document) }}">Imprimer</x-btn>
                </div>
            @else
                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-100 text-slate-500 cursor-not-allowed border border-slate-200" title="Document révoqué : impression désactivée">
                        Impression désactivée (document révoqué)
                    </span>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-100 text-slate-500 cursor-not-allowed border border-slate-200" title="Document révoqué : téléchargement désactivé">
                        Téléchargement désactivé (document révoqué)
                    </span>
                </div>
            @endif

            @error('document')
                <div class="mt-4"><x-alert type="error">{{ $message }}</x-alert></div>
            @enderror
            @if($document->statut === 'valide')
                @can('document.approve')
                    @if($document->approuve_le === null)
                        <form method="POST" action="{{ route('admin.documents-center.approuver', $document) }}" class="mt-4">
                            @csrf
                            <x-btn type="submit">Approuver</x-btn>
                        </form>
                    @endif
                @endcan
                @can('document.issue')
                    @if($document->approuve_le !== null && $document->emis_le === null)
                        <form method="POST" action="{{ route('admin.documents-center.emettre', $document) }}" class="mt-4">
                            @csrf
                            <x-btn type="submit">Émettre</x-btn>
                        </form>
                    @endif
                @endcan
                @can('document.send')
                    @if($document->emis_le !== null)
                        <form method="POST" action="{{ route('admin.documents-center.envoyer', $document) }}" class="mt-4">
                            @csrf
                            <x-btn type="submit" variant="secondary">Transmettre à l’étudiant</x-btn>
                        </form>
                    @endif
                @endcan
            @endif

            @can('document.revoke')
                @if($document->statut === 'valide')
                    <form method="POST" action="{{ route('admin.documents-center.revoquer', $document) }}" class="mt-4 flex gap-2">
                        @csrf
                        <x-input id="motif-revocation" label="Motif de révocation" name="motif" placeholder="Motif (obligatoire, min. 10 caractères)" required />
                        <x-btn type="submit">Révoquer</x-btn>
                    </form>
                @endif
            @endcan
        </div>

        <h2 class="mt-6 font-bold">Historique des événements</h2>
        <ul class="mt-2 text-sm space-y-1">
            @forelse($evenements as $evenement)
                <li>{{ $evenement->created_at?->format('d/m/Y H:i') }} — {{ $evenement->evenement }}</li>
            @empty
                <li class="text-slate-500">Aucun événement.</li>
            @endforelse
        </ul>
    </div>
@endsection
