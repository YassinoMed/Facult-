@extends('layouts.app')

@section('title', 'Mes Documents — Espace Étudiant')
@section('meta_description', 'Vos documents académiques autorisés : relevés, attestations, diplômes.')

@section('content')
<div class="mx-auto max-w-5xl px-4 sm:px-6 py-8 space-y-6">

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-black text-slate-900">Mes Documents Académiques</h1>
            <p class="mt-1 text-sm text-slate-500">Vos documents autorisés — chaque téléchargement est journalisé.</p>
        </div>
        <a href="{{ route('etudiant.archives.show') }}"
           class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 px-3.5 py-2 text-xs font-bold text-slate-600 hover:bg-slate-50 transition-smooth">
            ← Dossier complet
        </a>
    </div>

    @if($documents->isEmpty())
        <div class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">
            Aucun document disponible pour le moment.
        </div>
    @endif

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        @foreach($documents as $document)
            <div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm flex items-center gap-4">
                <div class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-700 border border-primary-100">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 12h6m-6 3h4.5M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h6.75c.621 0 1.125.504 1.125 1.125V21M3 3v12h6" />
                    </svg>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-bold text-slate-900">{{ $document->nom_original }}</p>
                    <p class="mt-0.5 text-xs text-slate-400">
                        {{ $document->type }} · {{ strtoupper($document->extension) }}
                        @if($document->anneeUniversitaire) · {{ $document->anneeUniversitaire->libelle }} @endif
                        · {{ \Illuminate\Support\Number::fileSize($document->taille) }}
                    </p>
                </div>
                <a href="{{ route('etudiant.archives.documents.download', $document) }}"
                   class="inline-flex items-center gap-1.5 rounded-xl bg-slate-900 px-3.5 py-2 text-xs font-bold text-white hover:bg-slate-800 transition-smooth">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                    </svg>
                    Télécharger
                </a>
            </div>
        @endforeach
    </div>
</div>
@endsection
