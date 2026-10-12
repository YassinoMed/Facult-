@extends('layouts.app')

@section('title', 'Hors ligne')

@section('content')
    <div class="mx-auto max-w-md px-4 py-16 text-center">
        <p class="text-4xl" aria-hidden="true">📡</p>
        <h1 class="mt-4 text-2xl font-black text-slate-900">Connexion perdue</h1>
        <p class="mt-2 text-sm text-slate-500">
            UniRegistre nécessite une connexion pour vos données (aucune donnée
            sensible n'est conservée hors ligne). Rechargez la page dès le
            retour du réseau.
        </p>
        <a href="/" class="mt-6 inline-block rounded-xl bg-primary-800 px-5 py-2.5 text-sm font-bold text-white">Réessayer</a>
    </div>
@endsection
