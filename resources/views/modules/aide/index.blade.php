@extends('modules.aide.gabarit')

@section('titre', "Centre d'aide")

@section('contenu')
    <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
        <h1 class="text-2xl font-black mb-2">Bienvenue — entrez par l'angle qui correspond à votre situation</h1>
        <p class="text-slate-600">
            Cette aide ne contient ni code ni architecture : elle répond à
            <strong>« comment utiliser la plateforme ? »</strong>. Vos possibilités dépendent
            d'abord de votre rôle et de votre périmètre (faculté).
        </p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach ($pages as $slug => $page)
            <a href="{{ route('aide.page', $slug) }}"
               class="bg-white rounded-2xl shadow-sm p-5 hover:shadow-md transition min-h-[44px] block">
                <span class="block text-lg font-bold text-primary-700 mb-1">{{ $page['titre'] }}</span>
                <span class="block text-sm text-slate-500">Ouvrir cette page d'aide →</span>
            </a>
        @endforeach
    </div>

    <div class="bg-white rounded-2xl shadow-sm p-6 mt-6">
        <h2 class="text-lg font-bold mb-2">Rappels valables pour tous les profils</h2>
        <ul class="list-disc ml-5 space-y-1 text-slate-700">
            <li>Le paiement n'est jamais bloquant pour la validation d'une inscription.</li>
            <li>Les notes officielles ne sont visibles qu'après publication d'une délibération.</li>
            <li>Période d'inscription fermée → saisie refusée avec un message explicite.</li>
            <li>Toute écriture est tracée (audit immuable) : motif, auteur, avant/après.</li>
            <li>Un module désactivé répond 404 : ce n'est pas une panne.</li>
        </ul>
    </div>
@endsection
