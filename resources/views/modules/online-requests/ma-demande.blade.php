@extends('layouts.app')

@section('title', 'Demande ' . $demande->reference . ' — Espace Étudiant')

@section('content')
    <div class="mx-auto max-w-3xl px-4 sm:px-6 py-8 sm:py-12">
        <a href="{{ route('etudiant.demandes.index') }}" class="text-xs font-bold text-primary-800 underline underline-offset-2">← Mes demandes</a>

        @if(session('success'))
            <div class="mt-3 rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-xs font-bold text-emerald-900" role="status">✓ {{ session('success') }}</div>
        @endif
        @if($errors->any())
            <div class="mt-3 rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-xs text-rose-800" role="alert">
                <ul class="list-disc list-inside">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="mt-3 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">{{ $demande->type->name }}</p>
                    <h1 class="mt-1 font-mono text-2xl font-black text-slate-900">{{ $demande->reference }}</h1>
                    <p class="mt-1 text-sm font-bold text-slate-900">{{ $demande->objet }}</p>
                    @if($demande->description)
                        <p class="mt-1 text-sm text-slate-600">{{ $demande->description }}</p>
                    @endif
                </div>
                <x-status-badge :statut="$demande->status" />
            </div>

            @if($demande->status === 'rejected' && $demande->rejection_reason)
                <p class="mt-3 rounded-xl border border-rose-300 bg-rose-50 px-4 py-2.5 text-xs font-semibold text-rose-800">
                    Motif du rejet : {{ $demande->rejection_reason }}
                </p>
            @endif
            @if($demande->status === 'waiting_student')
                <p class="mt-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-2.5 text-xs font-semibold text-amber-900">
                    La scolarité attend une pièce complémentaire — déposez-la ci-dessous.
                </p>
            @endif

            <div class="mt-4 flex flex-wrap gap-2 text-xs font-bold" role="group" aria-label="Actions">
                @if($demande->status === 'draft')
                    <form method="POST" action="{{ route('etudiant.demandes.submit', $demande) }}" class="inline">
                        @csrf
                        <button type="submit" class="rounded-xl bg-primary-800 px-5 py-3 text-sm text-white hover:bg-primary-700 min-h-[48px]">Soumettre la demande</button>
                    </form>
                @endif
                @if(in_array($demande->status, ['draft', 'submitted'], true))
                    <form method="POST" action="{{ route('etudiant.demandes.cancel', $demande) }}" class="inline">
                        @csrf
                        <button type="submit" class="rounded-xl border border-slate-300 px-5 py-3 text-slate-700 hover:bg-slate-50 min-h-[48px]">Annuler</button>
                    </form>
                @endif
                @if($demande->documents->isNotEmpty())
                    <a href="{{ route('etudiant.demandes.document.download', $demande) }}"
                       class="inline-flex items-center rounded-xl bg-emerald-700 px-5 py-3 text-sm text-white hover:bg-emerald-600 min-h-[48px]">Télécharger le document</a>
                @endif
            </div>
        </div>

        <section aria-label="Pièces justificatives" class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <h2 class="px-5 py-3 border-b border-slate-100 text-sm font-black text-slate-900">Pièces ({{ $demande->pieces->count() }})</h2>
            @if($demande->pieces->isNotEmpty())
                <ul class="divide-y divide-slate-100">
                    @foreach($demande->pieces as $piece)
                        <li class="px-5 py-3 text-sm font-bold text-slate-800">{{ $piece->original_filename }}</li>
                    @endforeach
                </ul>
            @else
                <div class="p-5"><x-empty-state message="Aucune pièce jointe." /></div>
            @endif
            @if(in_array($demande->status, ['draft', 'waiting_student'], true))
                <form method="POST" action="{{ route('etudiant.demandes.pieces.store', $demande) }}" enctype="multipart/form-data"
                      class="flex flex-col sm:flex-row gap-2 border-t border-slate-100 px-5 py-4">
                    @csrf
                    <input type="file" name="piece" required accept=".pdf,.jpg,.jpeg,.png,.webp" aria-label="Déposer un fichier"
                           class="flex-1 rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    <button type="submit" class="rounded-xl bg-primary-800 px-5 py-2.5 text-sm font-bold text-white hover:bg-primary-700 min-h-[44px]">Déposer</button>
                </form>
            @endif
        </section>

        @include('modules.online-requests._messages', ['action' => route('etudiant.demandes.messages.repondre', $demande), 'peutEcrire' => true])
    </div>
@endsection
