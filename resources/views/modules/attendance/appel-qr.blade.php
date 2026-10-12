@extends('layouts.app')

@section('title', 'QR de pointage — Espace Enseignant')

@section('content')
    <div class="mx-auto max-w-xl px-4 sm:px-6 py-8">
        <x-page-header :titre="'QR — ' . $etudiant->nom_complet" :description="'Appel du ' . $appel->date->format('d/m/Y') . ' · valable 15 minutes'"
            :fil="['Feuille' => route('enseignant.appel.feuille', $appel), 'QR' => '#']" />

        <section aria-label="QR temporaire de pointage" class="mt-4 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm p-6 text-center">
            <div class="flex justify-center" role="img" aria-label="QR code de pointage pour {{ $etudiant->nom_complet }}">{!! $qrSvg !!}</div>
            <p class="mt-3 text-xs font-mono break-all text-slate-500 dark:text-slate-400">{{ $jeton }}</p>
            <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                L'étudiant scanne ce QR (ou saisit le code) depuis <strong>son espace → Pointer ma présence</strong>.
                Jeton nominatif, valable 15 minutes, inutilisable pour un autre étudiant.
                Le pointage reste vérifiable et corrigeable sur la feuille d'appel.
            </p>
        </section>
    </div>
@endsection
