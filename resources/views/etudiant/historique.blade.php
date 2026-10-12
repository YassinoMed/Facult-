@extends('layouts.app')

@section('title', 'Mon Historique Académique — Espace Étudiant')
@section('meta_description', 'Journal des événements de votre dossier académique.')

@section('content')
<div class="mx-auto max-w-5xl px-4 sm:px-6 py-8 space-y-6">

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-black text-slate-900">Mon Historique Académique</h1>
            <p class="mt-1 text-sm text-slate-500">Journal en lecture seule des événements de votre dossier.</p>
        </div>
        <a href="{{ route('etudiant.archives.show') }}"
           class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 px-3.5 py-2 text-xs font-bold text-slate-600 hover:bg-slate-50 transition-smooth">
            ← Dossier complet
        </a>
    </div>

    @if($historique->isEmpty())
        <div class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">
            Aucun événement enregistré pour le moment.
        </div>
    @else
        <div class="rounded-2xl border border-slate-200/80 bg-white shadow-sm divide-y divide-slate-50">
            @foreach($historique as $entree)
                <div class="flex items-start gap-4 px-6 py-4">
                    <div class="mt-1 flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                        </svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-slate-800">{{ $entree->libelle }}</p>
                        <p class="mt-0.5 text-xs text-slate-400">
                            {{ $entree->created_at?->format('d/m/Y à H:i') }}
                            @if($entree->adresse_ip) · {{ $entree->adresse_ip }} @endif
                        </p>
                    </div>
                    <span class="rounded-full bg-slate-50 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-slate-500 border border-slate-200">
                        {{ $entree->entite }}
                    </span>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
