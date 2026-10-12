@extends('layouts.app')

@section('title', 'Mes Inscriptions — Espace Étudiant')
@section('meta_description', 'Consultation de vos inscriptions académiques par année universitaire.')

@section('content')
<div class="mx-auto max-w-5xl px-4 sm:px-6 py-8 space-y-6 relative">

    {{-- Academic banner header --}}
    <div class="relative overflow-hidden rounded-2xl border border-slate-200/80 bg-gradient-to-b from-slate-50 to-white shadow-2xl shadow-slate-400/25 mb-8">
        {{-- Subtle grid background --}}
        <div class="absolute inset-0 opacity-5 bg-grid-pattern-dark"></div>
        {{-- Decorative floating academic glyphs --}}
        <div class="absolute top-0 left-0 h-64 w-64 rounded-full bg-primary-500/10 blur-xl opacity-60 -translate-y-1/2 -translate-x-1/2 animate-float" aria-hidden="true"></div>
        <div class="absolute bottom-0 right-0 h-72 w-72 rounded-full bg-accent-500/10 blur-xl opacity-60 translateY-1/3 translateX-1/3 animate-float" aria-hidden="true"></div>
        
        <div class="relative z-10 px-6 py-8 sm:px-10 lg:px-16">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h1 class="text-3xl sm:text-4xl font-black text-slate-900 tracking-tight">
                        Mes Inscriptions Académiques
                    </h1>
                    <p class="mt-1 text-sm sm:text-base text-slate-500">
                        Une inscription par année universitaire, rattachée à votre dossier permanent.
                    </p>
                </div>
                <a href="{{ route('etudiant.archives.show') }}"
                   class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 px-3.5 py-2 text-xs font-bold uppercase tracking-wider text-slate-600 hover:bg-slate-50 transition-smooth">
                    ← Dossier complet
                </a>
            </div>
            
            {{-- Academic year filter/selector --}}
            <div class="mt-4 pt-4 border-t border-slate-100">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Année universitaire en cours</p>
                <p class="mt-1 text-sm font-medium text-slate-600">{{ $inscriptions->isNotEmpty() ? $inscriptions->first()->anneeUniversitaire->libelle : 'Aucune année' }}</p>
            </div>
        </div>
    </div>

    {{-- Stats overview --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        {{-- Total inscriptions card --}}
        <div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm card-hover animate-fade-up">
            <div class="h-10 w-10 flex items-center justify-center rounded-xl bg-primary-50 text-primary-600 border border-primary-100">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
            </div>
            <div class="mt-3">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Total inscriptions</p>
                <p class="mt-1 text-2xl font-black text-slate-900">{{ $inscriptions->count() }}</p>
            </div>
        </div>

        {{-- Validées card --}}
        <div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm card-hover animate-fade-up" style="animation-delay: 0.08s">
            <div class="h-10 w-10 flex items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 border border-emerald-100">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
            </div>
            <div class="mt-3">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Validées</p>
                <p class="mt-1 text-2xl font-black text-emerald-800">{{ $inscriptions->where('statut', 'valide')->count() }}</p>
            </div>
        </div>

        {{-- En cours card --}}
        <div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm card-hover animate-fade-up" style="animation-delay: 0.16s">
            <div class="h-10 w-10 flex items-center justify-center rounded-xl bg-amber-50 text-amber-600 border border-amber-100">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                </svg>
            </div>
            <div class="mt-3">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">En cours</p>
                <p class="mt-1 text-2xl font-black text-amber-800">{{ $inscriptions->where('statut', 'en_cours')->count() }}</p>
            </div>
        </div>

        {{-- Moyenne card --}}
        <div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm card-hover animate-fade-up" style="animation-delay: 0.24s">
            <div class="h-10 w-10 flex items-center justify-center rounded-xl bg-slate-50 text-slate-600 border border-slate-100">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
            </div>
            <div class="mt-3">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Moyenne générale</p>
                <p class="mt-1 text-2xl font-black">{{ $inscriptions->avg('moyenne_annuelle') ?? '—' }}</p>
                <p class="text-xs text-slate-400">/20</p>
            </div>
        </div>
    </div>

    {{-- Inscriptions list --}}
    @if($inscriptions->isEmpty())
        <div class="rounded-3xl border border-dashed border-slate-300 p-12 text-center text-slate-400 animate-fade-up">
            <svg class="mx-auto mb-4 h-12 w-12 text-slate-300 opacity-50" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h5m0 0h5v-6m0 0H5v6m0 0h5v-6a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2z" />
                <circle cx="3" cy="3" r="2" fill="currentColor" />
                <circle cx="21" cy="3" r="2" fill="currentColor" />
            </svg>
            <p class="mt-3 text-lg font-black text-slate-500">Aucune inscription académique enregistrée pour le moment.</p>
            <p class="mt-1 text-sm text-slate-400">Votre parcours s'affichera ici une fois vos inscriptions traitées.</p>
        </div>
    @endif

    <div class="space-y-6">
        @foreach($inscriptions as $inscription)
            {{-- Academic year card --}}
            <div class="rounded-3xl border border-slate-200/70 bg-white shadow-sm overflow-hidden card-hover animate-fade-up"
                 style="animation-delay: {{ $loop->iteration * 0.08 }}s">
                {{-- Year header with status badge --}}
                <div class="px-6 py-5 sm:px-8 lg:px-12 border-b border-slate-100 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        {{-- Section marker (I., II., etc.) --}}
                        <span class="font-serif text-base font-bold text-slate-900">
                            {{ $loop->first ? 'I.' : ($loop->iteration === 2 ? 'II.' : ($loop->iteration === 3 ? 'III.' : 'IV.')) }}
                        </span>
                        <h2 class="text-lg font-black text-slate-900 flex-1 truncate">{{ $inscription->anneeUniversitaire?->libelle ?? '—' }}</h2>
                        {{-- Status badge --}}
                        <span class="rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider bg-slate-50 text-slate-700 border border-slate-200">
                            {{ ucfirst($inscription->statut) }}
                        </span>
                    </div>
                    
                    {{-- Visual indicator dot --}}
                    <div class="h-3 w-3 rounded-full {{ statusDotClass($inscription->statut)}}"></div>
                </div>

                {{-- Inscription details grid --}}
                <div class="px-6 py-5 sm:px-8 lg:px-12 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    
                    {{-- Niveau --}}
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Niveau</p>
                        <p class="mt-1 font-black text-slate-900">{{ $inscription->niveau ?? '—' }}</p>
                    </div>

                    {{-- Filière --}}
                    @if($inscription->filiere)
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Filière</p>
                            <p class="mt-1 text-sm font-bold text-slate-600">{{ $inscription->filiere }}</p>
                        </div>
                    @endif

                    {{-- Groupe --}}
                    @if($inscription->groupe)
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Groupe</p>
                            <p class="mt-1 text-sm font-bold text-slate-600">{{ $inscription->groupe }}</p>
                        </div>
                    @endif

                    {{-- Crédits --}}
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Crédits</p>
                        <p class="mt-1 text-right">{{ $inscription->credits_obtenus ?? '—' }}/{{ $inscription->credits_totaux ?? '—' }}</p>
                    </div>

                    {{-- Moyenne --}}
                    @if($inscription->moyenne_generale !== null)
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Moyenne</p>
                            <p class="mt-1 text-right">
                                <span class="{{ moyenneColorClass($inscription->moyenne_generale) }}" x-text="number_format($inscription->moyenne_generale, 2)"></span>
                                <span class="text-[10px] text-slate-400">/20</span>
                            </p>
                        </div>
                    @endif

                    {{-- Décision --}}
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Décision</p>
                        <p class="mt-1 text-right">
                            <span class="{{ decisionColorClass($inscription->decision) }}" x-text="$inscription->decision ?? 'En cours'"></span>
                        </p>
                    </div>
                </div>

                {{-- Semester summary --}}
                <div class="pt-4 border-t border-slate-100">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Parcours</p>
                    <div class="mt-1 flex items-center gap-2">
                        <span class="text-[10px] font-medium text-slate-500">{{ $inscription->semestres->count() }}</span>
                        <span class="text-slate-300">semestre</span>
                    </div>
                </div>
            </div>
        </div>
        @endforeach
    </div>
</div>

@endsection

@push('scripts')
<script>
    // Helper functions for inline classes
    function statusDotClass(statut) {
        const dots = {
            'valide': 'bg-emerald-500',
            'en_cours': 'bg-amber-500', 
            'ajourne': 'bg-amber-500',
            'abandon': 'bg-rose-500'
        };
        return dots[statut] || 'bg-slate-400';
    }

    function badgeStatusClass(statut) {
        const badges = {
            'valide': 'bg-emerald-100 text-emerald-800',
            'en_cours': 'bg-amber-100 text-amber-800',
            'ajourne': 'bg-amber-100 text-amber-800',
            'abandon': 'bg-rose-100 text-rose-800'
        };
        return badges[statut] || 'bg-slate-100 text-slate-700';
    }

    function moyenneColorClass(moyenne) {
        return moyenne >= 10 ? 'text-emerald-600' : 'text-rose-600';
    }

    function decisionColorClass(decision) {
        if (!decision) return 'text-slate-500';
        const lower = decision.toLowerCase();
        if (lower.includes('valid') || lower.includes('admis')) return 'text-emerald-600';
        if (lower.includes('rattrap') || lower.includes('non valid')) return 'text-amber-600';
        return 'text-rose-600';
    }
</script>
@endpush