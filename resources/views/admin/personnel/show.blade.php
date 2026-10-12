@extends('layouts.app')

@section('title', $personnel->matricule . ' — Personnel')

@section('content')
    <div class="mx-auto max-w-3xl px-4 sm:px-6 py-8">
        <x-page-header :titre="$personnel->user->name" :description="$personnel->matricule . ' · ' . ($personnel->fonction?->nom ?? '—')"
            :fil="['Personnel' => route('admin.personnel.index'), $personnel->matricule => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('admin.personnel.edit', $personnel) }}">Modifier</x-btn>
            </x-slot:actions>
        </x-page-header>

        <div class="mt-4 ds-card p-6">
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                <div><dt class="text-[11px] font-bold uppercase text-slate-400">Email</dt><dd class="font-semibold">{{ $personnel->user->email }}</dd></div>
                <div><dt class="text-[11px] font-bold uppercase text-slate-400">Rôle</dt><dd class="font-semibold">{{ $personnel->user->role_libelle }}</dd></div>
                <div><dt class="text-[11px] font-bold uppercase text-slate-400">Département</dt><dd class="font-semibold">{{ $personnel->departement?->nom ?? '—' }}</dd></div>
                <div><dt class="text-[11px] font-bold uppercase text-slate-400">Bureau</dt><dd class="font-semibold">{{ $personnel->bureau ?? '—' }}</dd></div>
                <div><dt class="text-[11px] font-bold uppercase text-slate-400">Entrée</dt><dd class="font-semibold">{{ $personnel->date_entree?->format('d/m/Y') ?? '—' }}</dd></div>
                <div><dt class="text-[11px] font-bold uppercase text-slate-400">Statut</dt><dd class="font-semibold">{{ $personnel->statut }}</dd></div>
            </dl>
        </div>
    </div>
@endsection
