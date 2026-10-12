@extends('layouts.app')

@section('title', 'Modifier — ' . $faculte->nom)

@section('content')
<div class="mx-auto max-w-2xl px-4 py-8 sm:px-6 lg:px-8">
    {{-- Back --}}
    <a href="{{ route('admin.facultes.index') }}"
       class="mb-6 inline-flex items-center gap-2 text-sm font-medium text-gray-500 transition-smooth hover:text-primary-600">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
        Retour aux facultés
    </a>

    <div class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm sm:p-8">
        <div class="mb-6">
            <h1 class="text-xl font-bold text-gray-900">Modifier la faculté</h1>
            <p class="mt-1 text-sm text-gray-500">Modifiez les informations de <strong>{{ $faculte->nom }}</strong>.</p>
        </div>

        <form action="{{ route('admin.facultes.update', $faculte) }}" method="POST">
            @csrf
            @method('PUT')
            @include('admin.facultes._form')

            <div class="mt-6 flex items-center justify-end gap-3">
                <a href="{{ route('admin.facultes.index') }}"
                   class="rounded-lg border border-gray-200 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 transition-smooth hover:bg-gray-50">
                    Annuler
                </a>
                <button type="submit"
                        class="rounded-lg bg-primary-600 px-6 py-2.5 text-sm font-semibold text-white transition-smooth hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                    Mettre à jour
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
