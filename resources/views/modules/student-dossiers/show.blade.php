@extends('layouts.app')

@section('title', 'Dossier ' . $dossier->reference . ' — Scolarité')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8 sm:py-10">
        <a href="{{ route('admin.student-dossiers.index') }}" class="text-xs font-bold text-primary-800 underline underline-offset-2 hover:text-primary-600">← Registre des dossiers</a>

        <div class="mt-3 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Dossier permanent</p>
                    <h1 class="mt-1 font-mono text-2xl font-black text-slate-900">{{ $dossier->reference }}</h1>
                    <p class="mt-1 text-sm text-slate-600">
                        <strong class="text-slate-900">{{ $dossier->etudiant->nom_complet }}</strong>
                        · <span class="font-mono">{{ $dossier->etudiant->cin }}</span>
                        · {{ $dossier->etudiant->faculte?->nom }} · {{ $dossier->etudiant->niveau }}
                    </p>
                </div>
                <x-status-badge :statut="$dossier->status" />
            </div>

            @can('student_dossiers.pdf')
                <section aria-label="Document PDF du dossier" class="mt-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="text-sm font-black uppercase tracking-wider text-slate-900">Document PDF du dossier</h2>
                    @if($pdf !== null)
                        <p class="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-600">
                            <span class="inline-flex items-center gap-1.5 rounded-full border border-emerald-300 bg-emerald-50 px-2.5 py-0.5 font-bold text-emerald-900">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-600" aria-hidden="true"></span>Disponible
                            </span>
                            <span class="font-mono">{{ $pdf->reference }}</span>
                            <span>v{{ $pdf->version }}</span>
                            <span class="tabular-nums">{{ $pdf->created_at->format('d/m/Y H:i') }}</span>
                        </p>
                        <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2" role="group" aria-label="Actions du dossier PDF">
                            <a href="{{ route('admin.student-dossiers.pdf.preview', $dossier) }}" aria-label="Prévisualiser le dossier PDF"
                               class="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-4 focus:ring-primary-500/25 min-h-[44px]">👁 Prévisualiser</a>
                            <a href="{{ route('admin.student-dossiers.pdf.download', $dossier) }}" aria-label="Télécharger le dossier PDF"
                               class="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-4 focus:ring-primary-500/25 min-h-[44px]">⬇ Télécharger PDF</a>
                            <a href="{{ route('admin.student-dossiers.pdf.preview', $dossier) }}" aria-label="Imprimer le dossier"
                               class="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-4 focus:ring-primary-500/25 min-h-[44px]">🖨 Imprimer</a>
                            <form method="POST" action="{{ route('admin.student-dossiers.pdf.generer', $dossier) }}"
                                  x-data="{ regeneration: false }" @submit="regeneration = true">
                                @csrf
                                <input type="hidden" name="force" value="1">
                                <button type="submit" :disabled="regeneration" aria-label="Régénérer le dossier PDF"
                                        class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-primary-800 px-4 py-2.5 text-xs font-bold text-white hover:bg-primary-700 focus:outline-none focus:ring-4 focus:ring-primary-500/25 min-h-[44px] disabled:opacity-60">
                                    <span x-show="!regeneration">Régénérer</span>
                                    <span x-show="regeneration">Génération du PDF…</span>
                                </button>
                            </form>
                        </div>
                    @else
                        <p class="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-600">
                            <span class="inline-flex items-center gap-1.5 rounded-full border border-slate-300 bg-slate-50 px-2.5 py-0.5 font-bold text-slate-600">
                                <span class="h-1.5 w-1.5 rounded-full bg-slate-400" aria-hidden="true"></span>Non généré
                            </span>
                            Aucun PDF pour ce dossier pour le moment.
                        </p>
                        <form method="POST" action="{{ route('admin.student-dossiers.pdf.generer', $dossier) }}" class="mt-3"
                              x-data="{ generation: false }" @submit="generation = true">
                            @csrf
                            <button type="submit" :disabled="generation" aria-label="Générer le dossier PDF"
                                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-primary-800 px-5 py-3 text-sm font-bold text-white hover:bg-primary-700 focus:outline-none focus:ring-4 focus:ring-primary-500/25 transition min-h-[48px] disabled:opacity-60">
                                <span x-show="!generation">Générer PDF</span>
                                <span x-show="generation">Génération du PDF…</span>
                            </button>
                        </form>
                    @endif
                </section>
            @endcan

            @can('student_documents.upload')
                <details class="mt-5 rounded-xl border border-slate-200 bg-slate-50/60">
                    <summary class="cursor-pointer px-4 py-3 text-sm font-bold text-slate-800">Téléverser un document</summary>
                    <form method="POST" action="{{ route('admin.student-dossiers.documents.store', $dossier) }}" enctype="multipart/form-data" class="grid grid-cols-1 sm:grid-cols-2 gap-3 p-4 pt-0">
                        @csrf
                        <div>
                            <label for="fichier" class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Fichier (PDF, JPG, PNG, WebP — 10 Mo max)</label>
                            <input type="file" id="fichier" name="document" required accept=".pdf,.jpg,.jpeg,.png,.webp"
                                   class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label for="titre" class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Titre</label>
                            <input type="text" id="titre" name="title" required maxlength="150" placeholder="Ex : CIN recto"
                                   class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
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
                            <label for="description" class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Description (facultatif)</label>
                            <input type="text" id="description" name="description" maxlength="500" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        </div>
                        <div class="sm:col-span-2">
                            <button type="submit" class="rounded-xl bg-primary-800 px-5 py-2.5 text-sm font-bold text-white hover:bg-primary-700 min-h-[44px]">Téléverser</button>
                        </div>
                    </form>
                </details>
            @endcan
        </div>

        {{-- LOOP-UNIREGISTRE-DOCUMENTS-REUTILISABLES-01 : état documentaire
             de la dernière inscription ouverte (lecture seule). --}}
        @if(($reutilisation ?? null) !== null)
            @php
                $anneeLibelle = $inscriptionCourante?->anneeUniversitaire?->libelle ?? 'année en cours';
                $piecesAControler = collect($reutilisation['pieces'])->filter(
                    fn ($piece) => $piece['obligatoire'] === true
                        && in_array($piece['decision'], ['manquant', 'rejete', 'expire', 'non_conforme', 'en_attente'], true)
                );
                $piecesValides = collect($reutilisation['pieces'])->filter(
                    fn ($piece) => in_array($piece['decision'], ['reutilise', 'associe'], true)
                );
            @endphp
            <section aria-label="Pièces de l'inscription {{ $anneeLibelle }}" class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-sm font-black uppercase tracking-wider text-slate-900">Inscription {{ $anneeLibelle }} — pièces</h2>
                    <span class="text-xs font-bold {{ $reutilisation['complet'] ? 'text-emerald-700' : 'text-amber-700' }}">
                        {{ $reutilisation['complet'] ? '✓ Aucune pièce à renouveler' : $reutilisation['progression'] }}
                    </span>
                </div>
                <p class="mt-1 text-xs text-slate-500">Réutilisation automatique du dossier permanent : aucun fichier n’est dupliqué.</p>

                @if($piecesValides->isNotEmpty())
                    <p class="mt-2 text-xs text-emerald-800">
                        <span class="font-bold">Réutilisées / validées :</span>
                        {{ $piecesValides->pluck('libelle')->implode(' · ') }}
                    </p>
                @endif

                @if($piecesAControler->isNotEmpty())
                    <h3 class="mt-3 text-[11px] font-bold uppercase tracking-wider text-slate-400">Pièces à contrôler ou à renouveler</h3>
                    <ul class="mt-2 space-y-1 text-xs">
                        @foreach($piecesAControler as $piece)
                            <li class="rounded-lg px-2.5 py-1.5 {{ $piece['decision'] === 'en_attente' ? 'bg-amber-50 text-amber-800' : 'bg-rose-50 text-rose-800' }}">
                                <span class="font-bold">{{ $piece['libelle'] }}</span> — {{ $piece['decision_libelle'] }}
                                @if($piece['motif'])<span class="text-[11px]">({{ $piece['motif'] }})</span>@endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif

        <section aria-label="Documents du dossier" class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <h2 class="px-5 py-4 border-b border-slate-100 text-base font-black text-slate-900">Documents ({{ $dossier->documents->count() }})</h2>
            @if($dossier->documents->isNotEmpty())
                <ul class="divide-y divide-slate-100">
                    @foreach($dossier->documents as $document)
                        <li class="px-5 py-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-bold text-slate-900">{{ $document->title }}</p>
                                    <p class="text-[11px] text-slate-500">
                                        {{ config('student-dossiers.categories.' . $document->category, $document->category) }}
                                        · {{ $document->document_type }} · {{ $document->original_filename }}
                                        · v{{ $document->versions->max('version') }} · {{ number_format($document->size / 1024, 1) }} Ko
                                    </p>
                                    @if($document->status === 'rejected' && $document->rejected_reason)
                                        <p class="mt-1 text-[11px] font-semibold text-rose-700">Motif du rejet : {{ $document->rejected_reason }}</p>
                                    @endif
                                </div>
                                <x-status-badge :statut="$document->status" />
                            </div>
                            <div class="mt-2 flex flex-wrap gap-2 text-xs font-bold">
                                @can('student_documents.download', $document)
                                    <a href="{{ route('admin.student-dossiers.documents.download', [$dossier, $document]) }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-slate-700 hover:bg-slate-50">Télécharger</a>
                                @endcan
                                @can('student_documents.validate', $document)
                                    @if($document->status === 'pending')
                                        <form method="POST" action="{{ route('admin.student-dossiers.documents.validate', $document) }}" class="inline">
                                            @csrf @method('PATCH')
                                            <button type="submit" class="rounded-lg border border-emerald-300 px-3 py-1.5 text-emerald-700 hover:bg-emerald-50">Valider</button>
                                        </form>
                                    @endif
                                @endcan
                                @can('student_documents.reject', $document)
                                    @if(in_array($document->status, ['pending', 'validated'], true))
                                        <form method="POST" action="{{ route('admin.student-dossiers.documents.reject', $document) }}" class="flex flex-wrap items-center gap-1.5">
                                            @csrf @method('PATCH')
                                            <input type="text" name="motif" required maxlength="500" placeholder="Motif du rejet (obligatoire)"
                                                   class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs font-normal min-w-[200px]">
                                            <button type="submit" class="rounded-lg border border-amber-300 px-3 py-1.5 text-amber-800 hover:bg-amber-50">Rejeter</button>
                                        </form>
                                    @endif
                                @endcan
                                @can('student_documents.delete', $document)
                                    <form method="POST" action="{{ route('admin.student-dossiers.documents.destroy', $document) }}" class="inline"
                                          onsubmit="return confirm('Supprimer définitivement ce document et toutes ses versions ?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="rounded-lg border border-rose-300 px-3 py-1.5 text-rose-700 hover:bg-rose-50">Supprimer</button>
                                    </form>
                                @endcan
                            </div>
                            @can('student_documents.replace', $document)
                                <form method="POST" action="{{ route('admin.student-dossiers.documents.replace', $document) }}" enctype="multipart/form-data" class="mt-2 flex flex-wrap items-center gap-1.5 text-xs">
                                    @csrf
                                    <input type="file" name="document" required accept=".pdf,.jpg,.jpeg,.png,.webp" class="text-xs">
                                    <button type="submit" class="rounded-lg border border-slate-300 px-3 py-1.5 font-bold text-slate-600 hover:bg-slate-50">Remplacer (nouvelle version)</button>
                                </form>
                            @endcan
                            @if($document->versions->count() > 1)
                                <details class="mt-2 text-[11px] text-slate-500">
                                    <summary class="cursor-pointer font-bold">Historique des versions ({{ $document->versions->count() }})</summary>
                                    <ul class="mt-1 space-y-0.5">
                                        @foreach($document->versions as $version)
                                            <li class="font-mono">v{{ $version->version }} · {{ $version->original_filename }} · {{ $version->created_at->format('d/m/Y H:i') }}</li>
                                        @endforeach
                                    </ul>
                                </details>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @else
                <div class="p-5">
                    <x-empty-state message="Aucun document dans ce dossier." />
                </div>
            @endif
        </section>

        @if($peutVoirAudit)
            <section aria-label="Audit du dossier" class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-base font-black text-slate-900">Audit (30 dernières actions)</h2>
                @if($audit->isNotEmpty())
                    <ul class="mt-3 space-y-2">
                        @foreach($audit as $entree)
                            <li class="rounded-xl border border-slate-100 bg-slate-50/60 px-3.5 py-2.5 text-xs">
                                <p class="font-bold text-slate-800">{{ $entree->action }}</p>
                                <p class="mt-0.5 text-slate-500 tabular-nums">{{ $entree->created_at->format('d/m/Y H:i') }}</p>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="mt-2 text-sm text-slate-500">Aucune action auditée pour ce dossier.</p>
                @endif
            </section>
        @endif
    </div>
@endsection
