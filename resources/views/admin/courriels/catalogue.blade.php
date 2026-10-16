@extends('layouts.app')

@section('title', 'Courriels — catalogue')

@section('content')
<div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
    <x-page-header titre="Catalogue des modèles" description="Identifiants stables, versions, variables autorisées et règles. Aperçu sans envoi."
        :fil="['Courriels' => route('admin.courriels.index')]" />

    <div class="mt-4 grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-black text-slate-900">Modèles (catalogue v{{ config('courriels.version_catalogue') }})</h2>
            <ul class="mt-3 space-y-3">
                @foreach($modeles as $identifiant => $modele)
                    <li class="rounded-xl border border-slate-200 p-4 @if($identifiant === $choisi) border-primary-400 bg-primary-50/50 @endif">
                        <div class="flex items-center justify-between gap-2">
                            <p class="font-mono text-sm font-bold text-slate-900">{{ $identifiant }} <span class="text-slate-400">v{{ $modele['version'] }}</span></p>
                            <a href="{{ route('admin.courriels.catalogue', ['modele' => $identifiant]) }}" class="text-xs font-bold text-primary-800 underline underline-offset-2">Aperçu</a>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">{{ $modele['categorie'] }} · {{ $modele['sujet'] }}</p>
                        <p class="mt-1 text-xs text-slate-500">Variables : <span class="font-mono">{{ implode(', ', $modele['variables']) }}</span></p>
                        <p class="mt-1 text-xs text-slate-600">{{ $modele['regle'] }}</p>
                        @if(!empty($modele['emissible_cockpit']))
                            <p class="mt-1 text-xs font-bold text-emerald-700">Émissible depuis le cockpit (double confirmation).</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-black text-slate-900">Aperçu — <span class="font-mono">{{ $choisi }}</span></h2>
            <p class="mt-1 text-xs text-slate-500">Rendu sans envoi, variables de démonstration sûres.</p>
            <div class="mt-3 rounded-xl border border-slate-200 overflow-hidden">
                <div class="bg-slate-900 px-4 py-3">
                    <p class="text-sm font-bold text-white">{{ $apercu['sujet'] }}</p>
                </div>
                <div class="p-4 text-sm text-slate-700">
                    @foreach($apercu['lignes'] as $ligne)
                        <p class="mt-1">{{ $ligne }}</p>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
