@extends('layouts.app')

@section('title', 'Mon Dossier Académique — Espace Étudiant')
@section('meta_description', 'Consultation de votre dossier académique : parcours, notes, moyennes, crédits et documents.')

@section('content')
<div class="pt-3 sm:pt-6">

    <div class="w-full overflow-hidden border-b border-slate-200/90 bg-white shadow-2xl shadow-slate-400/25">

        {{-- En-tête institutionnel --}}
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 border-b-2 border-slate-800/90 px-6 py-5 sm:px-10 lg:px-16 xl:px-20">
            <div class="flex items-center gap-4">
                <div class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-xl border-2 border-slate-800/80 text-slate-900">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0 0 12 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75Z" />
                    </svg>
                </div>
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-400">Université — Service Central de la Scolarité</p>
                    <h1 class="font-serif text-xl sm:text-2xl font-bold text-slate-900">Mon Dossier Académique</h1>
                </div>
            </div>
            <a href="{{ route('etudiant.profil.show') }}"
               class="inline-flex items-center gap-1.5 self-start rounded-xl border border-slate-300 px-3.5 py-2 text-xs font-bold text-slate-600 hover:bg-slate-50 transition-smooth">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
                Mon profil
            </a>
        </div>
        <div class="h-px bg-gradient-to-r from-slate-300 via-slate-800/60 to-slate-300"></div>

        {{-- Navigation des sections en lecture seule (§3) --}}
        <div class="flex flex-wrap gap-1.5 border-b border-slate-100 bg-slate-50/60 px-6 py-2.5 sm:px-10 lg:px-16 xl:px-20">
            <span class="mr-1 self-center text-[10px] font-bold uppercase tracking-wider text-slate-400">Consulter :</span>
            @foreach([
                ['route' => 'etudiant.inscriptions.index', 'label' => 'Mes inscriptions'],
                ['route' => 'etudiant.notes.index', 'label' => 'Mes notes'],
                ['route' => 'etudiant.documents.index', 'label' => 'Mes documents'],
                ['route' => 'etudiant.historique.index', 'label' => 'Mon historique'],
            ] as $section)
                <a href="{{ route($section['route']) }}"
                   class="rounded-lg px-2.5 py-1 text-xs font-bold text-slate-500 transition-smooth hover:bg-slate-900 hover:text-white">
                    {{ $section['label'] }}
                </a>
            @endforeach
        </div>

        {{-- Identité --}}
        <div class="px-6 py-6 sm:px-10 sm:py-8 lg:px-16 xl:px-20">
            <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-5">
                    <div class="flex h-16 w-16 flex-shrink-0 items-center justify-center rounded-xl border-2 border-slate-800/80 bg-slate-50 text-xl font-black text-slate-800">
                        {{ mb_strtoupper(mb_substr($etudiant->prenom, 0, 1)) }}{{ mb_strtoupper(mb_substr($etudiant->nom, 0, 1)) }}
                    </div>
                    <div>
                        <h2 class="font-serif text-2xl font-bold text-slate-900">{{ $etudiant->nom_complet }}</h2>
                        <div class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500">
                            <span><span class="font-mono font-bold text-slate-700">{{ $dossier->matricule }}</span> · Matricule</span>
                            <span><span class="font-mono font-bold text-slate-700">{{ $etudiant->num_inscription }}</span> · Inscription</span>
                            <span class="font-semibold">{{ $etudiant->faculte->nom }}</span>
                        </div>
                    </div>
                </div>
                @if($dossier->statut === 'actif')
                    <div class="inline-flex rotate-2 items-center rounded-md border-[3px] border-emerald-600/80 bg-emerald-50/40 px-4 py-1.5 self-start">
                        <span class="text-sm font-black uppercase tracking-[0.25em] text-emerald-700/90">Actif</span>
                    </div>
                @else
                    <div class="inline-flex -rotate-2 items-center rounded-md border-[3px] border-slate-600/80 bg-slate-50 px-4 py-1.5 self-start">
                        <span class="text-sm font-black uppercase tracking-[0.25em] text-slate-600/90">Clos</span>
                    </div>
                @endif
            </div>
        </div>

        {{-- Synthèse --}}
        <div class="grid grid-cols-2 divide-y divide-slate-200 border-y border-slate-200 bg-slate-50/70 sm:grid-cols-4 sm:divide-x sm:divide-y-0">
            <div class="px-6 py-4 sm:px-10 lg:px-16 xl:px-20">
                <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-400">Moyenne générale</p>
                <p class="mt-1 text-2xl font-black {{ ($synthese['moyenne_generale'] ?? 0) >= 10 ? 'text-emerald-600' : 'text-slate-900' }}">{{ $synthese['moyenne_generale'] !== null ? number_format($synthese['moyenne_generale'], 2) : '—' }}<span class="text-sm text-slate-400">/20</span></p>
            </div>
            <div class="px-6 py-4 sm:px-10 lg:px-16 xl:px-20">
                <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-400">Crédits obtenus</p>
                <p class="mt-1 text-2xl font-black text-slate-900">{{ $synthese['credits_obtenus'] }}<span class="text-sm text-slate-400">/ {{ $synthese['credits_total'] }}</span></p>
            </div>
            <div class="px-6 py-4 sm:px-10 lg:px-16 xl:px-20">
                <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-400">Années validées</p>
                <p class="mt-1 text-2xl font-black text-slate-900">{{ $synthese['annees_validees'] }}<span class="text-sm text-slate-400">/ {{ $synthese['annees_total'] }}</span></p>
            </div>
            <div class="px-6 py-4 sm:px-10 lg:px-16 xl:px-20">
                <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-400">Dernière décision</p>
                <p class="mt-1 text-sm font-bold text-slate-700">{{ $inscriptions->first()?->decision ?? 'En cours' }}</p>
            </div>
        </div>

        {{-- Parcours & notes --}}
        <div class="px-6 py-8 sm:px-10 sm:py-10 lg:px-16 xl:px-20 space-y-5">
            <div class="flex items-center gap-3 border-b border-slate-800/80 pb-2">
                <span class="font-serif text-sm font-bold text-slate-900">I.</span>
                <h3 class="text-[11px] font-bold uppercase tracking-[0.18em] text-slate-700">Parcours universitaire, notes & moyennes</h3>
            </div>

            @forelse($inscriptions as $inscription)
                <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden" x-data="{ open: {{ $loop->first ? 'true' : 'false' }} }">
                    <button type="button" @click="open = !open"
                            class="w-full flex flex-col sm:flex-row sm:items-center gap-3 px-5 py-4 text-left hover:bg-slate-50/70 transition-smooth">
                        <div class="flex items-center gap-4 flex-1 min-w-0">
                            <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl font-black text-xs
                                {{ $inscription->statut === 'valide' ? 'bg-emerald-100 text-emerald-700' : ($inscription->statut === 'ajourne' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700') }}">
                                {{ $inscription->niveau }}
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm font-bold text-slate-900">{{ $inscription->anneeUniversitaire->libelle }} — {{ $inscription->niveau }}</p>
                                <p class="text-[11px] text-slate-400 truncate">{{ $inscription->filiere ?? 'Filière non précisée' }}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-4 text-xs flex-shrink-0">
                            <div class="text-right">
                                <p class="font-black text-slate-900">{{ $inscription->moyenne_annuelle !== null ? number_format((float) $inscription->moyenne_annuelle, 2) : '—' }}<span class="text-slate-400">/20</span></p>
                                <p class="text-[10px] text-slate-400">Moyenne annuelle</p>
                            </div>
                            <svg class="h-4 w-4 text-slate-400 transition-transform" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                        </div>
                    </button>

                    <div x-show="open" x-cloak x-transition class="border-t border-slate-100 bg-slate-50/50 px-5 py-5 space-y-4">
                        @foreach($inscription->semestres as $semestre)
                            <div class="rounded-xl border border-slate-200 bg-white overflow-hidden">
                                <div class="px-4 py-3 border-b border-slate-100 flex flex-wrap items-center justify-between gap-2">
                                    <span class="rounded-lg bg-slate-900 px-2.5 py-1 text-[11px] font-black text-white">{{ $semestre->libelle }}</span>
                                    <span class="text-xs text-slate-500">
                                        Moyenne : <strong class="text-slate-800">{{ $semestre->moyenne !== null ? number_format((float) $semestre->moyenne, 2) : '—' }}</strong>
                                        · Crédits : <strong class="text-slate-800">{{ $semestre->credits_obtenus }}/{{ $semestre->credits_total }}</strong>
                                        · {{ $semestre->decision ?? '—' }}
                                    </span>
                                </div>
                                <div class="overflow-x-auto">
                                    <table class="w-full text-left text-xs">
                                        <thead class="text-[10px] uppercase tracking-wider text-slate-400 border-b border-slate-100">
                                            <tr>
                                                <th class="px-4 py-2.5">Matière</th>
                                                <th class="px-4 py-2.5">Coef.</th>
                                                <th class="px-4 py-2.5">Crédits</th>
                                                <th class="px-4 py-2.5">Session</th>
                                                <th class="px-4 py-2.5">Note</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-50">
                                            @forelse($semestre->notes as $note)
                                                <tr>
                                                    <td class="px-4 py-2.5"><span class="font-bold text-slate-800">{{ $note->matiere->code }}</span> <span class="text-slate-500">{{ $note->matiere->nom }}</span></td>
                                                    <td class="px-4 py-2.5 text-slate-500">{{ number_format((float) $note->coefficient, 1) }}</td>
                                                    <td class="px-4 py-2.5 text-slate-500">{{ $note->credits }}</td>
                                                    <td class="px-4 py-2.5">
                                                        <span class="rounded-full px-2 py-0.5 text-[10px] font-bold {{ $note->session === 'normale' ? 'bg-slate-100 text-slate-600' : 'bg-amber-100 text-amber-800' }}">{{ $note->session === 'normale' ? 'Normale' : 'Rattrapage' }}</span>
                                                    </td>
                                                    <td class="px-4 py-2.5"><span class="font-black {{ (float) $note->note >= 10 ? 'text-emerald-600' : 'text-rose-600' }}">{{ number_format((float) $note->note, 2) }}</span></td>
                                                </tr>
                                            @empty
                                                <tr><td colspan="5" class="px-4 py-5 text-center text-slate-400">Aucune note publiée.</td></tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="rounded-2xl border border-dashed border-slate-300 p-10 text-center text-sm text-slate-400">
                    Aucune année universitaire enregistrée dans votre dossier pour le moment.
                </div>
            @endforelse
        </div>

        {{-- Documents autorisés --}}
        <div class="border-t border-slate-200 px-6 py-8 sm:px-10 sm:py-10 lg:px-16 xl:px-20">
            <div class="mb-4 flex items-center gap-3 border-b border-slate-800/80 pb-2">
                <span class="font-serif text-sm font-bold text-slate-900">II.</span>
                <h3 class="text-[11px] font-bold uppercase tracking-[0.18em] text-slate-700">Mes documents officiels</h3>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                @forelse($documents as $document)
                    <div class="flex items-center gap-3.5 rounded-2xl border border-slate-200 bg-white p-4">
                        <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600 border border-primary-100 font-black text-[10px] uppercase">
                            {{ \Illuminate\Support\Str::afterLast($document->nom_original, '.') }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-xs font-bold text-slate-900">{{ $document->nom_original }}</p>
                            <p class="text-[10px] text-slate-400">
                                {{ \App\Services\DocumentService::TYPES_DOCUMENTS[$document->type_document] ?? $document->type_document }} · {{ $document->taille_lisible }} · {{ $document->created_at->format('d/m/Y') }}
                            </p>
                        </div>
                        <a href="{{ route('etudiant.archives.documents.download', $document) }}"
                           class="flex-shrink-0 rounded-lg bg-slate-900 px-2.5 py-1.5 text-[10px] font-bold text-white hover:bg-slate-800 transition-smooth">Télécharger</a>
                    </div>
                @empty
                    <div class="md:col-span-2 rounded-2xl border border-dashed border-slate-300 p-8 text-center text-xs text-slate-400">
                        Aucun document n'est encore disponible dans votre dossier.
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
