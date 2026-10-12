@extends('layouts.app')

@section('title', 'Demande ' . $demande->reference . ' — Scolarité')

@section('content')
    <div class="mx-auto max-w-5xl px-4 sm:px-6 py-8 sm:py-10">
        <a href="{{ route('admin.demandes.index') }}" class="text-xs font-bold text-primary-800 underline underline-offset-2 hover:text-primary-600">← Registre des demandes</a>

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
                    <p class="mt-1 text-sm text-slate-600">
                        <strong class="text-slate-900">{{ $demande->etudiant->nom_complet }}</strong>
                        · <span class="font-mono">{{ $demande->etudiant->cin }}</span>
                        · {{ $demande->etudiant->faculte?->nom }} · {{ $demande->etudiant->niveau }}
                    </p>
                    <p class="mt-1 text-xs text-slate-500">
                        Créée le {{ $demande->created_at->format('d/m/Y H:i') }}
                        @if($demande->echeance()) · Échéance : <strong class="{{ $demande->enRetard() ? 'text-rose-700' : 'text-slate-700' }}">{{ $demande->echeance()->format('d/m/Y') }}</strong>
                            @php($joursOuvres = app(\App\Modules\OnlineRequests\Services\SlaService::class)->joursOuvresRestants($demande))
                            @if($joursOuvres !== null)<span> ({{ $joursOuvres }} j ouvrés restants)</span>@endif
                        @endif
                        @if($demande->responsable) · Responsable : {{ $demande->responsable->name }} @endif
                    </p>
                </div>
                <x-status-badge :statut="$demande->status" />
            </div>

            <div class="mt-4 rounded-xl bg-slate-50 border border-slate-200 p-4 text-sm">
                <h2 class="text-xs font-bold uppercase tracking-wider text-slate-500">Objet</h2>
                <p class="mt-1 font-bold text-slate-900">{{ $demande->objet }}</p>
                @if($demande->description)
                    <p class="mt-2 text-slate-700">{{ $demande->description }}</p>
                @endif
                @if(!empty($demande->form_data))
                    <dl class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                        @foreach($demande->form_data as $cle => $valeur)
                            <div class="flex justify-between gap-2 border-b border-slate-100 pb-1">
                                <dt class="text-slate-500">{{ $cle }}</dt>
                                <dd class="font-bold text-slate-900 text-right">{{ $valeur }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
                @if($demande->status === 'rejected' && $demande->rejection_reason)
                    <p class="mt-3 text-xs font-semibold text-rose-700">Motif du rejet : {{ $demande->rejection_reason }}</p>
                @endif
            </div>

            {{-- Actions de workflow (transitions autorisées uniquement) --}}
            <div class="mt-4 flex flex-wrap gap-2 text-xs font-bold" role="group" aria-label="Actions de traitement">
                @can('demandes.process', $demande)
                    @if(in_array($demande->status, ['submitted'], true))
                        <form method="POST" action="{{ route('admin.demandes.recevoir', $demande) }}" class="inline">@csrf @method('PATCH')
                            <button type="submit" class="rounded-xl bg-primary-800 px-4 py-2.5 text-white hover:bg-primary-700 min-h-[44px]">Prendre en charge</button>
                        </form>
                    @endif
                    @if(in_array($demande->status, ['received'], true))
                        <form method="POST" action="{{ route('admin.demandes.examiner', $demande) }}" class="inline">@csrf @method('PATCH')
                            <button type="submit" class="rounded-xl bg-primary-800 px-4 py-2.5 text-white hover:bg-primary-700 min-h-[44px]">Examiner</button>
                        </form>
                    @endif
                    @if(in_array($demande->status, ['approved'], true))
                        <form method="POST" action="{{ route('admin.demandes.terminer', $demande) }}" class="inline">@csrf @method('PATCH')
                            <button type="submit" class="rounded-xl border-2 border-emerald-300 px-4 py-2.5 text-emerald-800 hover:bg-emerald-50 min-h-[44px]">Clôturer</button>
                        </form>
                    @endif
                @endcan
                @can('demandes.request_documents', $demande)
                    @if($demande->status === 'in_review')
                        <form method="POST" action="{{ route('admin.demandes.demander-piece', $demande) }}" class="flex flex-wrap items-center gap-1.5">
                            @csrf @method('PATCH')
                            <input type="text" name="commentaire" required maxlength="500" placeholder="Pièce attendue…"
                                   class="rounded-xl border border-slate-300 px-3 py-2.5 text-xs font-normal min-w-[220px]">
                            <button type="submit" class="rounded-xl border border-amber-300 px-4 py-2.5 text-amber-800 hover:bg-amber-50 min-h-[44px]">Demander une pièce</button>
                        </form>
                    @endif
                @endcan
                @can('demandes.approve', $demande)
                    @if(in_array($demande->status, ['in_review', 'waiting_student'], true))
                        <form method="POST" action="{{ route('admin.demandes.approuver', $demande) }}" class="inline">@csrf @method('PATCH')
                            <button type="submit" class="rounded-xl bg-emerald-700 px-4 py-2.5 text-white hover:bg-emerald-600 min-h-[44px]">Approuver</button>
                        </form>
                    @endif
                @endcan
                @can('demandes.reject', $demande)
                    @if(in_array($demande->status, ['in_review', 'waiting_student'], true))
                        <form method="POST" action="{{ route('admin.demandes.rejeter', $demande) }}" class="flex flex-wrap items-center gap-1.5">
                            @csrf @method('PATCH')
                            <input type="text" name="motif" required maxlength="500" placeholder="Motif du rejet (obligatoire)"
                                   class="rounded-xl border border-slate-300 px-3 py-2.5 text-xs font-normal min-w-[220px]">
                            <button type="submit" class="rounded-xl border border-rose-300 px-4 py-2.5 text-rose-700 hover:bg-rose-50 min-h-[44px]">Rejeter</button>
                        </form>
                    @endif
                @endcan
                @can('demandes.pdf', $demande)
                    @if(in_array($demande->status, ['approved', 'completed'], true) && $demande->documents->isNotEmpty())
                        <a href="{{ route('admin.demandes.document.download', $demande) }}"
                           class="inline-flex items-center rounded-xl bg-primary-800 px-4 py-2.5 text-white hover:bg-primary-700 min-h-[44px]">Télécharger PDF</a>
                    @endif
                @endcan
            </div>
        </div>

        <section aria-label="Pièces justificatives" class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <h2 class="px-5 py-3 border-b border-slate-100 text-sm font-black text-slate-900">Pièces ({{ $demande->pieces->count() }})</h2>
            @if($demande->pieces->isNotEmpty())
                <ul class="divide-y divide-slate-100">
                    @foreach($demande->pieces as $piece)
                        <li class="flex items-center justify-between gap-3 px-5 py-3 text-sm">
                            <span class="min-w-0">
                                <span class="block truncate font-bold text-slate-800">{{ $piece->original_filename }}</span>
                                <span class="block text-[11px] text-slate-500">{{ $piece->created_at->format('d/m/Y H:i') }}</span>
                            </span>
                            @can('demandes.documents.download', $demande)
                                <a href="{{ route('admin.demandes.pieces.download', [$demande, $piece]) }}" class="flex-shrink-0 text-xs font-bold text-primary-800 underline underline-offset-2">Télécharger</a>
                            @endcan
                        </li>
                    @endforeach
                </ul>
            @else
                <div class="p-5"><x-empty-state message="Aucune pièce jointe." /></div>
            @endif
        </section>

        @can('demandes.process', $demande)
            @include('modules.online-requests._messages', ['action' => route('admin.demandes.messages.store', $demande), 'peutEcrire' => true])
        @else
            @include('modules.online-requests._messages', ['action' => '#', 'peutEcrire' => false])
        @endcan

        @if($demande->documents->isNotEmpty())
            <section aria-label="Documents générés" class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-sm font-black text-slate-900">Documents générés</h2>
                <ul class="mt-2 space-y-1.5 text-xs">
                    @foreach($demande->documents as $document)
                        <li class="flex items-center justify-between gap-3 rounded-xl border border-slate-100 px-3.5 py-2.5">
                            <span class="font-mono font-bold text-slate-800">{{ $document->reference }}</span>
                            @can('demandes.pdf', $demande)
                                <a href="{{ route('admin.demandes.document.download', $demande) }}" class="font-bold text-primary-800 underline underline-offset-2">Télécharger</a>
                            @endcan
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if($peutVoirAudit)
            <section aria-label="Historique" class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-sm font-black text-slate-900">Historique (traçabilité complète)</h2>
                @if($historique->isNotEmpty())
                    <ul class="mt-3 space-y-2">
                        @foreach($historique as $entree)
                            <li class="rounded-xl border border-slate-100 bg-slate-50/60 px-3.5 py-2.5 text-xs">
                                <p class="font-bold text-slate-800">{{ $entree->action }}</p>
                                <p class="mt-0.5 text-slate-500 tabular-nums">{{ $entree->created_at->format('d/m/Y H:i') }}</p>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="mt-2 text-sm text-slate-500">Aucun événement.</p>
                @endif
            </section>
        @endif
    </div>
@endsection
