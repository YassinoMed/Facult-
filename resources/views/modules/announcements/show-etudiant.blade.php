@extends('layouts.app')

@section('title', $annonce->titre)

@section('content')
    <div class="mx-auto max-w-2xl px-4 sm:px-6 py-8">
        <x-page-header :titre="$annonce->titre" :description="'Publiée le ' . $annonce->publiee_a?->format('d/m/Y')"
            :fil="['Annonces' => $retour ?? route('etudiant.annonces.index'), 'Lecture' => '#']" />
        <div class="mt-4 ds-card p-5 text-sm leading-relaxed">{{ $annonce->contenu }}</div>
    </div>
@endsection
