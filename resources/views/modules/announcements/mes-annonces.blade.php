@extends('layouts.app')

@section('title', 'Mes annonces')

@section('content')
    <div class="annonces-page mx-auto max-w-4xl px-4 sm:px-6 py-8">
        <x-page-header titre="Annonces" description="Informations ciblées de votre établissement." :fil="['Annonces' => '#']" />

        <div class="mt-4 space-y-3">
            @forelse($annonces as $annonce)
                <a href="{{ route($routeLecture ?? 'etudiant.annonces.show', $annonce) }}" class="annonce-card ds-card block p-4">
                    <p class="font-bold text-slate-900">{{ $annonce->titre }}</p>
                    <p class="mt-1 text-sm text-slate-500">{{ mb_substr($annonce->contenu, 0, 160) }}…</p>
                    <p class="mt-2"><x-status-badge :statut="$annonce->type" /></p>
                </a>
            @empty
                <x-empty-state :message="'Aucune annonce — Revenez plus tard.'" />
            @endforelse
        </div>
        <div class="mt-4">{{ $annonces->links() }}</div>
    </div>
@endsection
