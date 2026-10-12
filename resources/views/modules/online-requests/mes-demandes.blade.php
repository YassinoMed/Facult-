@extends('layouts.app')

@section('title', 'Mes demandes — Espace Étudiant')

@section('content')
    <div class="mx-auto max-w-5xl px-4 sm:px-6 py-8 sm:py-12">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Espace étudiant</p>
                <h1 class="mt-1 text-2xl sm:text-3xl font-black tracking-tight text-slate-900">Mes demandes</h1>
            </div>
            <a href="{{ route('etudiant.demandes.create') }}"
               class="inline-flex items-center justify-center gap-2 rounded-xl bg-primary-800 px-5 py-3 text-sm font-bold text-white hover:bg-primary-700 min-h-[48px]">
                Nouvelle demande
            </a>
        </div>

        @if(session('success'))
            <div class="mt-4 rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-xs font-bold text-emerald-900" role="status">✓ {{ session('success') }}</div>
        @endif

        <form method="GET" action="{{ route('etudiant.demandes.index') }}" class="mt-6 flex flex-col sm:flex-row gap-3">
            <select name="statut" aria-label="Filtrer par statut" class="rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm">
                <option value="">Tous les statuts</option>
                @foreach(\App\Modules\OnlineRequests\Services\RequestWorkflowService::libelles() as $code => $libelle)
                    <option value="{{ $code }}" @selected(request('statut') === $code)>{{ $libelle }}</option>
                @endforeach
            </select>
            <button type="submit" class="rounded-xl border border-slate-300 px-5 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50 min-h-[44px]">Filtrer</button>
        </form>

        <section aria-label="Liste de mes demandes" class="mt-4 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            @if($demandes->isNotEmpty())
                <ul class="divide-y divide-slate-100">
                    @foreach($demandes as $demande)
                        <li class="px-5 py-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="font-mono text-sm font-black text-slate-900">{{ $demande->reference }}</p>
                                    <p class="truncate text-sm text-slate-600">{{ $demande->type->name }} — {{ $demande->objet }}</p>
                                    <p class="text-[11px] text-slate-500 tabular-nums">{{ $demande->created_at->format('d/m/Y') }}</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <x-status-badge :statut="$demande->status" />
                                    <a href="{{ route('etudiant.demandes.show', $demande) }}" class="text-xs font-bold text-primary-800 underline underline-offset-2">Voir</a>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
                <div class="px-5 py-3 border-t border-slate-100">{{ $demandes->links() }}</div>
            @else
                <div class="p-5">
                    <x-empty-state message="Aucune demande pour le moment. Créez votre première demande en ligne." />
                </div>
            @endif
        </section>
    </div>
@endsection
