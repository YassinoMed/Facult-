@extends('layouts.app')

@section('title', 'Cockpit des modèles de présence — Scolarité')
@section('meta_description', 'Cockpit visuel et interactif de conception des modèles et feuilles d\'émargement.')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Module Présences · Administration</p>
                <h1 class="mt-1 text-2xl sm:text-3xl font-black tracking-tight text-slate-900">Cockpit des modèles de présence</h1>
                <p class="mt-1 text-sm text-slate-500">
                    Conception modulaire, réorganisation des blocs et prévisualisation instantanée sans PII.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <x-btn href="{{ route('admin.presences.index') }}" variant="ghost">
                    ← Retour aux présences
                </x-btn>
                <x-btn href="{{ route('admin.presences.feuille.nouveau') }}" variant="secondary">
                    Générateur d'émargement
                </x-btn>
            </div>
        </div>

        @if(session('success'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
                {{ session('success') }}
            </div>
        @endif

        {{-- Intégration du composant Livewire Builder --}}
        <livewire:attendance-template-builder :initial-template-id="request('template_id')" />
    </div>
@endsection
