@extends('layouts.app')

@section('title', '403 — Accès refusé')

@section('content')
    <div class="mx-auto max-w-xl px-4 sm:px-6 py-16 text-center">
        <p class="font-mono text-6xl font-black text-primary-800" aria-hidden="true">403</p>
        <h1 class="mt-2 text-2xl font-black text-slate-900">Accès refusé</h1>
        <p class="mt-2 text-sm text-slate-600">Vous n’avez pas la permission d’accéder à cette page.</p>
        <p class="mt-1 text-xs text-slate-500">Si vous pensez qu’il s’agit d’une erreur, contactez la scolarité.</p>
        <div class="mt-6 flex flex-col sm:flex-row justify-center gap-2">
            <x-btn href="{{ route('inscription.create') }}">Retour à l'accueil</x-btn>
            <x-btn variant="secondary" href="{{ route('etudiant.connexion.show') }}">Se connecter</x-btn>
        </div>
    </div>
@endsection
