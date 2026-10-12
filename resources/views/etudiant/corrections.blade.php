@extends('layouts.app')

@section('title', 'Compléments et rectifications — Espace Étudiant')
@section('meta_description', 'Soumettez les informations et pièces justificatives demandées par le service de scolarité pour compléter votre dossier.')

@section('content')
<div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 py-10 sm:py-14">

    {{-- ── En-tête de navigation ── --}}
    <div class="mb-8">
        <a href="{{ route('etudiant.suivi') }}"
           class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-500 hover:text-primary-600 transition-smooth">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
            </svg>
            Retour au suivi de dossier
        </a>
        <h1 class="mt-3 text-2xl sm:text-3xl font-black tracking-tight text-slate-900">
            Compléments et rectifications du dossier
        </h1>
        <p class="mt-2 text-sm text-slate-600">
            Dossier n° <span class="font-mono font-bold text-slate-800">{{ $etudiant->num_inscription }}</span>
            · {{ $etudiant->nom_complet }}
            · <span class="font-medium text-slate-600">{{ $etudiant->faculte?->nom ?? 'Faculté universitaire' }}</span>
        </p>
    </div>

    {{-- ── Messages flash ── --}}
    @if(session('status'))
        <div class="mb-6 rounded-2xl border border-emerald-300 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900 shadow-xs">
            <div class="flex items-center gap-2">
                <svg class="h-5 w-5 text-emerald-600 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <span>{{ session('status') }}</span>
            </div>
        </div>
    @endif

    @if($errors->any())
        <div class="mb-6 rounded-2xl border border-rose-300 bg-rose-50 p-4 text-sm font-semibold text-rose-900 shadow-xs">
            <div class="flex items-start gap-2">
                <svg class="h-5 w-5 text-rose-600 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
                <div>
                    <p class="font-bold">Des erreurs empêchent l'enregistrement de vos corrections :</p>
                    <ul class="mt-1 list-disc list-inside space-y-0.5 text-xs text-rose-800 font-normal">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    @endif

    {{-- ── CAS 1 : Dossier clôturé (décision définitive) ── --}}
    @if(! $elements['est_modifiable'])
        <div class="rounded-3xl border border-slate-200 bg-white p-8 text-center shadow-sm">
            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full {{ $etudiant->statut === 'valide' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' }}">
                @if($etudiant->statut === 'valide')
                    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                @else
                    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                @endif
            </div>
            <h2 class="mt-4 text-lg font-black text-slate-900">
                Modification impossible dans l'état actuel
            </h2>
            <p class="mt-2 text-sm text-slate-600 max-w-lg mx-auto">
                {{ $elements['message_statut'] }}
            </p>
            <div class="mt-6 flex justify-center gap-3">
                <a href="{{ route('etudiant.suivi') }}"
                   class="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-5 py-2.5 text-xs font-bold text-white hover:bg-slate-800 transition-smooth min-h-[44px]">
                    Consulter le suivi de dossier
                </a>
            </div>
        </div>

    {{-- ── CAS 2 : Aucun complément en attente ── --}}
    @elseif(! $elements['a_des_demandes'])
        <div class="rounded-3xl border border-slate-200 bg-white p-8 text-center shadow-sm">
            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-sky-100 text-sky-700">
                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
            <h2 class="mt-4 text-lg font-black text-slate-900">
                Aucun complément requis pour le moment
            </h2>
            <p class="mt-2 text-sm text-slate-600 max-w-md mx-auto">
                Le service de scolarité n'a formulé aucune demande de rectification sur votre dossier. Votre demande suit son cours d'instruction normal.
            </p>
            <div class="mt-6 flex justify-center">
                <a href="{{ route('etudiant.suivi') }}"
                   class="inline-flex items-center gap-2 rounded-xl bg-primary-600 px-5 py-2.5 text-xs font-bold text-white hover:bg-primary-700 transition-smooth min-h-[44px]">
                    Revenir au suivi de dossier
                </a>
            </div>
        </div>

    {{-- ── CAS 3 : Formulaire de correction actif ── --}}
    @else
        <form method="POST" action="{{ route('etudiant.corrections.store') }}" enctype="multipart/form-data" class="space-y-8">
            @csrf

            {{-- Bandeau explicatif --}}
            <div class="rounded-2xl border border-amber-300 bg-amber-50/80 p-5 shadow-xs">
                <div class="flex items-start gap-3">
                    <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-amber-200 text-amber-900 font-black text-sm flex-shrink-0">
                        !
                    </span>
                    <div>
                        <h2 class="text-sm font-black text-amber-950">
                            {{ $elements['total_elements'] }} élément(s) à corriger ou compléter
                        </h2>
                        <p class="mt-1 text-xs text-amber-900/90 leading-relaxed">
                            Pour éviter tout blocage ou rejet de votre inscription, veuillez renseigner soigneusement les champs demandés et téléverser des documents lisibles et conformes. Vos éléments seront ré-analysés immédiatement après soumission.
                        </p>
                    </div>
                </div>
            </div>

            {{-- ── Section 1 : Champs d'information à rectifier ── --}}
            @if(! empty($elements['champs']))
                <div class="rounded-3xl border border-slate-200/90 bg-white p-6 sm:p-8 shadow-sm space-y-6">
                    <div class="border-b border-slate-100 pb-4">
                        <h3 class="text-base font-black text-slate-900 flex items-center gap-2">
                            <span class="flex h-6 w-6 items-center justify-center rounded-lg bg-primary-100 text-primary-800 text-xs font-black">1</span>
                            Informations personnelles à rectifier
                        </h3>
                        <p class="mt-1 text-xs text-slate-500">
                            Modifiez uniquement les champs expressément signalés par la scolarité ci-dessous.
                        </p>
                    </div>

                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        @foreach($elements['champs'] as $champ)
                            <div class="sm:col-span-2 rounded-2xl border border-slate-200/80 bg-slate-50/60 p-4 sm:p-5">
                                <div class="flex flex-wrap items-baseline justify-between gap-2">
                                    <label for="champ_{{ $champ['champ'] }}" class="text-xs font-bold uppercase tracking-wider text-slate-700">
                                        {{ $champ['champ_libelle'] }} <span class="text-rose-500">*</span>
                                    </label>
                                    <span class="rounded-md bg-amber-100 px-2 py-0.5 text-[11px] font-bold text-amber-900 border border-amber-200">
                                        Demandé par la scolarité
                                    </span>
                                </div>

                                {{-- Motif de la demande --}}
                                <div class="mt-2 rounded-xl bg-white border border-amber-200/80 p-3 text-xs text-amber-950">
                                    <span class="font-bold text-amber-800">Motif :</span> {{ $champ['motif'] }}
                                </div>

                                <div class="mt-3">
                                    @if($champ['champ'] === 'date_naissance')
                                        <input type="date"
                                               id="champ_{{ $champ['champ'] }}"
                                               name="champs[{{ $champ['champ'] }}]"
                                               value="{{ old('champs.'.$champ['champ'], $champ['valeur_actuelle']) }}"
                                               required
                                               class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm font-semibold text-slate-900 focus:border-primary-500 focus:ring-2 focus:ring-primary-200">
                                    @elseif($champ['champ'] === 'sexe')
                                        <select id="champ_{{ $champ['champ'] }}"
                                                name="champs[{{ $champ['champ'] }}]"
                                                required
                                                class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm font-semibold text-slate-900 focus:border-primary-500 focus:ring-2 focus:ring-primary-200">
                                            <option value="M" {{ old('champs.'.$champ['champ'], $champ['valeur_actuelle']) === 'M' ? 'selected' : '' }}>Masculin (M)</option>
                                            <option value="F" {{ old('champs.'.$champ['champ'], $champ['valeur_actuelle']) === 'F' ? 'selected' : '' }}>Féminin (F)</option>
                                        </select>
                                    @else
                                        <input type="text"
                                               id="champ_{{ $champ['champ'] }}"
                                               name="champs[{{ $champ['champ'] }}]"
                                               value="{{ old('champs.'.$champ['champ'], $champ['suggestion'] ?? $champ['valeur_actuelle']) }}"
                                               required
                                               class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm font-semibold text-slate-900 focus:border-primary-500 focus:ring-2 focus:ring-primary-200">
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- ── Section 2 : Pièces justificatives à renouveler ── --}}
            @if(! empty($elements['pieces']))
                <div class="rounded-3xl border border-slate-200/90 bg-white p-6 sm:p-8 shadow-sm space-y-6">
                    <div class="border-b border-slate-100 pb-4">
                        <h3 class="text-base font-black text-slate-900 flex items-center gap-2">
                            <span class="flex h-6 w-6 items-center justify-center rounded-lg bg-primary-100 text-primary-800 text-xs font-black">2</span>
                            Pièces justificatives à remplacer
                        </h3>
                        <p class="mt-1 text-xs text-slate-500">
                            Téléversez un nouveau document clair, conforme et lisible au format PDF ou image (max 5 Mo). L'ancienne version reste archivée dans l'historique de votre dossier.
                        </p>
                    </div>

                    <div class="space-y-5">
                        @foreach($elements['pieces'] as $piece)
                            <div class="rounded-2xl border border-rose-200/90 bg-rose-50/40 p-4 sm:p-5">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <span class="text-sm font-black text-slate-900 block">{{ $piece['title'] }}</span>
                                        <span class="text-xs text-slate-500 font-mono">Fichier initial : {{ $piece['original_filename'] ?? '—' }}</span>
                                    </div>
                                    <span class="rounded-md bg-rose-200/80 px-2 py-0.5 text-[11px] font-bold text-rose-900 border border-rose-300">
                                        Rejeté par la scolarité
                                    </span>
                                </div>

                                {{-- Motif de rejet --}}
                                <div class="mt-2.5 rounded-xl bg-white border border-rose-200 p-3 text-xs text-rose-950">
                                    <span class="font-bold text-rose-900">Motif de rejet :</span> {{ $piece['motif'] }}
                                </div>

                                {{-- Champ d'upload du nouveau fichier --}}
                                <div class="mt-4">
                                    <label for="piece_{{ $piece['id'] }}" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                                        Nouveau fichier de remplacement <span class="text-rose-500">*</span>
                                    </label>
                                    <input type="file"
                                           id="piece_{{ $piece['id'] }}"
                                           name="pieces[{{ $piece['id'] }}]"
                                           accept=".pdf,.jpg,.jpeg,.png"
                                           required
                                           class="w-full text-xs text-slate-700 file:mr-4 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100 cursor-pointer">
                                    <p class="mt-1 text-[11px] text-slate-500">
                                        Formats acceptés : PDF, JPEG ou PNG · Taille maximale : 5 Mo
                                    </p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- ── Validation et transmission ── --}}
            <div class="rounded-3xl border border-slate-200 bg-white p-6 sm:p-8 shadow-sm">
                <div class="flex items-start gap-3">
                    <input type="checkbox"
                           id="attestation_honneur"
                           name="attestation_honneur"
                           required
                           class="mt-1 h-4 w-4 rounded border-slate-300 text-primary-600 focus:ring-primary-500">
                    <label for="attestation_honneur" class="text-xs text-slate-700 leading-relaxed cursor-pointer select-none">
                        J'atteste sur l'honneur l'exactitude des informations et documents transmis. Je comprends que toute déclaration inexacte ou document falsifié expose à l'annulation immédiate de la procédure d'inscription.
                    </label>
                </div>

                <div class="mt-6 flex flex-col-reverse sm:flex-row items-center justify-end gap-3 pt-4 border-t border-slate-100">
                    <a href="{{ route('etudiant.suivi') }}"
                       class="w-full sm:w-auto text-center rounded-xl px-5 py-3 text-xs font-bold text-slate-600 hover:bg-slate-100 transition-smooth min-h-[44px] flex items-center justify-center">
                        Annuler
                    </a>
                    <button type="submit"
                            class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-xl bg-primary-600 px-6 py-3 text-xs font-bold text-white shadow-sm hover:bg-primary-700 transition-smooth min-h-[44px]">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        Transmettre mes compléments et re-soumettre
                    </button>
                </div>
            </div>
        </form>
    @endif

</div>
@endsection
