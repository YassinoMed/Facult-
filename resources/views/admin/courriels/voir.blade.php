@extends('layouts.app')

@section('title', 'Courriel — détail')

@section('content')
<div class="mx-auto max-w-4xl px-4 sm:px-6 py-8">
    <x-page-header titre="Courriel n°{{ $courriel->id }}" description="Détail technique expurgé — aucun contenu sensible."
        :fil="['Courriels' => route('admin.courriels.index')]" />

    @if(session('success'))
        <div class="mt-4"><x-alert type="success">{{ session('success') }}</x-alert></div>
    @endif
    @if(session('error'))
        <div class="mt-4"><x-alert type="error">{{ session('error') }}</x-alert></div>
    @endif

    <dl class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
        @foreach([
            'Statut' => $courriel->statut,
            'Catégorie' => $courriel->categorie,
            'Modèle' => $courriel->modele.' (v'.$courriel->version_modele.')',
            'Destinataire (masqué)' => $courriel->destinataire_masque,
            'Sujet' => $courriel->sujet,
            'Tentatives' => $courriel->tentatives,
            'Catégorie d’erreur' => $courriel->categorie_erreur ?? '—',
            'Référence métier' => $courriel->reference_metier ?? '—',
            'Corrélation' => $courriel->correlation_id ?? '—',
            'Acteur admin' => $courriel->acteur_admin_id ?? '—',
            'Créé le' => $courriel->created_at->format('d/m/Y H:i:s'),
            'Accepté le' => $courriel->envoye_le?->format('d/m/Y H:i:s') ?? '—',
        ] as $libelle => $valeur)
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400">{{ $libelle }}</dt>
                <dd class="mt-1 font-mono text-xs break-all">{{ $valeur }}</dd>
            </div>
        @endforeach
    </dl>

    @if($catalogue)
        <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-4 text-sm text-slate-600">
            <p class="font-bold text-slate-900">Règle du modèle</p>
            <p class="mt-1">{{ $catalogue['regle'] ?? '' }}</p>
        </div>
    @endif

    @if($courriel->peutEtreReessaye())
        @can('mail.retry')
        <form method="POST" action="{{ route('admin.courriels.reessayer', $courriel) }}" class="mt-4 rounded-2xl border border-slate-200 bg-white p-4 space-y-3">
            @csrf
            <p class="text-sm font-bold text-slate-900">Reprise encadrée (réémission réelle)</p>
            <p class="text-xs text-slate-500">Réservée aux modèles réémissibles, vers l’adresse d’origine uniquement. Les codes OTP ne sont jamais réémis : reprenez le parcours.</p>
            <div>
                <label for="destinataire-reprise" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Confirmer l’adresse d’origine *</label>
                <input id="destinataire-reprise" name="destinataire" type="email" required maxlength="254" autocomplete="off"
                       class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm">
            </div>
            <label class="flex items-start gap-2 text-sm font-bold text-slate-700">
                <input type="checkbox" name="confirmation" value="1" required class="mt-0.5 h-4 w-4">
                Je confirme la réémission vers cette adresse.
            </label>
            <button type="submit" class="rounded-xl bg-primary-600 px-5 py-2.5 text-xs font-bold text-white">Relancer</button>
        </form>
        @endcan
    @endif
</div>
@endsection
