@extends('layouts.app')

@section('title', 'Mon dossier — Espace Étudiant')
@section('meta_description', 'Votre dossier administratif : documents, statuts et historique.')

@section('content')
    <div class="mx-auto max-w-5xl px-4 sm:px-6 py-8 sm:py-12">
        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Espace étudiant</p>
        <h1 class="mt-1 text-2xl sm:text-3xl font-black tracking-tight text-slate-900">Mon dossier</h1>
        <p class="mt-1 font-mono text-sm text-slate-500">Référence {{ $dossier->reference }}</p>

        {{-- LOOP 16 : porte d'entrée d'aide humaine quand l'automatisation ne
             suffit pas (pièce rejetée, checklist incomplète) — moteur unique
             OnlineRequests, masqué si le module est inactif. --}}
        @if(app(\App\Support\Modules\ModuleRegistry::class)->estActif('online-requests'))
            <a href="{{ route('etudiant.demandes.create') }}"
               class="mt-3 inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2 text-xs font-bold text-slate-700 shadow-xs hover:border-primary-400 hover:text-primary-800 transition">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.328-.671.447-.714.388-1.148 1.05-1.148 1.796v.322m0 3.204.001.008M18 12a6 6 0 1 1-12 0 6 6 0 0 1 12 0Z" />
                </svg>
                Besoin d'aide ? Demander assistance à la scolarité
            </a>
        @endif

        @if(session('success'))
            <div class="mt-4 rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-xs font-bold text-emerald-900" role="status">
                ✓ {{ session('success') }}
            </div>
        @endif
        @if($errors->any())
            <div class="mt-4 rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-xs text-rose-800" role="alert">
                <ul class="list-disc list-inside">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="mt-6 grid grid-cols-1 sm:grid-cols-3 gap-3">
            <x-stat-card libelle="Validés" :valeur="$dossier->documents->where('status', 'validated')->count()" detail="Documents acceptés" couleur="emerald" />
            <x-stat-card libelle="En attente" :valeur="$dossier->documents->where('status', 'pending')->count()" detail="En cours de traitement" couleur="amber" />
            <x-stat-card libelle="Refusés" :valeur="$dossier->documents->where('status', 'rejected')->count()" detail="À corriger" couleur="rose" />
        </div>

        {{-- LOOP-UNIREGISTRE-DOCUMENTS-REUTILISABLES-01 : état de l'inscription
             annuelle ouverte. Les pièces validées du dossier permanent sont
             réutilisées ; seules les pièces réellement manquantes, refusées,
             expirées ou non conformes sont demandées. --}}
        @if(($reutilisation ?? null) !== null)
            @php
                $anneeLibelle = $inscriptionCourante?->anneeUniversitaire?->libelle ?? 'année en cours';
                $piecesValides = collect($reutilisation['pieces'])->filter(
                    fn ($piece) => in_array($piece['decision'], ['reutilise', 'associe'], true)
                );
                $piecesEnAttente = collect($reutilisation['pieces'])->filter(
                    fn ($piece) => $piece['obligatoire'] === true
                        && $piece['decision'] === 'en_attente'
                );
                $piecesACompleter = collect($reutilisation['pieces'])->filter(
                    fn ($piece) => $piece['obligatoire'] === true
                        && in_array($piece['decision'], ['manquant', 'rejete', 'expire', 'non_conforme'], true)
                );
            @endphp
            <section aria-label="Pièces de l'inscription {{ $anneeLibelle }}" class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-base font-black uppercase tracking-wider text-slate-900">Inscription {{ $anneeLibelle }} — pièces</h2>
                    <span class="text-xs font-bold {{ $reutilisation['complet'] ? 'text-emerald-700' : 'text-amber-700' }}">
                        {{ $reutilisation['complet'] ? '✓ Aucune pièce à fournir' : $reutilisation['progression'] }}
                    </span>
                </div>
                <p class="mt-1 text-xs text-slate-500">
                    Les pièces validées de votre dossier permanent sont réutilisées automatiquement : aucun nouvel envoi n’est nécessaire.
                    Seules les pièces ci-dessous sont demandées.
                </p>

                @if($piecesValides->isNotEmpty())
                    <h3 class="mt-3 text-[11px] font-bold uppercase tracking-wider text-slate-400">Réutilisées / déjà validées</h3>
                    <ul class="mt-1.5 space-y-1.5 text-sm">
                        @foreach($piecesValides as $piece)
                            <li class="flex flex-wrap items-center gap-2 rounded-lg bg-emerald-50 px-3 py-1.5 text-emerald-800">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-600" aria-hidden="true"></span>
                                <span class="font-bold">{{ $piece['libelle'] }}</span>
                                <span class="text-xs">{{ $piece['decision_libelle'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if($piecesEnAttente->isNotEmpty())
                    <h3 class="mt-3 text-[11px] font-bold uppercase tracking-wider text-slate-400">En attente de contrôle</h3>
                    <ul class="mt-1.5 space-y-1.5 text-sm">
                        @foreach($piecesEnAttente as $piece)
                            <li class="rounded-lg bg-amber-50 px-3 py-1.5 text-amber-800">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true"></span>
                                    <span class="font-bold">{{ $piece['libelle'] }}</span>
                                    <span class="text-xs font-semibold">{{ $piece['decision_libelle'] }}</span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-2 text-xs text-slate-500">Aucun nouvel envoi n’est nécessaire : la scolarité doit contrôler la pièce déjà transmise.</p>
                @endif

                @if($piecesACompleter->isNotEmpty())
                    <h3 class="mt-3 text-[11px] font-bold uppercase tracking-wider text-slate-400">À fournir ou à renouveler</h3>
                    <ul class="mt-1.5 space-y-1.5 text-sm">
                        @foreach($piecesACompleter as $piece)
                            <li class="rounded-lg bg-rose-50 px-3 py-1.5 text-rose-800">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true"></span>
                                    <span class="font-bold">{{ $piece['libelle'] }}</span>
                                    <span class="text-xs font-semibold">{{ $piece['decision_libelle'] }}</span>
                                </div>
                                @if($piece['motif'])
                                    <p class="mt-0.5 text-[11px]">{{ $piece['motif'] }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-2 text-xs text-slate-500">Transmettez uniquement la pièce concernée via le formulaire ci-dessous ; les autres restent réutilisées.</p>
                @endif
            </section>
        @elseif(($checklist['requis'] ?? 0) > 0)
            <section aria-label="Pièces obligatoires" class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-base font-black uppercase tracking-wider text-slate-900">Pièces obligatoires</h2>
                    <span class="text-xs font-bold {{ $checklist['complet'] ? 'text-emerald-700' : 'text-amber-700' }}">
                        {{ $checklist['complet'] ? '✓ Dossier complet' : $checklist['progression'] }}
                    </span>
                </div>
                <ul class="mt-3 space-y-1.5 text-sm">
                    @foreach(['manquants' => ['Non fournie', 'text-rose-700', 'bg-rose-50'], 'rejetes' => ['Rejetée — à remplacer', 'text-rose-700', 'bg-rose-50'], 'en_attente' => ['Transmise — en attente de validation', 'text-amber-700', 'bg-amber-50'], 'expires' => ['Expirée — à remplacer', 'text-amber-700', 'bg-amber-50']] as $cle => [$libelle, $couleur, $fond])
                        @foreach($checklist[$cle] as $code)
                            <li class="flex items-center gap-2 rounded-lg {{ $fond }} px-3 py-1.5">
                                <span class="h-1.5 w-1.5 rounded-full bg-current {{ $couleur }}" aria-hidden="true"></span>
                                <span class="font-mono text-xs font-bold">{{ $code }}</span>
                                <span class="{{ $couleur }}">{{ $libelle }}</span>
                            </li>
                        @endforeach
                    @endforeach
                    @for($i = 0; $i < $checklist['valides']; $i++)
                        <li class="flex items-center gap-2 rounded-lg bg-emerald-50 px-3 py-1.5 text-emerald-700">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-600" aria-hidden="true"></span>
                            <span class="font-bold">Pièce validée</span>
                        </li>
                    @endfor
                </ul>
                <p class="mt-2 text-xs text-slate-500">Liste officielle calculée par la scolarité selon votre situation — les pièces validées ne sont jamais redemandées.</p>
            </section>
        @endif

        <section aria-label="Mon dossier PDF" class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-black uppercase tracking-wider text-slate-900">Mon dossier PDF</h2>
            <p class="mt-1 text-xs text-slate-500">Récapitulatif officiel de votre dossier (A4), à télécharger ou imprimer.</p>
            @if(($pdf ?? null) !== null)
                <p class="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-600">
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-emerald-300 bg-emerald-50 px-2.5 py-0.5 font-bold text-emerald-900">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-600" aria-hidden="true"></span>Disponible
                    </span>
                    <span class="font-mono">{{ $pdf->reference }}</span>
                    <span>v{{ $pdf->version }}</span>
                    <span class="tabular-nums">{{ $pdf->created_at->format('d/m/Y') }}</span>
                </p>
                <div class="mt-3 grid grid-cols-1 sm:grid-cols-3 gap-3" role="group" aria-label="Actions de mon dossier PDF">
                    <a href="{{ route('etudiant.dossier.officiels.index') }}"
                       aria-label="Voir mes relevés et attestations"
                       class="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-5 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-4 focus:ring-primary-500/25 transition min-h-[48px]">
                        Relevés et attestations
                    </a>
                    <a href="{{ route('etudiant.dossier.pdf.preview') }}"
                       aria-label="Prévisualiser mon dossier PDF"
                       class="inline-flex items-center justify-center gap-2 rounded-xl bg-primary-800 px-5 py-3 text-sm font-bold text-white hover:bg-primary-700 focus:outline-none focus:ring-4 focus:ring-primary-500/25 transition min-h-[48px]">
                        👁 Prévisualiser
                    </a>
                    <a href="{{ route('etudiant.dossier.pdf.download') }}"
                       aria-label="Télécharger mon dossier PDF"
                       class="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-5 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-4 focus:ring-primary-500/25 transition min-h-[48px]">
                        ⬇ Télécharger
                    </a>
                    <a href="{{ route('etudiant.dossier.pdf.preview') }}"
                       aria-label="Imprimer mon dossier"
                       class="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-5 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-4 focus:ring-primary-500/25 transition min-h-[48px]">
                        🖨 Imprimer
                    </a>
                </div>
            @else
                <p class="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-600">
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-slate-300 bg-slate-50 px-2.5 py-0.5 font-bold text-slate-600">
                        <span class="h-1.5 w-1.5 rounded-full bg-slate-400" aria-hidden="true"></span>Non généré
                    </span>
                    Votre PDF n'existe pas encore.
                </p>
                <form method="POST" action="{{ route('etudiant.dossier.pdf.generer') }}" class="mt-3"
                      x-data="{ generation: false }" @submit="generation = true">
                    @csrf
                    <button type="submit" :disabled="generation" aria-label="Générer mon dossier PDF"
                            class="inline-flex items-center justify-center gap-2 rounded-xl bg-primary-800 px-5 py-3 text-sm font-bold text-white hover:bg-primary-700 focus:outline-none focus:ring-4 focus:ring-primary-500/25 transition min-h-[48px] disabled:opacity-60">
                        <span x-show="!generation">Générer mon dossier PDF</span>
                        <span x-show="generation">Génération du PDF…</span>
                    </button>
                </form>
            @endif
        </section>

        <section aria-label="Transmettre un document" class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-black text-slate-900">Transmettre un document</h2>
            <form method="POST" action="{{ route('etudiant.dossier.documents.store') }}" enctype="multipart/form-data" class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
                @csrf
                <div>
                    <label for="fichier" class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Fichier (PDF, JPG, PNG, WebP — 10 Mo max)</label>
                    <input type="file" id="fichier" name="document" required accept=".pdf,.jpg,.jpeg,.png,.webp" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm min-h-[44px]">
                </div>
                <div>
                    <label for="titre" class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Titre</label>
                    <input type="text" id="titre" name="title" required maxlength="150" placeholder="Ex : CIN recto" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="categorie" class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Catégorie</label>
                    <select id="categorie" name="category" required class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        @foreach(config('student-dossiers.categories', []) as $code => $libelle)
                            <option value="{{ $code }}">{{ $libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="type" class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Type</label>
                    <select id="type" name="document_type" required class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        @foreach(config('student-dossiers.document_types', []) as $categorie => $types)
                            <optgroup label="{{ config('student-dossiers.categories.' . $categorie, $categorie) }}">
                                @foreach($types as $typeOption)
                                    <option value="{{ $typeOption }}">{{ $typeOption }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <button type="submit" class="rounded-xl bg-primary-800 px-5 py-2.5 text-sm font-bold text-white hover:bg-primary-700 min-h-[44px]">Transmettre</button>
                </div>
            </form>
        </section>

        @foreach(['validated' => 'Documents validés', 'pending' => 'Documents en attente', 'rejected' => 'Documents refusés'] as $statut => $titre)
            <section aria-label="{{ $titre }}" class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                <h2 class="px-5 py-3 border-b border-slate-100 text-sm font-black text-slate-900">{{ $titre }}</h2>
                @php $liste = $dossier->documents->where('status', $statut); @endphp
                @if($liste->isNotEmpty())
                    <ul class="divide-y divide-slate-100">
                        @foreach($liste as $document)
                            <li class="px-5 py-3">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-bold text-slate-900">{{ $document->title }}</p>
                                        <p class="text-[11px] text-slate-500">{{ $document->document_type }} · v{{ $document->versions->max('version') }}</p>
                                        @if($statut === 'rejected' && $document->rejected_reason)
                                            <p class="mt-1 text-[11px] font-semibold text-rose-700">Motif : {{ $document->rejected_reason }}</p>
                                        @endif
                                    </div>
                                    <x-status-badge :statut="$document->status" />
                                </div>
                                <div class="mt-2 flex flex-wrap items-center gap-2 text-xs font-bold">
                                    <a href="{{ route('etudiant.dossier.documents.preview', $document) }}" target="_blank" rel="noopener noreferrer" class="rounded-lg border border-primary-200 bg-primary-50 px-3 py-1.5 text-primary-800 hover:bg-primary-100">👁 Aperçu</a>
                                    <a href="{{ route('etudiant.dossier.documents.download', $document) }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-slate-700 hover:bg-slate-50">Télécharger</a>
                                    <form method="POST" action="{{ route('etudiant.dossier.documents.replace', $document) }}" enctype="multipart/form-data" class="flex items-center gap-1.5">
                                        @csrf
                                        <input type="file" name="document" required accept=".pdf,.jpg,.jpeg,.png,.webp" class="text-xs max-w-[180px]">
                                        <button type="submit" class="rounded-lg border border-slate-300 px-3 py-1.5 text-slate-600 hover:bg-slate-50">Remplacer</button>
                                    </form>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <div class="p-5">
                        <x-empty-state message="Aucun document dans cette rubrique." />
                    </div>
                @endif
            </section>
        @endforeach
    </div>
@endsection
