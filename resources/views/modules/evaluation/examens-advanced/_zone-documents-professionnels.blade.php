{{--
    Zone commune transversale : « Documents professionnels — Aperçu et impression » (LOT 2)
    Intégrée dans chaque option d'Examens Advanced :
    - Regroupements virtuels (groupes)
    - Planification (planification)
    - Salles & Capacités (salles)
    - Surveillance (surveillance)
    - Convocations (convocations)
    - Historique & Audit (historique)
    - Publication & Validation (publication)
--}}

@php
    $optionCourante = $optionCourante ?? 'groupes';
    $serviceDocs = app(\App\Modules\Evaluation\Services\ExamDocumentsProfessionnelsService::class);
    $documentsOption = $serviceDocs->obtenirDocumentsPourOption($optionCourante, auth()->user());
    $premierDoc = !empty($documentsOption) ? $documentsOption[0] : null;

    $faculteRestreinteId = auth()->user()?->faculteRestreinteId();

    try {
        $sessionsDisponiblesSelect = (isset($sessionsDisponibles) && is_iterable($sessionsDisponibles))
            ? $sessionsDisponibles
            : \App\Modules\Evaluation\Models\SessionExamen::query()
                ->when($faculteRestreinteId !== null, fn ($q) => $q->pourFaculteId($faculteRestreinteId))
                ->orderByDesc('date_debut')
                ->limit(20)
                ->get(['id', 'nom', 'code']);
    } catch (\Throwable $e) {
        $sessionsDisponiblesSelect = collect();
    }

    try {
        $epreuvesSelect = (isset($epreuves) && is_iterable($epreuves) && !($epreuves instanceof \Illuminate\Pagination\LengthAwarePaginator))
            ? $epreuves
            : \App\Modules\Evaluation\Models\Epreuve::query()
                ->when($faculteRestreinteId !== null, fn ($q) => $q->pourFaculteId($faculteRestreinteId))
                ->with('matiere')
                ->orderByDesc('date')
                ->limit(30)
                ->get(['id', 'code', 'matiere_id', 'date']);
    } catch (\Throwable $e) {
        $epreuvesSelect = collect();
    }

    try {
        $sallesSelect = (isset($salles) && is_iterable($salles) && !($salles instanceof \Illuminate\Pagination\LengthAwarePaginator))
            ? $salles
            : \App\Modules\Schedule\Models\Room::query()
                ->when($faculteRestreinteId !== null, fn ($q) => $q->where('faculte_id', $faculteRestreinteId))
                ->where('actif', true)
                ->orderBy('code')
                ->limit(30)
                ->get(['id', 'code', 'nom', 'capacite', 'type_salle']);
    } catch (\Throwable $e) {
        $sallesSelect = collect();
    }

    try {
        $groupesSelect = (isset($groupes) && is_iterable($groupes) && !($groupes instanceof \Illuminate\Pagination\LengthAwarePaginator))
            ? $groupes
            : \App\Modules\Schedule\Models\Group::query()
                ->when($faculteRestreinteId !== null, fn ($q) => $q->where('faculte_id', $faculteRestreinteId))
                ->orderBy('code')
                ->limit(30)
                ->get(['id', 'code', 'nom']);
    } catch (\Throwable $e) {
        $groupesSelect = collect();
    }
@endphp

