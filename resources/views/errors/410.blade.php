@extends('layouts.app')

@section('title', '410 — ' . ($exception->getMessage() ?: 'Document révoqué'))

@section('content')
    <div class="mx-auto max-w-xl px-4 sm:px-6 py-16 text-center">
        <p class="font-mono text-6xl font-black text-rose-700" aria-hidden="true">410</p>
        <h1 class="mt-2 text-2xl font-black text-slate-900">{{ $exception->getMessage() ?: 'Document révoqué' }}</h1>
        <p class="mt-2 text-sm text-slate-600">Ce document officiel a été révoqué ou retiré. Il ne peut plus être imprimé, téléchargé ou délivré.</p>
        <p class="mt-1 text-xs text-slate-500">Pour toute régularisation ou nouveau tirage, veuillez consulter le dossier académique de l’étudiant.</p>
        <div class="mt-6 flex flex-col sm:flex-row justify-center gap-2">
            <x-btn href="{{ url()->previous() ?: route('admin.documents-center.index') }}">Retour</x-btn>
            <x-btn variant="secondary" href="{{ route('admin.documents-center.index') }}">Centre documentaire</x-btn>
        </div>
    </div>
@endsection
