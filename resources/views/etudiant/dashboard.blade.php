@extends('layouts.app')

@section('title', 'Tableau de bord — Espace Étudiant')
@section('meta_description', 'Votre vie académique : cours du jour, démarches, documents et résultats.')

@section('content')
    <div class="mx-auto w-full py-2">
        {{-- Salutation (§6) --}}
        <section aria-labelledby="titre-bonjour">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                {{ \Carbon\Carbon::now()->translatedFormat('l d F Y') }}
            </p>
            <h1 id="titre-bonjour" class="mt-1 text-2xl sm:text-3xl font-black tracking-tight text-slate-900">
                Bonjour {{ $etudiant->prenom }} 👋
            </h1>
            <p class="mt-1 text-sm text-slate-500">Voici ce qui se passe dans votre parcours académique aujourd'hui.</p>
        </section>

        <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-4">
            {{-- Aujourd'hui --}}
            <section aria-labelledby="titre-aujourdhui" class="lg:col-span-2 ds-card p-5 sm:p-6">
                <div class="flex items-center justify-between gap-2">
                    <h2 id="titre-aujourdhui" class="text-base font-black text-slate-900">Aujourd'hui</h2>
                    @if(app(\App\Support\Modules\ModuleRegistry::class)->estActif('schedule'))
                        <a href="{{ route('etudiant.emploi-du-temps.semaine') }}" class="text-xs font-bold text-primary-800 underline underline-offset-2 hover:text-primary-600">Voir mon emploi du temps →</a>
                    @endif
                </div>
                @if(count($coursDuJour) > 0)
                    <ul class="mt-3 space-y-2">
                        @foreach($coursDuJour as $cours)
                            <li class="flex items-center justify-between gap-3 rounded-xl border px-4 py-2.5 text-sm
                                {{ $cours['statut'] === 'cancelled' ? 'border-rose-300 bg-rose-50' : 'border-slate-200 bg-slate-50/60' }}">
                                <span>
                                    <span class="block font-black tabular-nums text-slate-900">{{ $cours['debut'] }} → {{ $cours['fin'] }}</span>
                                    <span class="block font-bold text-slate-800">{{ $cours['matiere'] }}</span>
                                </span>
                                <span class="text-right text-xs text-slate-500">
                                    @if($cours['salle'])<span class="block">Salle <span class="font-mono">{{ $cours['salle'] }}</span></span>@endif
                                    @if($cours['statut'] === 'cancelled')
                                        <span class="font-black text-rose-700">✕ Annulé</span>
                                    @elseif($cours['statut'] !== 'scheduled')
                                        <span class="font-black text-amber-800">⚠ Modifié</span>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <div class="mt-3">
                        <x-empty-state message="Aucun cours aujourd'hui. Profitez de votre journée !" />
                    </div>
                @endif
            </section>

            {{-- Ma situation (inscription courante, Phase 9 §55) --}}
            <section aria-labelledby="titre-situation" class="ds-card p-5 sm:p-6">
                <h2 id="titre-situation" class="text-base font-black text-slate-900">Ma situation</h2>
                @if($derniereInscription !== null)
                    <p class="mt-2 text-sm font-bold text-slate-900">{{ $derniereInscription->programmeRef?->nom ?? $derniereInscription->filiere ?? '—' }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ $derniereInscription->niveauRef?->code ?? $derniereInscription->niveau }} · {{ $derniereInscription->anneeUniversitaire?->libelle }} · <x-status-badge :statut="$derniereInscription->statut" /></p>
                @else
                    <div class="mt-2 space-y-1">
                        <p class="text-xs font-bold text-slate-700">Dossier d'admission : <span class="font-mono text-slate-900">{{ $etudiant->num_inscription }}</span></p>
                        <p class="text-xs text-slate-500">Statut : <x-status-badge :statut="$etudiant->statut" /></p>
                    </div>
                @endif
                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <a href="{{ route('etudiant.suivi') }}" class="inline-flex items-center gap-1.5 text-xs font-bold text-primary-800 underline underline-offset-2 hover:text-primary-600">Suivi de mon dossier →</a>
                    @if($derniereInscription !== null)
                        <a href="{{ route('etudiant.inscriptions.index') }}" class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-500 hover:text-slate-700">Mes inscriptions</a>
                    @endif
                </div>
            </section>

            {{-- Dernier résultat (persisté, léger) --}}
            <section aria-labelledby="titre-resultat" class="ds-card p-5 sm:p-6">
                <h2 id="titre-resultat" class="text-base font-black text-slate-900">Dernier résultat</h2>                <p class="mt-2 text-3xl font-black tabular-nums text-slate-900">
                    {{ $derniereInscription?->moyenne_annuelle !== null ? number_format($derniereInscription->moyenne_annuelle, 2) : '—' }}<span class="text-base font-bold text-slate-400"> / 20</span>
                </p>
                <p class="mt-1 text-xs text-slate-500">
                    Crédits : <strong class="tabular-nums">{{ $derniereInscription !== null ? $derniereInscription->credits_obtenus : '—' }} / {{ $derniereInscription !== null ? $derniereInscription->credits_total : '—' }}</strong>
                </p>
                <p class="text-xs text-slate-500">{{ $derniereInscription?->anneeUniversitaire?->libelle ?? 'Aucune année académique' }} · {{ $derniereInscription?->decision ?? '—' }}</p>
                <a href="{{ route('etudiant.notes.index') }}" class="mt-3 inline-flex items-center gap-1.5 text-xs font-bold text-primary-800 underline underline-offset-2 hover:text-primary-600">Toutes mes notes →</a>
            </section>
        </div>

        <div class="mt-4 grid grid-cols-1 lg:grid-cols-2 gap-4">
            {{-- Mes démarches (timeline) --}}
            @if(app(\App\Support\Modules\ModuleRegistry::class)->estActif('online-requests'))
            <section aria-labelledby="titre-demarches" class="ds-card p-5 sm:p-6">
                <div class="flex items-center justify-between gap-2">
                    <h2 id="titre-demarches" class="text-base font-black text-slate-900">Mes démarches</h2>
                    <a href="{{ route('etudiant.demandes.index') }}" class="text-xs font-bold text-primary-800 underline underline-offset-2 hover:text-primary-600">Toutes →</a>
                </div>
                @if($demarches->isNotEmpty())
                    @php
                        $etapes = ['Déposée', 'En traitement', 'Décision', 'Clôturée'];
                        $etapeDe = fn ($s) => in_array($s, ['draft']) ? 1 : (in_array($s, ['submitted', 'received', 'in_review', 'waiting_student']) ? 2 : (in_array($s, ['approved', 'rejected']) ? 3 : 4));
                    @endphp
                    <ol class="mt-4 space-y-4">
                        @foreach($demarches as $demarche)
                            @php $etape = $etapeDe($demarche->status); $echouee = $demarche->status === 'rejected'; @endphp
                            <li>
                                <div class="flex items-center justify-between gap-2 text-sm">
                                    <p class="min-w-0">
                                        <span class="block truncate font-bold text-slate-900">{{ $demarche->type->name }}</span>
                                        <span class="block font-mono text-[11px] text-slate-500">{{ $demarche->reference }}</span>
                                    </p>
                                    <x-status-badge :statut="$demarche->status" />
                                </div>
                                <div class="mt-2 flex items-center gap-1" aria-hidden="true">
                                    @for($i = 1; $i <= 4; $i++)
                                        <span class="h-1.5 flex-1 rounded-full {{ $i < $etape ? 'bg-emerald-500' : ($i === $etape ? ($echouee ? 'bg-rose-500' : 'bg-primary-700') : 'bg-slate-200') }}"></span>
                                    @endfor
                                </div>
                                <p class="sr-only">Étape {{ $etape }} sur 4 : {{ $etapes[$etape - 1] }}</p>
                            </li>
                        @endforeach
                    </ol>
                @else
                    <div class="mt-3">
                        <x-empty-state message="Aucune démarche pour le moment." />
                    </div>
                    <a href="{{ route('etudiant.demandes.create') }}" class="mt-3 inline-flex items-center gap-1.5 text-xs font-bold text-primary-800 underline underline-offset-2 hover:text-primary-600">Faire une demande →</a>
                @endif
            </section>
            @endif

            {{-- Documents récents --}}
            <section aria-labelledby="titre-docs" class="ds-card p-5 sm:p-6">
                <div class="flex items-center justify-between gap-2">
                    <h2 id="titre-docs" class="text-base font-black text-slate-900">Documents</h2>
                    <a href="{{ route('etudiant.documents.index') }}" class="text-xs font-bold text-primary-800 underline underline-offset-2 hover:text-primary-600">Tous →</a>
                </div>
                @if($documentsRecents->isNotEmpty())
                    <ul class="mt-3 space-y-2">
                        @foreach($documentsRecents as $document)
                            <li class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 px-4 py-2.5 text-sm">
                                <span class="min-w-0">
                                    <span class="block truncate font-bold text-slate-800">{{ $document->nom_original }}</span>
                                    <span class="block text-[11px] text-slate-500">{{ $document->created_at->format('d/m/Y') }}</span>
                                </span>
                                <a href="{{ route('etudiant.archives.documents.download', $document) }}" class="flex-shrink-0 text-xs font-bold text-primary-800 underline underline-offset-2 hover:text-primary-600">Télécharger</a>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <div class="mt-3">
                        <x-empty-state message="Aucun document disponible pour le moment." />
                    </div>
                @endif
            </section>
        </div>

        {{-- Modules : widgets complémentaires --}}
        @php $widgetsEtudiant = app(\App\Support\Modules\ModuleRegistry::class)->widgets('etudiant'); @endphp
        @if(count($widgetsEtudiant) > 0)
            <div class="mt-4 grid grid-cols-1 lg:grid-cols-2 gap-4">
                @foreach($widgetsEtudiant as $widget)
                    @include($widget)
                @endforeach
            </div>
        @endif
    </div>
@endsection