<div id="zone-documents-professionnels" class="mt-8 rounded-2xl border border-slate-200/90 bg-gradient-to-b from-slate-50/50 via-white to-white p-6 shadow-xs">
    {{-- En-tête de la zone documentaire --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-5 border-b border-slate-100">
        <div class="flex items-center gap-3">
            <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-primary-600 text-white shadow-xs">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Zm-3 0h.008v.008H15V10.5Z" />
                </svg>
            </span>
            <div>
                <h3 class="text-base font-bold text-slate-900 flex items-center gap-2">
                    Documents professionnels — Aperçu et impression
                    <span class="inline-flex items-center rounded-full bg-primary-50 px-2 py-0.5 text-xs font-semibold text-primary-700 ring-1 ring-inset ring-primary-600/20">
                        {{ count($documentsOption) }} disponible(s)
                    </span>
                </h3>
                <p class="text-xs text-slate-500 mt-0.5">
                    Modèles certifiés A4, en-tête institutionnel, gestion des filigranes d'état, export PDF et impression papier.
                </p>
            </div>
        </div>

        {{-- Badge d'assurance conformité --}}
        <div class="flex items-center gap-2 text-xs font-medium text-slate-600 bg-white border border-slate-200/80 rounded-xl px-3 py-1.5 shadow-2xs self-start sm:self-auto">
            <svg class="h-4 w-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
            </svg>
            <span>Anti-IDOR &amp; Traçabilité garantie</span>
        </div>
    </div>

    @if(!empty($documentsOption))
        <div class="mt-6 flex flex-col xl:flex-row gap-6 items-stretch">
            {{-- Colonne de gauche : Sélection du document officiel --}}
            <div class="w-full xl:w-[440px] 2xl:w-[480px] shrink-0 space-y-3">
                <div class="flex items-center justify-between">
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                        Sélectionner le document officiel
                    </label>
                    <span class="text-[11px] text-slate-400">Cliquez pour configurer</span>
                </div>
                <div class="space-y-2.5" id="liste-cartes-documents">
                    @foreach($documentsOption as $index => $doc)
                        <button type="button"
                                onclick="selectionnerDocument('{{ $doc['id'] }}', '{{ addslashes($doc['titre']) }}', '{{ addslashes($doc['description']) }}', '{{ $doc['orientation'] }}')"
                                id="btn-doc-{{ $doc['id'] }}"
                                class="w-full text-left p-3.5 rounded-xl border transition-all duration-150 flex items-start gap-3.5 carte-selection-doc cursor-pointer {{ $index === 0 ? 'border-primary-600 bg-primary-50/60 shadow-2xs ring-1 ring-primary-500/20' : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50/70' }}">
                            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg {{ $index === 0 ? 'bg-primary-600 text-white shadow-2xs' : 'bg-slate-100 text-slate-600' }} icone-carte">
                                <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                                </svg>
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center justify-between gap-1.5">
                                    <span class="text-xs font-bold text-slate-900 truncate titre-carte">{{ $doc['titre'] }}</span>
                                    <span class="shrink-0 text-[10px] font-semibold uppercase px-2 py-0.5 rounded-md {{ $doc['orientation'] === 'landscape' ? 'bg-amber-100 text-amber-900' : 'bg-slate-100 text-slate-700' }}">
                                        {{ $doc['orientation'] === 'landscape' ? 'A4 Paysage' : 'A4 Portrait' }}
                                    </span>
                                </div>
                                <p class="text-[11px] text-slate-500 line-clamp-2 mt-1 leading-relaxed">{{ $doc['description'] }}</p>
                            </div>
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Colonne de droite : Paramétrage des filtres et Actions d'impression --}}
            <div class="w-full flex-1 min-w-0 flex flex-col justify-between rounded-2xl border border-slate-200/90 bg-white p-6 shadow-2xs">
                <div>
                    {{-- Cartouche du document actif --}}
                    <div class="rounded-xl bg-slate-50/90 border border-slate-200/80 p-4 mb-5">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-primary-100 text-primary-800 px-2.5 py-0.5 text-[11px] font-bold">
                                <span class="h-1.5 w-1.5 rounded-full bg-primary-600"></span>
                                DOCUMENT ACTIF
                            </span>
                            <span id="badge-orientation" class="inline-flex items-center rounded-md bg-white border border-slate-200 px-2.5 py-0.5 text-xs font-semibold text-slate-700 shadow-2xs">
                                {{ ($premierDoc['orientation'] ?? '') === 'landscape' ? 'Format Paysage' : 'Format Portrait' }}
                            </span>
                        </div>
                        <h4 id="label-doc-actif" class="text-sm sm:text-base font-bold text-slate-900 mt-2">
                            {{ $premierDoc['titre'] ?? 'Sélectionnez un document' }}
                        </h4>
                        <p id="desc-doc-actif" class="text-xs text-slate-600 mt-1 leading-relaxed">
                            {{ $premierDoc['description'] ?? '' }}
                        </p>
                    </div>

                    {{-- Formulaire des filtres autorisés --}}
                    <div>
                        <div class="flex items-center justify-between mb-3">
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                                Paramètres et filtres d'export
                            </label>
                            <span class="text-[11px] text-slate-400">Filtrage contextuel sécurisé</span>
                        </div>

                        <form id="form-filtres-documents" class="space-y-4">
                            <input type="hidden" name="type_document" id="input-type-document" value="{{ $premierDoc['id'] ?? '' }}">

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                {{-- Filtre Session --}}
                                <div>
                                    <label for="doc-filtre-session" class="block text-xs font-semibold text-slate-700 mb-1.5">
                                        Session d'examen
                                    </label>
                                    <select id="doc-filtre-session" name="session_id" class="w-full text-xs rounded-xl border border-slate-200 bg-white py-2.5 px-3 text-slate-800 shadow-2xs focus:border-primary-500 focus:ring-primary-500">
                                        <option value="">Toutes les sessions</option>
                                        @foreach($sessionsDisponiblesSelect as $sess)
                                            <option value="{{ $sess->id }}">{{ $sess->nom ?? $sess->code }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                {{-- Filtre Épreuve --}}
                                <div>
                                    <label for="doc-filtre-epreuve" class="block text-xs font-semibold text-slate-700 mb-1.5">
                                        Épreuve ciblée (optionnel)
                                    </label>
                                    <select id="doc-filtre-epreuve" name="epreuve_id" class="w-full text-xs rounded-xl border border-slate-200 bg-white py-2.5 px-3 text-slate-800 shadow-2xs focus:border-primary-500 focus:ring-primary-500">
                                        <option value="">Toutes les épreuves / Session complète</option>
                                        @foreach($epreuvesSelect as $ep)
                                            <option value="{{ $ep->id }}">{{ $ep->code }} — {{ $ep->matiere?->nom ?? 'Épreuve' }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                {{-- Filtre Salle --}}
                                <div>
                                    <label for="doc-filtre-salle" class="block text-xs font-semibold text-slate-700 mb-1.5">
                                        Salle ou Amphithéâtre
                                    </label>
                                    <select id="doc-filtre-salle" name="salle_id" class="w-full text-xs rounded-xl border border-slate-200 bg-white py-2.5 px-3 text-slate-800 shadow-2xs focus:border-primary-500 focus:ring-primary-500">
                                        <option value="">Toutes les salles</option>
                                        @foreach($sallesSelect as $sal)
                                            <option value="{{ $sal->id }}">{{ $sal->code }} ({{ $sal->nom }})</option>
                                        @endforeach
                                    </select>
                                </div>

                                {{-- Filtre Groupe virtuel --}}
                                <div>
                                    <label for="doc-filtre-groupe" class="block text-xs font-semibold text-slate-700 mb-1.5">
                                        Groupe virtuel / Cohorte
                                    </label>
                                    <select id="doc-filtre-groupe" name="groupe_id" class="w-full text-xs rounded-xl border border-slate-200 bg-white py-2.5 px-3 text-slate-800 shadow-2xs focus:border-primary-500 focus:ring-primary-500">
                                        <option value="">Tous les groupes</option>
                                        @foreach($groupesSelect as $grp)
                                            <option value="{{ $grp->id }}">{{ $grp->code }} ({{ $grp->nom ?? 'Groupe' }})</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                {{-- Barre d'actions : Aperçu, Téléchargement PDF et Impression --}}
                <div class="mt-6 pt-5 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-3">
                    <div class="flex items-center gap-2 text-xs text-slate-500 self-start sm:self-auto">
                        <span class="inline-block h-2 w-2 rounded-full bg-emerald-500"></span>
                        <span>Visas & cachets prêts à l'édition</span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2.5 w-full sm:w-auto justify-end">
                        {{-- Bouton Aperçu avant impression --}}
                        <button type="button"
                                id="btn-apercu-document"
                                onclick="ouvrirApercuDocument()"
                                class="inline-flex items-center justify-center gap-2 rounded-xl bg-white border border-slate-300 px-4 py-2.5 text-xs font-bold text-slate-700 shadow-2xs hover:bg-slate-50 hover:border-slate-400 transition cursor-pointer">
                            <svg class="h-4 w-4 text-primary-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                            </svg>
                            Aperçu avant impression
                        </button>

                        {{-- Bouton Imprimer via navigateur --}}
                        <button type="button"
                                id="btn-imprimer-direct"
                                onclick="lancerImpressionNavigateur()"
                                class="inline-flex items-center justify-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-xs font-bold text-white shadow-2xs hover:bg-slate-800 transition cursor-pointer">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Zm-3 0h.008v.008H15V10.5Z" />
                            </svg>
                            Imprimer
                        </button>

                        {{-- Bouton Télécharger PDF certifié --}}
                        <button type="button"
                                id="btn-telecharger-pdf"
                                onclick="telechargerPdfCertifie()"
                                class="inline-flex items-center justify-center gap-2 rounded-xl bg-primary-600 px-4 py-2.5 text-xs font-bold text-white shadow-xs hover:bg-primary-700 transition cursor-pointer">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                            </svg>
                            Générer PDF officiel (A4)
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @else
        {{-- État vide explicite si aucun document n'est disponible pour l'option --}}
        <div class="mt-6 rounded-xl border border-dashed border-slate-200 bg-white p-8 text-center">
            <svg class="mx-auto h-10 w-10 text-slate-300" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
            </svg>
            <h4 class="mt-3 text-sm font-bold text-slate-800">Aucun document disponible pour cette option</h4>
            <p class="mt-1 text-xs text-slate-500 max-w-md mx-auto">
                Votre profil utilisateur ne dispose pas des autorisations nécessaires pour imprimer des documents dans cette section, ou aucun modèle n'est associé à cette vue.
            </p>
        </div>
    @endif
</div>

{{-- Modal in-page d'aperçu avant impression --}}
<div id="modal-apercu-document" class="fixed inset-0 z-50 hidden overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="relative w-full max-w-5xl bg-white rounded-2xl shadow-2xl overflow-hidden border border-slate-200 flex flex-col max-h-[92vh]">
        {{-- En-tête du modal --}}
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200 bg-slate-50/80">
            <div class="flex items-center gap-3">
                <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-primary-100 text-primary-700">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                    </svg>
                </span>
                <div>
                    <h3 id="modal-apercu-titre" class="text-sm font-bold text-slate-900">Aperçu avant impression</h3>
                    <p id="modal-apercu-ref" class="text-[11px] text-slate-500 font-mono">Chargement...</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button type="button"
                        onclick="telechargerPdfCertifie()"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-semibold text-white shadow-2xs hover:bg-primary-700 transition cursor-pointer"
                        title="Télécharger le fichier PDF certifié (A4)">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                    </svg>
                    Télécharger PDF
                </button>
                <button type="button"
                        onclick="lancerImpressionDepuisModal()"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white shadow-2xs hover:bg-slate-800 transition cursor-pointer"
                        title="Imprimer directement ce document">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Zm-3 0h.008v.008H15V10.5Z" />
                    </svg>
                    Imprimer
                </button>
                <button type="button"
                        onclick="fermerModalApercu()"
                        class="text-slate-400 hover:text-slate-600 p-1.5 rounded-lg hover:bg-slate-100 transition cursor-pointer"
                        title="Fermer l'aperçu">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>

        {{-- Corps avec iframe ou conteneur d'aperçu --}}
        <div class="relative flex-1 bg-slate-100 overflow-hidden flex items-center justify-center p-4">
            <div id="spinner-chargement-apercu" class="absolute inset-0 flex items-center justify-center bg-white/80 z-10">
                <div class="flex flex-col items-center gap-3">
                    <div class="h-8 w-8 animate-spin rounded-full border-3 border-primary-600 border-t-transparent"></div>
                    <span class="text-xs font-semibold text-slate-600">Composition du document officiel...</span>
                </div>
            </div>
            <iframe id="iframe-apercu-document" class="w-full h-[68vh] bg-white rounded-lg shadow-sm border border-slate-200"></iframe>
        </div>

        {{-- Pied du modal --}}
        <div class="flex items-center justify-between px-6 py-3 border-t border-slate-200 bg-white">
            <span class="text-xs text-slate-500">Mise en page certifiée A4 conforme au règlement des examens finaux UniRegistre.</span>
            <button type="button" onclick="fermerModalApercu()" class="rounded-lg border border-slate-300 bg-white px-3.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 cursor-pointer">
                Fermer l'aperçu
            </button>
        </div>
    </div>
</div>

<script>
    function selectionnerDocument(id, titre, description, orientation) {
        document.getElementById('input-type-document').value = id;
        document.getElementById('label-doc-actif').innerText = titre;
        const descElem = document.getElementById('desc-doc-actif');
        if (descElem) {
            descElem.innerText = description;
        }
        document.getElementById('badge-orientation').innerText = orientation === 'landscape' ? 'Format Paysage' : 'Format Portrait';

        // Mise à jour de la mise en valeur visuelle
        document.querySelectorAll('.carte-selection-doc').forEach(btn => {
            btn.classList.remove('border-primary-600', 'bg-primary-50/60', 'shadow-2xs', 'ring-1', 'ring-primary-500/20');
            btn.classList.add('border-slate-200', 'bg-white');
            const icone = btn.querySelector('.icone-carte');
            if (icone) {
                icone.classList.remove('bg-primary-600', 'text-white', 'shadow-2xs');
                icone.classList.add('bg-slate-100', 'text-slate-600');
            }
        });

        const btnActif = document.getElementById('btn-doc-' + id);
        if (btnActif) {
            btnActif.classList.remove('border-slate-200', 'bg-white');
            btnActif.classList.add('border-primary-600', 'bg-primary-50/60', 'shadow-2xs', 'ring-1', 'ring-primary-500/20');
            const icone = btnActif.querySelector('.icone-carte');
            if (icone) {
                icone.classList.remove('bg-slate-100', 'text-slate-600');
                icone.classList.add('bg-primary-600', 'text-white', 'shadow-2xs');
            }
        }
    }

    function construireUrlDocument(action) {
        const form = document.getElementById('form-filtres-documents');
        const formData = new FormData(form);
        const params = new URLSearchParams();

        for (const [key, value] of formData.entries()) {
            if (value !== '' && value !== null) {
                params.append(key, value);
            }
        }

        let base = '';
        if (action === 'apercu') {
            base = '{{ route("admin.evaluation.examens.documents.apercu") }}';
        } else if (action === 'imprimer') {
            base = '{{ route("admin.evaluation.examens.documents.imprimer") }}';
        } else if (action === 'telecharger') {
            base = '{{ route("admin.evaluation.examens.documents.telecharger") }}';
        }

        return base + '?' + params.toString();
    }

    function ouvrirApercuDocument() {
        const modal = document.getElementById('modal-apercu-document');
        const iframe = document.getElementById('iframe-apercu-document');
        const spinner = document.getElementById('spinner-chargement-apercu');
        const titreDoc = document.getElementById('label-doc-actif').innerText;

        document.getElementById('modal-apercu-titre').innerText = titreDoc;
        document.getElementById('modal-apercu-ref').innerText = 'Génération en cours...';

        modal.classList.remove('hidden');
        spinner.classList.remove('hidden');

        const url = construireUrlDocument('apercu');
        iframe.src = url;

        iframe.onload = function() {
            spinner.classList.add('hidden');
            document.getElementById('modal-apercu-ref').innerText = 'Aperçu prêt — Conforme A4';
        };
    }

    function fermerModalApercu() {
        document.getElementById('modal-apercu-document').classList.add('hidden');
        document.getElementById('iframe-apercu-document').src = 'about:blank';
    }

    function lancerImpressionNavigateur() {
        const url = construireUrlDocument('imprimer') + '&auto=1';
        window.open(url, '_blank');
    }

    function lancerImpressionDepuisModal() {
        const iframe = document.getElementById('iframe-apercu-document');
        if (iframe && iframe.contentWindow) {
            iframe.contentWindow.print();
        } else {
            lancerImpressionNavigateur();
        }
    }

    function telechargerPdfCertifie() {
        const url = construireUrlDocument('telecharger');
        window.location.href = url;
    }
</script>
