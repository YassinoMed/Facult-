@extends('layouts.app')

@section('title', 'Gestion des Facultés')

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">

    {{-- En-tête Desktop --}}
    <div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2.5">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary-100 text-primary-700">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0 0 12 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75Z" />
                    </svg>
                </div>
                <div>
                    <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">Facultés Universitaires</h1>
                    <p class="mt-0.5 text-xs sm:text-sm text-slate-500">Administration des structures académiques, codes d'affectation et quotas.</p>
                </div>
            </div>
        </div>

        <a href="{{ route('admin.facultes.create') }}"
           class="inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-primary-600 to-accent-600 px-4 py-2.5 text-xs font-bold text-white shadow-md shadow-primary-500/20 hover:shadow-lg hover:shadow-primary-500/30 hover:-translate-y-0.5 transition-smooth">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
            </svg>
            Ajouter une Faculté
        </a>
    </div>

    {{-- Grille des Facultés (Desktop 3 Colonnes Cards) --}}
    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
        @forelse($facultes as $faculte)
            <div class="card-hover group relative rounded-3xl border border-slate-200/90 bg-white p-6 shadow-xs backdrop-blur-md flex flex-col justify-between"
                 x-data="{ openDelete: false }">

                {{-- Top Badge Statut --}}
                <div>
                    <div class="flex items-center justify-between gap-2 mb-4">
                        <span class="inline-flex items-center font-mono rounded-lg bg-primary-50 px-2.5 py-1 text-xs font-extrabold text-primary-800 border border-primary-200">
                            {{ $faculte->code }}
                        </span>

                        @if($faculte->active)
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-bold text-emerald-700 border border-emerald-200">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                Active
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-bold text-slate-500 border border-slate-200">
                                <span class="h-1.5 w-1.5 rounded-full bg-slate-400"></span>
                                Inactive
                            </span>
                        @endif
                    </div>

                    <h3 class="text-base font-bold text-slate-900 group-hover:text-primary-700 transition-smooth leading-snug">
                        {{ $faculte->nom }}
                    </h3>

                    @if($faculte->description)
                        <p class="mt-2 text-xs text-slate-500 line-clamp-2 leading-relaxed">
                            {{ $faculte->description }}
                        </p>
                    @endif
                </div>

                {{-- Bottom Stats & Actions --}}
                <div class="mt-6 pt-4 border-t border-slate-100">
                    <div class="flex items-center justify-between text-xs text-slate-600 mb-4">
                        <span class="flex items-center gap-1.5">
                            <svg class="h-4 w-4 text-primary-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                            </svg>
                            Inscrits
                        </span>
                        <span class="font-extrabold text-slate-900 bg-slate-100 px-2 py-0.5 rounded-md">
                            {{ $faculte->etudiants_count }} étudiant{{ $faculte->etudiants_count > 1 ? 's' : '' }}
                        </span>
                    </div>

                    <div class="flex items-center gap-2">
                        <a href="{{ route('admin.facultes.edit', $faculte) }}"
                           class="flex-1 inline-flex items-center justify-center gap-1.5 rounded-xl border border-slate-200 bg-white py-2 px-3 text-xs font-bold text-slate-700 hover:bg-slate-50 hover:text-primary-700 transition-smooth">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" /></svg>
                            Modifier
                        </a>

                        <button type="button"
                                @click="openDelete = true"
                                class="inline-flex items-center justify-center p-2 rounded-xl border border-rose-200 text-rose-600 hover:bg-rose-50 transition-smooth"
                                title="Supprimer la faculté">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                        </button>
                    </div>

                    {{-- Modal Confirmation Delete --}}
                    <div x-show="openDelete" x-cloak
                         class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 text-left">
                        <div @click.away="openDelete = false"
                             class="w-full max-w-md rounded-3xl bg-white p-6 shadow-2xl">
                            <h3 class="text-base font-bold text-slate-900">Supprimer la faculté ?</h3>
                            <p class="mt-2 text-xs sm:text-sm text-slate-600">
                                Êtes-vous sûr de vouloir supprimer <strong class="text-slate-900">{{ $faculte->nom }}</strong> ({{ $faculte->code }}) ?
                                @if($faculte->etudiants_count > 0)
                                    <span class="block mt-2 font-bold text-rose-600">
                                        Attention : {{ $faculte->etudiants_count }} étudiant(s) sont inscrits dans cette faculté. La suppression sera bloquée.
                                    </span>
                                @endif
                            </p>
                            <div class="mt-6 flex justify-end gap-2.5">
                                <button type="button" @click="openDelete = false" class="rounded-xl border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                    Annuler
                                </button>
                                <form action="{{ route('admin.facultes.destroy', $faculte) }}" method="POST">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="rounded-xl bg-rose-600 px-4 py-2 text-xs font-bold text-white hover:bg-rose-700 shadow-xs">
                                        Supprimer
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-span-full rounded-3xl border border-slate-200 bg-white p-12 text-center text-slate-400">
                Aucune faculté enregistrée pour le moment.
            </div>
        @endforelse
    </div>

    {{-- Pagination --}}
    @if($facultes->hasPages())
        <div class="mt-8">
            {{ $facultes->links() }}
        </div>
    @endif
</div>
@endsection
