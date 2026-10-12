@extends('layouts.app')

@section('title', 'Support — Espace Enseignant')

@section('content')
    <div class="mx-auto max-w-4xl px-4 sm:px-6 py-8">
        <x-page-header :titre="$ressource->titre" :description="($ressource->matiere?->code ?? '—') . ' · ' . ($ressource->groupe?->code ?? 'Tous groupes')"
            :fil="['Supports' => route('enseignant.ressources.index'), 'Détail' => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('enseignant.ressources.telecharger', $ressource) }}">Télécharger</x-btn>
            </x-slot:actions>
        </x-page-header>

        @if(session('success'))
            <div class="mt-4"><x-alert type="success" :message="session('success')" /></div>
        @endif
        @if($errors->any())
            <div class="mt-4"><x-alert type="error" :message="$errors->first()" /></div>
        @endif

        <section aria-label="Détail du support" class="mt-4 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between gap-2">
                <h3 class="text-sm font-black text-slate-900 dark:text-slate-100">Fiche support</h3>
                <x-status-badge :statut="$ressource->statut" />
            </div>
            <dl class="divide-y divide-slate-100 dark:divide-slate-800 text-sm">
                <div class="px-5 py-3 flex justify-between gap-4"><dt class="text-slate-500">Matière</dt><dd class="font-semibold">{{ $ressource->matiere?->code }} — {{ $ressource->matiere?->nom }}</dd></div>
                <div class="px-5 py-3 flex justify-between gap-4"><dt class="text-slate-500">Groupe ciblé</dt><dd class="font-semibold">{{ $ressource->groupe?->code ?? 'Tous les groupes' }}</dd></div>
                <div class="px-5 py-3 flex justify-between gap-4"><dt class="text-slate-500">Fichier</dt><dd class="font-mono text-xs">{{ $ressource->nom_fichier }} ({{ number_format($ressource->taille / 1024, 1) }} Ko)</dd></div>
                @if($ressource->description)
                    <div class="px-5 py-3"><dt class="text-slate-500">Description</dt><dd class="mt-1 text-slate-700 dark:text-slate-300">{{ $ressource->description }}</dd></div>
                @endif
            </dl>
            <div class="px-5 py-4 border-t border-slate-100 dark:border-slate-800 flex flex-wrap gap-2">
                @if($ressource->statut === 'brouillon')
                    <form method="POST" action="{{ route('enseignant.ressources.publier', $ressource) }}">@csrf<x-btn type="submit">Publier</x-btn></form>
                    <form method="POST" action="{{ route('enseignant.ressources.destroy', $ressource) }}" onsubmit="return confirm('Supprimer ce support ?')">@csrf @method('DELETE')<x-btn type="submit" variant="danger">Supprimer</x-btn></form>
                @elseif($ressource->statut === 'publie')
                    <form method="POST" action="{{ route('enseignant.ressources.archiver', $ressource) }}">@csrf<x-btn type="submit" variant="secondary">Archiver</x-btn></form>
                    <form method="POST" action="{{ route('enseignant.ressources.retirer', $ressource) }}">@csrf<x-btn type="submit" variant="secondary">Repasser en brouillon</x-btn></form>
                @else
                    <form method="POST" action="{{ route('enseignant.ressources.retirer', $ressource) }}">@csrf<x-btn type="submit" variant="secondary">Repasser en brouillon</x-btn></form>
                    <form method="POST" action="{{ route('enseignant.ressources.destroy', $ressource) }}" onsubmit="return confirm('Supprimer ce support ?')">@csrf @method('DELETE')<x-btn type="submit" variant="danger">Supprimer</x-btn></form>
                @endif
            </div>
        </section>
    </div>
@endsection
