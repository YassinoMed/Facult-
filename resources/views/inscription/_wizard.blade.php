{{-- $prefixe : préfixe des identifiants en collision potentielle avec la
     page hôte (`cin`, `date_naissance` + libellés/erreurs associés).
     Vide sur la page canonique (rendu strictement identique) ; positionné
     (`emb-`) dans le parcours embarqué. Les autres identifiants n'ont
     aucun homonyme sur les pages hôtes (audité) et restent intacts. --}}
@php($prefixe = $prefixe ?? '')
{{-- Étape initiale : la page canonique fournit toujours `$etapeErreur`
     (erreurs serveur) ; en modal (fetch, erreurs clientes), défaut 1. --}}
@php($etapeErreur = $etapeErreur ?? 1)
            <form action="{{ route('inscription.store') }}" method="POST"
                  x-data="inscriptionForm(@js(old('sexe', '')), @js($checklistsDocuments ?? []), {{ ! empty($embarque) ? 'true' : 'false' }}, @js($prefixe ?? ''))"
                  x-init="etape = {{ $etapeErreur }}; if (etape === 6) preparerRecap(); @if($errors->any()) $nextTick(() => { document.getElementById('erreurs-globales')?.scrollIntoView({ behavior: 'smooth', block: 'center' }); }); @else if (!periode.interactif && etape < 4) etape = 4; @endif"
                  @identification-verifiee.window="if (etape === 1) allerEtape(2)"
                  @sexe-identifie.window="if (!sexe) sexe = $event.detail.sexe"
                  @periode-actualisee.window="if (!$event.detail.interactif && etape < 4) etape = 4"
                  @submit="if (embarque) { $event.preventDefault(); if (etape !== totalEtapes) etapeSuivante(); else soumettreEmbarque(); } else if (etape !== totalEtapes) { $event.preventDefault(); etapeSuivante(); } else { submitting = true; }"
                  class="mt-6">
                @csrf
                <input type="hidden" name="idempotency_token" value="{{ $idempotencyToken ?? (string) str()->uuid() }}">

                {{-- Barre de progression (§5/§9) : texte + état, jamais la couleur seule --}}
                <nav aria-label="Progression de l'inscription">
                    <ol class="flex flex-col sm:flex-row sm:items-center gap-2 text-xs font-semibold">
                        @foreach([1 => 'Identification', 2 => 'Identité', 3 => 'Coordonnées', 4 => 'Formation', 5 => 'Documents', 6 => 'Confirmation'] as $numero => $libelle)
                            <li class="flex items-center gap-2 flex-1">
                                <button type="button" @click="if ({{ $numero }} < etape) allerEtape({{ $numero }})"
                                        :aria-current="etape === {{ $numero }} ? 'step' : null"
                                        :disabled="{{ $numero }} > etape"
                                        class="flex items-center gap-2 rounded-lg border px-3 py-2 w-full text-left transition focus:outline-none focus:ring-4 focus:ring-primary-500/25 disabled:cursor-not-allowed"
                                        :class="etape === {{ $numero }} ? 'border-primary-700 bg-primary-50 text-primary-900' : (etape > {{ $numero }} ? 'border-emerald-300 bg-emerald-50 text-emerald-900' : 'border-slate-200 bg-white text-slate-500')">
                                    <span class="flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full text-[11px] font-black"
                                          :class="etape === {{ $numero }} ? 'bg-primary-800 text-white' : (etape > {{ $numero }} ? 'bg-emerald-600 text-white' : 'bg-slate-200 text-slate-600')"
                                          aria-hidden="true">
                                        <span x-show="etape <= {{ $numero }}">{{ $numero }}</span>
                                        <svg x-show="etape > {{ $numero }}" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                        </svg>
                                    </span>
                                    {{ $libelle }}
                                    <span class="sr-only">
                                        <span x-show="etape === {{ $numero }}">(étape en cours)</span>
                                        <span x-show="etape > {{ $numero }}">(étape terminée)</span>
                                    </span>
                                </button>
                                @if($numero < 6)
                                    <span class="hidden sm:block h-px w-4 bg-slate-300" aria-hidden="true"></span>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                    <p class="mt-2 text-xs text-slate-500" aria-live="polite">Étape <span x-text="etape"></span> sur 6</p>
                    <div class="mt-1 h-1.5 rounded-full bg-slate-100" role="progressbar" aria-label="Progression" aria-valuemin="1" aria-valuemax="6" :aria-valuenow="etape">
                        <div class="h-full rounded-full bg-primary-700 transition-all" :style="'width:' + (etape / 6 * 100) + '%'"></div>
                    </div>
                    @if(!empty($embarque))
                    {{-- Panneaux embarqués (modal d'accueil, sans navigation) :
                         erreur globale + confirmation, pilotés par
                         `soumettreEmbarque()`. Inertes sur la page canonique. --}}
                    <div x-show="embarqueMessage" x-cloak id="embarque-erreurs" class="mt-4 rounded-xl border border-rose-300 bg-rose-50 p-4 text-xs sm:text-sm text-rose-900" role="alert" tabindex="-1">
                        <p class="font-bold" x-text="embarqueMessage"></p>
                        <ul class="mt-2 list-disc list-inside space-y-1" x-show="Object.keys(embarqueErreurs).length">
                            <template x-for="(messages, champ) in embarqueErreurs" :key="champ">
                                <li><strong x-text="champ"></strong> : <span x-text="(messages || []).join(' ')"></span></li>
                            </template>
                        </ul>
                    </div>
                    <div x-show="embarqueSucces" x-cloak id="embarque-confirmation" class="mt-4 rounded-xl border border-emerald-300 bg-emerald-50 p-4 text-xs sm:text-sm text-emerald-900" role="status" tabindex="-1">
                        <p class="font-bold">Votre inscription a été enregistrée avec succès !</p>
                        <p class="mt-1">Référence de votre dossier : <strong class="font-mono" x-text="embarqueSucces && embarqueSucces.num_inscription"></strong></p>
                        <p class="mt-1" x-show="embarqueSucces && embarqueSucces.deja_enregistre">Ce dossier existait déjà : aucun doublon n'a été créé.</p>
                    </div>
                    @endif
                {{-- ── Alerte d'état réseau (LOOP 17) : non intrusive, rassurante sur les données locales ── --}}
                <div x-show="horsLigne" x-cloak class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-xs text-amber-900" role="status" aria-live="polite">
                    <div class="flex items-start gap-2.5">
                        <span class="text-base" aria-hidden="true">⚡</span>
                        <div>
                            <p class="font-bold">Mode hors-ligne actif</p>
                            <p class="mt-0.5">Votre connexion Internet est actuellement indisponible. Vos sélections académiques non sensibles sont préservées sur cet appareil, mais la transmission officielle de votre dossier requiert le rétablissement de la connexion.</p>
                        </div>
                    </div>
                </div>

                {{-- ── Assistant guidé et parcours adaptatif (LOOP 02 & LOOP 03) ── --}}
                <div class="mt-4 rounded-xl border border-primary-200 bg-primary-50/60 p-4" aria-live="polite">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-primary-800 px-2.5 py-0.5 text-xs font-bold text-white">
                                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-400" aria-hidden="true"></span>
                                    <span x-text="libelleCategorie()">Première inscription (Licence)</span>
                                </span>
                                <span class="text-xs font-bold text-primary-950" x-text="titreEtapeCourante()"></span>
                            </div>
                            <p class="mt-1 text-xs text-primary-900 font-medium">
                                <span class="font-bold">Prochaine action :</span> <span x-text="prochaineAction()"></span>
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 self-start sm:self-auto">
                            {{-- Indicateur de brouillon automatique (LOOP 03) --}}
                            <div class="flex items-center gap-1.5 text-[11px]" id="statut-brouillon-indicateur">
                                <template x-if="brouillon.etat === 'enregistrement'">
                                    <span class="inline-flex items-center gap-1.5 rounded-lg bg-amber-100/80 px-2.5 py-1 font-bold text-amber-900 border border-amber-300">
                                        <svg class="h-3 w-3 animate-spin text-amber-700" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                        Enregistrement…
                                    </span>
                                </template>
                                <template x-if="brouillon.etat === 'enregistre'">
                                    <span class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-100/80 px-2.5 py-1 font-bold text-emerald-900 border border-emerald-300">
                                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-600"></span>
                                        Brouillon enregistré <span class="text-[10px] font-normal text-emerald-800" x-show="brouillon.dateSauvegarde" x-text="'(' + brouillon.dateSauvegarde + ')'"></span>
                                    </span>
                                </template>
                                <template x-if="brouillon.etat === 'echec'">
                                    <span class="inline-flex items-center gap-1.5 rounded-lg bg-rose-100/80 px-2.5 py-1 font-bold text-rose-900 border border-rose-300">
                                        <span>⚠</span> Échec sauvegarde
                                    </span>
                                </template>
                                <template x-if="horsLigne">
                                    <span class="inline-flex items-center gap-1 rounded-lg bg-slate-100 px-2 py-1 font-bold text-slate-700 border border-slate-300" title="Mode hors-ligne : données conservées localement">
                                        ⚡ Hors-ligne
                                    </span>
                                </template>
                                <template x-if="brouillon.existe">
                                    <button type="button" @click="effacerBrouillon()"
                                            title="Effacer le brouillon sauvegardé sur ce navigateur"
                                            class="text-[11px] font-bold text-slate-500 hover:text-rose-700 underline underline-offset-2 ml-1">
                                        Effacer
                                    </button>
                                </template>
                            </div>

                            <div class="flex items-center gap-1.5 text-[11px] font-semibold text-slate-600 bg-white/80 rounded-lg px-2.5 py-1 border border-primary-100">
                                <span>Parcours guidé :</span>
                                <span class="text-primary-800 font-bold" x-text="'Étape ' + etape + ' / 6'"></span>
                            </div>
                        </div>
                    </div>

                    {{-- Alerte de restauration automatique du brouillon (LOOP 03) --}}
                    <div x-show="brouillon.restaure" x-cloak class="mt-3 flex items-center justify-between rounded-lg bg-white/90 border border-emerald-200 px-3 py-2 text-xs text-emerald-900">
                        <div class="flex items-center gap-2">
                            <span>✓</span>
                            <span>Vos sélections académiques de la session précédente ont été restaurées automatiquement.</span>
                        </div>
                        <button type="button" @click="brouillon.restaure = false" class="text-emerald-700 font-bold hover:text-emerald-900 text-xs ml-2">✕</button>
                    </div>
                </div>

                {{-- Encart si retour sur étape 1 (Identification) --}}
                <div data-etape-contenu="1" x-show="etape === 1" class="mt-6 rounded-xl border border-slate-200 bg-slate-50/50 p-5 sm:p-6 text-center">
                    <p class="text-sm font-bold text-slate-900">Identification initiale validée</p>
                    <p class="mt-1 text-xs text-slate-600" x-show="!identification.mensIndisponible">Vos identifiants ont été confirmés via le référentiel national MENS.</p>
                    <p class="mt-1 text-xs text-amber-700 font-medium" x-show="identification.mensIndisponible">Saisie manuelle autorisée suite à l'indisponibilité temporaire du référentiel national MENS.</p>
                    <button type="button" @click="allerEtape(2)" class="mt-4 inline-flex items-center gap-2 rounded-xl bg-primary-800 px-5 py-2.5 text-xs font-bold text-white hover:bg-primary-700">
                        Continuer vers l'identité →
                    </button>
                </div>

                {{-- ═══════ ÉTAPE 2 · IDENTITÉ (§6) ═══════ --}}
                <fieldset data-etape-contenu="2" x-show="etape === 2" class="mt-6 rounded-xl border border-slate-200 bg-slate-50/50 p-5 sm:p-6">
                    <legend class="px-2 text-sm sm:text-base font-bold text-slate-900">Étape 2 — Identité (profil vérifié)</legend>

                    {{-- ── Bannière de certification MENS / Résilience (LOOP 06) ── --}}
                    <div x-show="identification.source === 'MENS'" class="mb-4 rounded-xl border border-emerald-300 bg-emerald-50/90 p-4" aria-live="polite">
                        <div class="flex items-start gap-3">
                            <div class="flex-shrink-0 text-emerald-600 mt-0.5">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 0 1-1.043 3.296 3.745 3.745 0 0 1-3.296 1.043A3.745 3.745 0 0 1 12 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 0 1-3.296-1.043 3.745 3.745 0 0 1-1.043-3.296A3.745 3.745 0 0 1 3 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 0 1 1.043-3.296 3.745 3.745 0 0 1 3.296-1.043A3.745 3.745 0 0 1 12 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 0 1 3.296 1.043 3.745 3.745 0 0 1 1.043 3.296A3.745 3.745 0 0 1 21 12Z" />
                                </svg>
                            </div>
                            <div class="flex-1 text-xs">
                                <p class="font-bold text-emerald-900">Identité certifiée via le référentiel national MENS</p>
                                <p class="mt-0.5 text-emerald-800">
                                    Vos informations d'état civil ont été préremplies depuis la base officielle du ministère.
                                    <span x-show="identification.certitude === 'probable'" class="block mt-1 font-semibold text-amber-800">
                                        ⚠ Note : Cette correspondance est qualifiée de probable. Veuillez vérifier attentivement vos nom et prénom.
                                    </span>
                                </p>
                            </div>
                        </div>
                    </div>

                    <div x-show="identification.mensIndisponible" class="mb-4 rounded-xl border border-amber-300 bg-amber-50/90 p-4" aria-live="polite">
                        <div class="flex items-start gap-3">
                            <div class="flex-shrink-0 text-amber-600 mt-0.5">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                                </svg>
                            </div>
                            <div class="flex-1 text-xs">
                                <p class="font-bold text-amber-900">Référentiel national temporairement inaccessible — saisie manuelle</p>
                                <p class="mt-0.5 text-amber-800">
                                    Le service national MENS est momentanément indisponible. Vous pouvez renseigner vos informations manuellement ; votre identité sera vérifiée par le service de scolarité lors de l'instruction.
                                </p>
                            </div>
                        </div>
                    </div>

                    {{-- Scan CIN / passeport (§8) --}}
                    <div class="rounded-xl border border-primary-200 bg-white p-4 sm:p-5">
                        <h3 class="text-sm font-bold text-slate-900">Scanner / importer ma CIN</h3>
                        <p class="mt-0.5 text-xs text-slate-500">Facultatif : le document est analysé puis <strong>supprimé immédiatement</strong> — il n'est jamais conservé. Vous vérifierez tout avant l'envoi.</p>

                        <div class="mt-3 grid grid-cols-2 gap-3" role="group" aria-label="Type de document">
                            <button type="button" :disabled="!periode.interactif" @click="choisirTypeDocument('cin')"
                                    :aria-pressed="scan.type === 'cin'"
                                    :class="scan.type === 'cin' ? 'border-primary-700 bg-primary-50 ring-2 ring-primary-500/20 text-primary-900 font-bold' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'"
                                    class="rounded-xl border px-3 py-3 text-xs sm:text-sm transition min-h-[48px]">CIN tunisienne</button>
                            <button type="button" :disabled="!periode.interactif" @click="choisirTypeDocument('passeport')"
                                    :aria-pressed="scan.type === 'passeport'"
                                    :class="scan.type === 'passeport' ? 'border-primary-700 bg-primary-50 ring-2 ring-primary-500/20 text-primary-900 font-bold' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'"
                                    class="rounded-xl border px-3 py-3 text-xs sm:text-sm transition min-h-[48px]">Passeport</button>
                        </div>

                        <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3" x-show="scan.etat !== 'encours' && scan.etat !== 'termine'">
                            <button type="button" :disabled="!periode.interactif" @click="declencherChoixFichier('scan-camera')"
                                    class="rounded-xl bg-slate-900 px-4 py-3 text-sm font-bold text-white hover:bg-slate-800 focus:outline-none focus:ring-4 focus:ring-primary-500/25 transition min-h-[48px]">Utiliser la caméra</button>
                            <button type="button" :disabled="!periode.interactif" @click="declencherChoixFichier('scan-fichier')"
                                    class="rounded-xl border-2 border-primary-200 bg-white px-4 py-3 text-sm font-bold text-primary-800 hover:border-primary-400 focus:outline-none focus:ring-4 focus:ring-primary-500/25 transition min-h-[48px]">Importer un document</button>
                        </div>

                        <input type="file" id="scan-camera" :disabled="!periode.interactif" class="hidden" accept="image/jpeg,image/png" capture="environment"
                               aria-label="Photographier la pièce d'identité avec la caméra"
                               data-taille-max="{{ (int) config('identity.upload.taille_max') }}"
                               @change="televerserDocumentScan($event)">
                        <input type="file" id="scan-fichier" :disabled="!periode.interactif" class="hidden" accept="image/jpeg,image/png,application/pdf"
                               aria-label="Importer un fichier de pièce d'identité (image ou PDF)"
                               data-taille-max="{{ (int) config('identity.upload.taille_max') }}"
                               @change="televerserDocumentScan($event)">

                        <div class="mt-3" aria-live="polite">
                            <div x-show="scan.etat === 'encours'" class="rounded-xl border border-primary-200 bg-primary-50/60 px-4 py-4 text-center">
                                <p class="text-xs font-semibold text-primary-800">Document reçu — extraction des informations en cours…</p>
                                <p class="mt-0.5 text-[11px] text-slate-500" x-text="scan.nomFichier"></p>
                            </div>
                            <div x-show="scan.etat === 'erreur' || scan.etat === 'limite'" class="mt-2 rounded-xl border px-4 py-3 text-xs font-semibold"
                                 :class="scan.etat === 'limite' ? 'border-rose-300 bg-rose-50 text-rose-800' : 'border-amber-300 bg-amber-50 text-amber-900'">
                                <p x-text="scan.message"></p>
                            </div>
                            <div x-show="scan.etat === 'termine' && scan.resultat" class="mt-2 space-y-3">
                                <p class="text-xs font-bold text-emerald-900">Informations détectées — vérifiez leur exactitude avant de continuer.</p>
                                <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                                    <template x-for="(valeur, champ) in (scan.resultat?.data || {})" :key="champ">
                                        <div class="border-b border-slate-100 px-4 py-2.5 last:border-0">
                                            <div class="flex items-center justify-between gap-3">
                                                <div class="min-w-0">
                                                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400" x-text="libelleChampScan(champ)"></p>
                                                    <p class="truncate text-sm font-bold text-slate-900" x-text="valeur || '—'"></p>
                                                </div>
                                                <span class="flex-shrink-0 rounded-full border px-2.5 py-1 text-[10px] font-bold"
                                                      :class="badgeConfiance(champ)"
                                                      x-text="scan.resultat?.confidence?.[champ] ? Math.round(scan.resultat.confidence[champ] * 100) + ' %' : 'non détecté'"></span>
                                            </div>
                                            <template x-if="scan.resultat?.originals?.[champ]">
                                                <div class="mt-1.5 rounded-lg bg-slate-50 px-2.5 py-1.5 text-[11px] text-slate-500" dir="auto">
                                                    <span class="font-bold text-slate-400 uppercase text-[9px]">Document : </span>
                                                    <span class="font-semibold" dir="rtl" x-text="scan.resultat.originals[champ].raw"></span>
                                                    <span class="text-slate-300"> → </span>
                                                    <span x-text="scan.resultat.originals[champ].transliterated"></span>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                                <template x-if="scan.resultat?.divergences?.length > 0">
                                    <div class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3">
                                        <p class="text-xs font-bold text-amber-900">Vérification manuelle requise — différences avec le dossier enregistré :</p>
                                        <ul class="mt-1.5 space-y-1.5">
                                            <template x-for="divergence in scan.resultat.divergences" :key="divergence.champ">
                                                <li class="text-[11px] text-amber-800">
                                                    <span class="font-bold" x-text="libelleChampScan(divergence.champ)"></span> —
                                                    extrait : <span class="font-mono" x-text="divergence.extrait"></span> ·
                                                    enregistré : <span class="font-mono font-bold" x-text="divergence.enregistre"></span>
                                                </li>
                                            </template>
                                        </ul>
                                    </div>
                                </template>
                                <template x-if="scan.resultat?.existing_student === true">
                                    <div class="rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3">
                                        <p class="text-xs font-bold text-emerald-900">Ce document correspond à un dossier déjà enregistré — un second dossier sera refusé.</p>
                                        <p class="mt-0.5 text-[11px] text-emerald-800">
                                            Pour consulter votre dossier, <a href="{{ route('etudiant.connexion.show') }}" class="font-bold underline underline-offset-2">connectez-vous à votre espace étudiant</a>.
                                        </p>
                                    </div>
                                </template>
                                <template x-if="scan.resultat?.alerts?.length > 0">
                                    <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                                        <ul class="space-y-1">
                                            <template x-for="alerte in scan.resultat.alerts" :key="alerte">
                                                <li class="text-[11px] text-slate-600">⚠ <span x-text="alerte"></span></li>
                                            </template>
                                        </ul>
                                    </div>
                                </template>
                                <button type="button" @click="appliquerScan()"
                                        class="w-full rounded-xl bg-primary-800 px-4 py-3 text-sm font-bold text-white hover:bg-primary-700 focus:outline-none focus:ring-4 focus:ring-primary-500/25 transition min-h-[48px]">
                                    Reporter ces informations dans le formulaire
                                </button>
                                <button type="button" @click="scan.etat = 'inactif'; scan.resultat = null"
                                        class="w-full rounded-xl border border-slate-200 px-4 py-2 text-xs font-bold text-slate-500 hover:bg-slate-50 transition">
                                    Refaire un scan / saisir manuellement
                                </button>
                            </div>
                        </div>
                    </div>

                    {{-- CIN mise en évidence (§6-§7) --}}
                    <div class="mt-4 rounded-xl border-2 border-primary-700 bg-white p-4 sm:p-5">
                        <label for="{{ $prefixe }}cin" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">
                            N° CIN <span class="text-rose-600" aria-hidden="true">*</span>
                        </label>
                        <input type="text" id="{{ $prefixe }}cin" :disabled="!periode.interactif" @change="actualiserPeriode()" name="cin" value="{{ old('cin') }}" required
                               minlength="8" maxlength="8" pattern="[0-9]{8}" inputmode="numeric"
                               @error('cin') aria-invalid="true" @enderror aria-describedby="{{ $prefixe }}aide-cin @error('cin') {{ $prefixe }}erreur-cin @enderror"
                               @blur="rechercherCin()"
                               @input="champModifie('cin'); planifierRechercheCin()"
                               class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-3 text-sm uppercase font-mono font-bold tracking-wider text-slate-900 focus:border-primary-700 focus:ring-4 focus:ring-primary-500/20 focus:outline-none @error('cin') border-rose-400 bg-rose-50/50 @enderror"
                               placeholder="Ex : 11126679" autocomplete="off">
                        <p id="{{ $prefixe }}aide-cin" class="mt-1.5 text-[11px] text-slate-500">Saisissez votre CIN puis votre date de naissance : si un dossier existe, les champs autorisés sont pré-remplis (le mot de passe reste toujours vide).</p>
                        @error('cin')
                            <p id="{{ $prefixe }}erreur-cin" class="erreur-champ mt-1 text-xs font-semibold text-rose-700">⚠ {{ $message }}</p>
                        @enderror
                        <div class="mt-2" aria-live="polite">
                            <div x-show="cinRecherche.etat === 'encours'" class="rounded-lg border border-primary-200 bg-primary-50 px-3 py-2 text-xs font-semibold text-primary-800">Recherche du CIN en cours…</div>
                            <div x-show="cinRecherche.etat === 'attente_date'" class="rounded-lg border border-sky-300 bg-sky-50 px-3 py-2 text-xs text-sky-900">Saisissez également votre <strong>date de naissance</strong> pour retrouver vos informations.</div>
                            <div x-show="cinRecherche.etat === 'trouve'" class="space-y-2">
                                <p class="rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-900">✓ Informations existantes trouvées et pré-remplies.</p>
                                <p class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-[11px] text-amber-900">⚠ Un dossier existe déjà avec ce CIN : la création d'un doublon sera refusée. <a href="{{ route('etudiant.connexion.show') }}" class="font-bold underline underline-offset-2">Accédez à votre espace étudiant</a>.</p>
                            </div>
                            <div x-show="cinRecherche.etat === 'absent'" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs text-slate-600">Aucun dossier trouvé — vous pouvez continuer avec une nouvelle inscription.</div>
                            <div x-show="cinRecherche.etat === 'limite'" class="rounded-lg border border-rose-300 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-800">Trop de recherches — patientez une minute avant de réessayer.</div>
                        </div>
                    </div>

                    <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="nom" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Nom <span class="text-rose-600" aria-hidden="true">*</span></label>
                            <input type="text" id="nom" :disabled="!periode.interactif" name="nom" value="{{ old('nom') }}" required autocomplete="family-name"
                                   @input="champModifie('nom')"
                                   @error('nom') aria-invalid="true" @enderror aria-describedby="@error('nom') erreur-nom @enderror"
                                   class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-700 focus:ring-4 focus:ring-primary-500/20 focus:outline-none @error('nom') border-rose-400 bg-rose-50/50 @enderror"
                                   placeholder="Nom de famille">
                            @error('nom')
                                <p id="erreur-nom" class="erreur-champ mt-1 text-xs font-semibold text-rose-700">⚠ {{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="prenom" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Prénom <span class="text-rose-600" aria-hidden="true">*</span></label>
                            <input type="text" id="prenom" :disabled="!periode.interactif" name="prenom" value="{{ old('prenom') }}" required autocomplete="given-name"
                                   @input="champModifie('prenom')"
                                   @error('prenom') aria-invalid="true" @enderror aria-describedby="@error('prenom') erreur-prenom @enderror"
                                   class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-700 focus:ring-4 focus:ring-primary-500/20 focus:outline-none @error('prenom') border-rose-400 bg-rose-50/50 @enderror"
                                   placeholder="Prénom">
                            @error('prenom')
                                <p id="erreur-prenom" class="erreur-champ mt-1 text-xs font-semibold text-rose-700">⚠ {{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="{{ $prefixe }}date_naissance" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Date de naissance</label>
                            <input type="date" id="{{ $prefixe }}date_naissance" :disabled="!periode.interactif" name="date_naissance" value="{{ old('date_naissance') }}"
                                   min="1920-01-01" max="{{ date('Y-m-d') }}"
                                   @input="champModifie('date_naissance'); planifierRechercheCin()"
                                   @error('date_naissance') aria-invalid="true" @enderror aria-describedby="@error('date_naissance') {{ $prefixe }}erreur-date_naissance @enderror"
                                   class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-700 focus:ring-4 focus:ring-primary-500/20 focus:outline-none @error('date_naissance') border-rose-400 bg-rose-50/50 @enderror">
                            @error('date_naissance')
                                <p id="{{ $prefixe }}erreur-date_naissance" class="erreur-champ mt-1 text-xs font-semibold text-rose-700">⚠ {{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <span id="legende-sexe" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Sexe <span class="text-rose-600" aria-hidden="true">*</span></span>
                            <div class="grid grid-cols-2 gap-3" role="radiogroup" aria-labelledby="legende-sexe">
                                <label @click="sexe = 'M'; champModifie('sexe')"
                                       :class="sexe === 'M' ? 'border-primary-700 bg-primary-50 ring-2 ring-primary-500/20 text-primary-900 font-bold' : 'border-slate-300 bg-white text-slate-700 hover:border-slate-400'"
                                       class="flex cursor-pointer items-center justify-center gap-2 rounded-xl border px-3 py-2.5 text-xs sm:text-sm transition min-h-[44px]">
                                    <input type="radio" name="sexe" :disabled="!periode.interactif" value="M" class="sr-only" :checked="sexe === 'M'" required>
                                    Masculin
                                </label>
                                <label @click="sexe = 'F'; champModifie('sexe')"
                                       :class="sexe === 'F' ? 'border-primary-700 bg-primary-50 ring-2 ring-primary-500/20 text-primary-900 font-bold' : 'border-slate-300 bg-white text-slate-700 hover:border-slate-400'"
                                       class="flex cursor-pointer items-center justify-center gap-2 rounded-xl border px-3 py-2.5 text-xs sm:text-sm transition min-h-[44px]">
                                    <input type="radio" name="sexe" :disabled="!periode.interactif" value="F" class="sr-only" :checked="sexe === 'F'" required>
                                    Féminin
                                </label>
                            </div>
                            @error('sexe')
                                <p class="erreur-champ mt-1 text-xs font-semibold text-rose-700">⚠ {{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end">
                        <button type="button" @click="etapeSuivante()" :disabled="!periode.interactif"
                                class="inline-flex items-center gap-2 rounded-xl bg-primary-800 px-6 py-3 text-sm font-bold text-white hover:bg-primary-700 focus:outline-none focus:ring-4 focus:ring-primary-500/25 transition min-h-[48px] disabled:opacity-60 disabled:cursor-not-allowed">
                            Continuer
                            <span aria-hidden="true">→</span>
                        </button>
                    </div>
                </fieldset>

                {{-- ═══════ ÉTAPE 3 · COORDONNÉES ═══════ --}}
                <fieldset data-etape-contenu="3" x-show="etape === 3" class="mt-6 rounded-xl border border-slate-200 bg-slate-50/50 p-5 sm:p-6">
                    <legend class="px-2 text-sm sm:text-base font-bold text-slate-900">Étape 3 — Coordonnées</legend>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="email" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Email <span class="text-rose-600" aria-hidden="true">*</span></label>
                            <input type="email" id="email" :disabled="!periode.interactif" name="email" value="{{ old('email') }}" required autocomplete="email"
                                   maxlength="254"
                                   @input="champModifie('email')"
                                   @error('email') aria-invalid="true" @enderror aria-describedby="@error('email') erreur-email @enderror"
                                   class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-700 focus:ring-4 focus:ring-primary-500/20 focus:outline-none @error('email') border-rose-400 bg-rose-50/50 @enderror"
                                   placeholder="etudiant@domaine.com">
                            @error('email')
                                <p id="erreur-email" class="erreur-champ mt-1 text-xs font-semibold text-rose-700">⚠ {{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="telephone" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Téléphone</label>
                            <input type="tel" id="telephone" :disabled="!periode.interactif" name="telephone" value="{{ old('telephone') }}" autocomplete="tel"
                                   maxlength="30"
                                   @input="champModifie('telephone')"
                                   @error('telephone') aria-invalid="true" @enderror aria-describedby="@error('telephone') erreur-telephone @enderror"
                                   class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-700 focus:ring-4 focus:ring-primary-500/20 focus:outline-none @error('telephone') border-rose-400 bg-rose-50/50 @enderror"
                                   placeholder="+212 6 00 00 00 00">
                            @error('telephone')
                                <p id="erreur-telephone" class="erreur-champ mt-1 text-xs font-semibold text-rose-700">⚠ {{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="ville" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Ville <span class="text-rose-600" aria-hidden="true">*</span></label>
                            <input type="text" id="ville" :disabled="!periode.interactif" name="ville" value="{{ old('ville') }}" required
                                   @input="champModifie('ville')"
                                   @error('ville') aria-invalid="true" @enderror aria-describedby="@error('ville') erreur-ville @enderror"
                                   class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-700 focus:ring-4 focus:ring-primary-500/20 focus:outline-none @error('ville') border-rose-400 bg-rose-50/50 @enderror"
                                   placeholder="Ex : Casablanca">
                            @error('ville')
                                <p id="erreur-ville" class="erreur-champ mt-1 text-xs font-semibold text-rose-700">⚠ {{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="code_postal" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Code postal <span class="text-rose-600" aria-hidden="true">*</span></label>
                            <input type="text" id="code_postal" :disabled="!periode.interactif" name="code_postal" value="{{ old('code_postal') }}" required
                                   @input="champModifie('code_postal')"
                                   @error('code_postal') aria-invalid="true" @enderror aria-describedby="@error('code_postal') erreur-code_postal @enderror"
                                   class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-700 focus:ring-4 focus:ring-primary-500/20 focus:outline-none @error('code_postal') border-rose-400 bg-rose-50/50 @enderror"
                                   placeholder="Ex : 20000">
                            @error('code_postal')
                                <p id="erreur-code_postal" class="erreur-champ mt-1 text-xs font-semibold text-rose-700">⚠ {{ $message }}</p>
                            @enderror
                        </div>
                        <div class="sm:col-span-2">
                            <label for="adresse" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Adresse complète <span class="text-rose-600" aria-hidden="true">*</span></label>
                            <textarea id="adresse" :disabled="!periode.interactif" name="adresse" rows="2" required
                                      @input="champModifie('adresse')"
                                      @error('adresse') aria-invalid="true" @enderror aria-describedby="@error('adresse') erreur-adresse @enderror"
                                      class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-700 focus:ring-4 focus:ring-primary-500/20 focus:outline-none resize-none @error('adresse') border-rose-400 bg-rose-50/50 @enderror"
                                      placeholder="Numéro, rue, quartier, résidence...">{{ old('adresse') }}</textarea>
                            @error('adresse')
                                <p id="erreur-adresse" class="erreur-champ mt-1 text-xs font-semibold text-rose-700">⚠ {{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                    <div class="mt-6 flex flex-col sm:flex-row justify-between gap-3">
                        <button type="button" @click="etapePrecedente()"
                                class="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-6 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-4 focus:ring-primary-500/25 transition min-h-[48px]">
                            <span aria-hidden="true">←</span> Précédent
                        </button>
                        <button type="button" @click="etapeSuivante()" :disabled="!periode.interactif"
                                class="inline-flex items-center justify-center gap-2 rounded-xl bg-primary-800 px-6 py-3 text-sm font-bold text-white hover:bg-primary-700 focus:outline-none focus:ring-4 focus:ring-primary-500/25 transition min-h-[48px] disabled:opacity-60 disabled:cursor-not-allowed">
                            Continuer <span aria-hidden="true">→</span>
                        </button>
                    </div>
                </fieldset>

                {{-- ═══════ ÉTAPE 4 · FORMATION & PARCOURS ADAPTATIF ═══════ --}}
                <fieldset data-etape-contenu="4" x-show="etape === 4" class="mt-6 rounded-xl border border-slate-200 bg-slate-50/50 p-5 sm:p-6">
                    <legend class="px-2 text-sm sm:text-base font-bold text-slate-900">Étape 4 — Formation & Parcours</legend>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        {{-- Choix de la catégorie de parcours (LOOP 02) --}}
                        <div class="sm:col-span-2 rounded-xl border border-primary-200 bg-white p-4">
                            <span class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">
                                Catégorie d'inscription / Situation académique <span class="text-rose-600" aria-hidden="true">*</span>
                            </span>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3" role="radiogroup" aria-label="Type d'inscription">
                                <label @click="changerCategorie('premiere')"
                                       :class="categorie === 'premiere' ? 'border-primary-700 bg-primary-50 ring-2 ring-primary-500/20 text-primary-900 font-bold' : 'border-slate-300 bg-white text-slate-700 hover:border-slate-400'"
                                       class="flex cursor-pointer flex-col rounded-xl border p-3 text-xs transition">
                                    <div class="flex items-center gap-2">
                                        <input type="radio" name="type_inscription" value="premiere" class="sr-only" :checked="categorie === 'premiere'">
                                        <span class="font-bold text-sm">Première inscription</span>
                                    </div>
                                    <p class="mt-1 text-[11px] text-slate-500 font-normal">Nouveau bachelier (Licence 1 / accès initial).</p>
                                </label>
                                <label @click="changerCategorie('reinscription')"
                                       :class="categorie === 'reinscription' ? 'border-primary-700 bg-primary-50 ring-2 ring-primary-500/20 text-primary-900 font-bold' : 'border-slate-300 bg-white text-slate-700 hover:border-slate-400'"
                                       class="flex cursor-pointer flex-col rounded-xl border p-3 text-xs transition">
                                    <div class="flex items-center gap-2">
                                        <input type="radio" name="type_inscription" value="reinscription" class="sr-only" :checked="categorie === 'reinscription'">
                                        <span class="font-bold text-sm">Réinscription</span>
                                    </div>
                                    <p class="mt-1 text-[11px] text-slate-500 font-normal">Étudiant déjà inscrit poursuivant son cursus.</p>
                                </label>
                                <label @click="changerCategorie('transfert')"
                                       :class="categorie === 'transfert' ? 'border-primary-700 bg-primary-50 ring-2 ring-primary-500/20 text-primary-900 font-bold' : 'border-slate-300 bg-white text-slate-700 hover:border-slate-400'"
                                       class="flex cursor-pointer flex-col rounded-xl border p-3 text-xs transition">
                                    <div class="flex items-center gap-2">
                                        <input type="radio" name="type_inscription" value="transfert" class="sr-only" :checked="categorie === 'transfert'">
                                        <span class="font-bold text-sm">Transfert / Passerelle</span>
                                    </div>
                                    <p class="mt-1 text-[11px] text-slate-500 font-normal">Mobilité inter-facultés ou admission sur titre.</p>
                                </label>
                            </div>
                            @error('type_inscription')
                                <p class="erreur-champ mt-1 text-xs font-semibold text-rose-700">⚠ {{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="annee_bac" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Session du baccalauréat <span class="text-rose-600" aria-hidden="true">*</span></label>
                            <select id="annee_bac" :disabled="!periode.interactif" name="annee_bac" required
                                    @change="champModifie('annee_bac')"
                                    @error('annee_bac') aria-invalid="true" @enderror aria-describedby="@error('annee_bac') erreur-annee_bac @enderror"
                                    class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-700 focus:ring-4 focus:ring-primary-500/20 focus:outline-none @error('annee_bac') border-rose-400 bg-rose-50/50 @enderror">
                                <option value="">— Année du bac —</option>
                                @foreach($anneesBac as $ab)
                                    <option value="{{ $ab }}" {{ old('annee_bac', date('Y')) == $ab ? 'selected' : '' }}>Session {{ $ab }}</option>
                                @endforeach
                            </select>
                            @error('annee_bac')
                                <p id="erreur-annee_bac" class="erreur-champ mt-1 text-xs font-semibold text-rose-700">⚠ {{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="mention_bac" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Mention obtenue</label>
                            <select id="mention_bac" :disabled="!periode.interactif" name="mention_bac"
                                    @change="champModifie('mention_bac')"
                                    @error('mention_bac') aria-invalid="true" @enderror aria-describedby="@error('mention_bac') erreur-mention_bac @enderror"
                                    class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-700 focus:ring-4 focus:ring-primary-500/20 focus:outline-none @error('mention_bac') border-rose-400 bg-rose-50/50 @enderror">
                                <option value="">— Sans mention / Non spécifiée —</option>
                                @foreach($mentionsBac as $m)
                                    <option value="{{ $m }}" {{ old('mention_bac') == $m ? 'selected' : '' }}>Mention {{ $m }}</option>
                                @endforeach
                            </select>
                            @error('mention_bac')
                                <p id="erreur-mention_bac" class="erreur-champ mt-1 text-xs font-semibold text-rose-700">⚠ {{ $message }}</p>
                            @enderror
                        </div>
                        <div class="sm:col-span-2">
                            <label for="faculte_id" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Faculté d'accueil <span class="text-rose-600" aria-hidden="true">*</span></label>
                            <select id="faculte_id" name="faculte_id" required
                                    @change="champModifie('faculte_id'); actualiserPeriode(); ajusterOrientationParcours()"
                                    @error('faculte_id') aria-invalid="true" @enderror aria-describedby="@error('faculte_id') erreur-faculte_id @enderror"
                                    class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-3 text-sm font-semibold text-slate-900 focus:border-primary-700 focus:ring-4 focus:ring-primary-500/20 focus:outline-none @error('faculte_id') border-rose-400 bg-rose-50/50 @enderror">
                                <option value="">— Sélectionnez la faculté de votre choix —</option>
                                @foreach($facultes as $faculte)
                                    <option value="{{ $faculte->id }}" {{ old('faculte_id') == $faculte->id ? 'selected' : '' }}>{{ $faculte->nom }} ({{ $faculte->code }})</option>
                                @endforeach
                            </select>
                            @error('faculte_id')
                                <p id="erreur-faculte_id" class="erreur-champ mt-1 text-xs font-semibold text-rose-700">⚠ {{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Sélecteur de parcours LMD (LOOP 08) --}}
                        <div class="sm:col-span-2">
                            <label for="programme_id" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">
                                Parcours / Filière de formation (LMD)
                            </label>
                            <select id="programme_id" name="programme_id"
                                    @change="champModifie('programme_id'); ajusterOrientationParcours()"
                                    @error('programme_id') aria-invalid="true" @enderror aria-describedby="@error('programme_id') erreur-programme_id @enderror aide-programme"
                                    class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm font-semibold text-slate-900 focus:border-primary-700 focus:ring-4 focus:ring-primary-500/20 focus:outline-none @error('programme_id') border-rose-400 bg-rose-50/50 @enderror">
                                <option value="">— Cursus général / Sélectionner un parcours —</option>
                                @foreach($facultes as $f)
                                    @foreach($f->programmes as $prog)
                                        <option value="{{ $prog->id }}"
                                                data-faculte="{{ $f->id }}"
                                                data-capacite="{{ $prog->capacite ?? '' }}"
                                                data-niveaux="{{ $prog->niveaux->pluck('code')->join(',') }}"
                                                {{ old('programme_id') == $prog->id ? 'selected' : '' }}>
                                            {{ $f->code }} — {{ $prog->nom }} ({{ $prog->code }})@if($prog->capacite) — {{ $prog->capacite }} places max @endif
                                        </option>
                                    @endforeach
                                @endforeach
                            </select>
                            <p id="aide-programme" class="mt-1 text-[11px] text-slate-500">
                                Sélectionnez la filière spécialisée souhaitée. Seuls les parcours ouverts de l’établissement choisi sont proposés.
                            </p>
                            @error('programme_id')
                                <p id="erreur-programme_id" class="erreur-champ mt-1 text-xs font-semibold text-rose-700">⚠ {{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Indicateur interactif d'orientation et d'éligibilité (LOOP 08) --}}
                        <div x-show="orientationInfo.visible" x-cloak class="sm:col-span-2 rounded-xl border p-3.5 text-xs transition"
                             :class="orientationInfo.type === 'bloquant' ? 'border-rose-300 bg-rose-50 text-rose-900' : (orientationInfo.type === 'avertissement' ? 'border-amber-300 bg-amber-50 text-amber-900' : 'border-sky-300 bg-sky-50 text-sky-900')"
                             role="status" aria-live="polite">
                            <div class="flex items-start gap-2">
                                <span class="font-bold text-sm" x-text="orientationInfo.icone">ℹ</span>
                                <div class="flex-1">
                                    <p class="font-bold" x-text="orientationInfo.titre"></p>
                                    <p class="mt-0.5 text-[11px]" x-text="orientationInfo.message"></p>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label for="niveau" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Niveau d'admission <span class="text-rose-600" aria-hidden="true">*</span></label>
                            <select id="niveau" :disabled="!periode.interactif" name="niveau" required
                                    @change="champModifie('niveau'); ajusterOrientationParcours()"
                                    @error('niveau') aria-invalid="true" @enderror aria-describedby="@error('niveau') erreur-niveau @enderror"
                                    class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-700 focus:ring-4 focus:ring-primary-500/20 focus:outline-none @error('niveau') border-rose-400 bg-rose-50/50 @enderror">
                                <option value="">— Niveau —</option>
                                @foreach($niveaux as $niv)
                                    <option value="{{ $niv }}" {{ old('niveau', 'L1') == $niv ? 'selected' : '' }}>
                                        {{ $niv }} — {{ match (substr($niv, 0, 1)) { 'L' => 'Licence ' . substr($niv, 1), 'M' => 'Master ' . substr($niv, 1), 'D' => 'Doctorat ' . substr($niv, 1), default => $niv} }}
                                    </option>
                                @endforeach
                            </select>
                            @error('niveau')
                                <p id="erreur-niveau" class="erreur-champ mt-1 text-xs font-semibold text-rose-700">⚠ {{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="annee_academique" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Année universitaire <span class="text-rose-600" aria-hidden="true">*</span></label>
                            <select id="annee_academique" name="annee_academique" required
                                    @change="champModifie('annee_academique'); actualiserPeriode()"
                                    @error('annee_academique') aria-invalid="true" @enderror aria-describedby="@error('annee_academique') erreur-annee_academique @enderror"
                                    class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm font-semibold text-slate-900 focus:border-primary-700 focus:ring-4 focus:ring-primary-500/20 focus:outline-none @error('annee_academique') border-rose-400 bg-rose-50/50 @enderror">
                                @foreach($anneesAcademiques as $aa)
                                    <option value="{{ $aa }}" {{ old('annee_academique', $anneesAcademiques[1] ?? $anneesAcademiques[0]) == $aa ? 'selected' : '' }}>{{ $aa }}</option>
                                @endforeach
                            </select>
                            @error('annee_academique')
                                <p id="erreur-annee_academique" class="erreur-champ mt-1 text-xs font-semibold text-rose-700">⚠ {{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                    <div class="mt-6 flex flex-col sm:flex-row justify-between gap-3">
                        <button type="button" @click="etapePrecedente()"
                                class="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-6 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-4 focus:ring-primary-500/25 transition min-h-[48px]">
                            <span aria-hidden="true">←</span> Précédent
                        </button>
                {{-- Étape 3 = contexte (faculté/année) : navigation toujours libre,
                     sinon l'usager ne pourrait plus changer de contexte pour rouvrir. --}}
                        <button type="button" @click="etapeSuivante()"
                                class="inline-flex items-center justify-center gap-2 rounded-xl bg-primary-800 px-6 py-3 text-sm font-bold text-white hover:bg-primary-700 focus:outline-none focus:ring-4 focus:ring-primary-500/25 transition min-h-[48px]">
                            Continuer <span aria-hidden="true">→</span>
                        </button>
                    </div>
                </fieldset>

                {{-- ═══════ ÉTAPE 5 · DOCUMENTS (Checklist documentaire dynamique LOOP 04) ═══════ --}}
                <fieldset data-etape-contenu="5" x-show="etape === 5" class="mt-6 rounded-xl border border-slate-200 bg-slate-50/50 p-5 sm:p-6">
                    <legend class="px-2 text-sm sm:text-base font-bold text-slate-900">Étape 5 — Checklist des pièces justificatives</legend>
                    <div class="rounded-xl border border-slate-200 bg-white p-4 sm:p-5 text-sm text-slate-700">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 border-b border-slate-100 pb-3">
                            <div>
                                <h3 class="font-bold text-slate-900 text-base">Pièces requises pour votre parcours</h3>
                                <p class="text-xs text-slate-500 mt-0.5">
                                    Parcours actif : <span class="font-bold text-primary-900" x-text="libelleCategorie()"></span>
                                </p>
                            </div>
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-primary-100 px-3 py-1 text-xs font-bold text-primary-800 self-start sm:self-auto">
                                <span x-text="(checklists[categorie] || []).length"></span> pièce(s) attendue(s)
                            </span>
                        </div>

                        {{-- Liste dynamique des pièces adaptées à la catégorie --}}
                        <div class="mt-4 space-y-3" id="checklist-pieces-container">
                            <template x-for="piece in (checklists[categorie] || [])" :key="piece.code">
                                <div class="rounded-xl border p-4 transition-all"
                                     :class="piece.obligatoire ? 'border-primary-200 bg-primary-50/20' : 'border-slate-200 bg-slate-50/40'">
                                    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                                        <div class="space-y-1 flex-1">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <h4 class="font-bold text-slate-900 text-sm" x-text="piece.libelle"></h4>
                                                <template x-if="piece.obligatoire">
                                                    <span class="inline-flex items-center rounded-md bg-rose-100 px-2 py-0.5 text-[11px] font-bold text-rose-800 border border-rose-200">
                                                        Obligatoire
                                                    </span>
                                                </template>
                                                <template x-if="!piece.obligatoire">
                                                    <span class="inline-flex items-center rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600 border border-slate-200">
                                                        Facultatif
                                                    </span>
                                                </template>
                                            </div>
                                            <p class="text-xs text-slate-600" x-text="piece.description"></p>
                                            <p class="text-[11px] text-slate-500 italic" x-show="piece.consignes" x-text="'💡 ' + piece.consignes"></p>
                                        </div>
                                        <div class="text-left sm:text-right shrink-0">
                                            <div class="text-[11px] font-medium text-slate-500">
                                                Formats : <span class="font-bold text-slate-700 uppercase" x-text="(piece.formats || []).join(', ')"></span>
                                            </div>
                                            <div class="text-[11px] font-medium text-slate-500">
                                                Taille max : <span class="font-bold text-slate-700" x-text="piece.taille_max_mo + ' Mo'"></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>

                            <noscript>
                                <div class="space-y-3">
                                    @foreach(($checklistsDocuments['premiere'] ?? []) as $piece)
                                        <div class="rounded-xl border border-slate-200 bg-white p-4">
                                            <div class="flex items-center gap-2">
                                                <span class="font-bold text-slate-900 text-sm">{{ $piece['libelle'] }}</span>
                                                @if($piece['obligatoire'])
                                                    <span class="rounded bg-rose-100 px-2 py-0.5 text-xs font-bold text-rose-800">Obligatoire</span>
                                                @else
                                                    <span class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">Facultatif</span>
                                                @endif
                                            </div>
                                            <p class="text-xs text-slate-600 mt-1">{{ $piece['description'] }}</p>
                                            <div class="mt-2 text-[11px] text-slate-500">
                                                Formats : {{ strtoupper(implode(', ', $piece['formats'])) }} — Max {{ $piece['taille_max_mo'] }} Mo
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </noscript>
                        </div>

                        <div class="mt-5 rounded-lg bg-amber-50 border border-amber-200 p-3.5 text-xs text-amber-900">
                            <div class="flex items-start gap-2">
                                <span class="text-base font-bold leading-none mt-0.5">ℹ</span>
                                <div>
                                    <span class="font-bold">Instructions administratives :</span>
                                    Les pièces listées ci-dessus devront être présentées lors de la finalisation physique de votre dossier ou téléversées dans votre espace étudiant dès confirmation.
                                    Les documents facultatifs ne bloquent aucunement la validation de votre candidature.
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-6 flex flex-col sm:flex-row justify-between gap-3">
                        <button type="button" @click="etapePrecedente()"
                                class="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-6 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-4 focus:ring-primary-500/25 transition min-h-[48px]">
                            <span aria-hidden="true">←</span> Précédent
                        </button>
                        <button type="button" @click="etapeSuivante()" :disabled="!periode.interactif"
                                class="inline-flex items-center justify-center gap-2 rounded-xl bg-primary-800 px-6 py-3 text-sm font-bold text-white hover:bg-primary-700 focus:outline-none focus:ring-4 focus:ring-primary-500/25 transition min-h-[48px] disabled:opacity-60 disabled:cursor-not-allowed">
                            Vérifier mon dossier <span aria-hidden="true">→</span>
                        </button>
                    </div>
                </fieldset>

                {{-- ═══════ ÉTAPE 6 · VÉRIFICATION + SÉCURITÉ + CONFIRMATION (§10) ═══════ --}}
                <div data-etape-contenu="6" x-show="etape === 6" class="mt-6 space-y-6">
                    <fieldset class="rounded-xl border border-slate-200 bg-slate-50/50 p-5 sm:p-6" x-data="{ showPassword: false }">
                        <legend class="px-2 text-sm sm:text-base font-bold text-slate-900">Étape 6 — Vérification et confirmation</legend>

                        <h3 class="text-sm font-bold text-slate-900">Accès à votre espace étudiant</h3>
                        <p class="mt-0.5 text-xs text-slate-500">Ce mot de passe, associé à votre email ou CIN, vous permettra de suivre votre dossier. Ne le communiquez à personne.</p>
                        <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label for="password" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Mot de passe <span class="text-rose-600" aria-hidden="true">*</span></label>
                                <div class="relative">
                                    <input id="password" :disabled="!periode.interactif" name="password" type="password" required autocomplete="new-password"
                                           :type="showPassword ? 'text' : 'password'"
                                           placeholder="10 caractères min., lettre + chiffre"
                                           @error('password') aria-invalid="true" @enderror aria-describedby="@error('password') erreur-password @enderror"
                                           class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 pr-11 text-sm text-slate-900 focus:border-primary-700 focus:ring-4 focus:ring-primary-500/20 focus:outline-none @error('password') border-rose-400 bg-rose-50/50 @enderror">
                                    <button type="button" @click="showPassword = !showPassword"
                                            :aria-label="showPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe'"
                                            class="absolute inset-y-0 right-0 flex items-center pr-3.5 text-slate-400 hover:text-primary-700 min-w-[44px] justify-center">👁</button>
                                </div>
                                @error('password')
                                    <p id="erreur-password" class="erreur-champ mt-1 text-xs font-semibold text-rose-700">⚠ {{ $message }}</p>
                                @enderror
                            </div>
                            <div>
                                <label for="password_confirmation" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Confirmer le mot de passe <span class="text-rose-600" aria-hidden="true">*</span></label>
                                <input id="password_confirmation" :disabled="!periode.interactif" name="password_confirmation" :type="showPassword ? 'text' : 'password'" required autocomplete="new-password"
                                       placeholder="Répétez le mot de passe"
                                       class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-700 focus:ring-4 focus:ring-primary-500/20 focus:outline-none @error('password') border-rose-400 bg-rose-50/50 @enderror">
                            </div>
                        </div>
                    </fieldset>

                    <div class="rounded-xl border border-slate-200 bg-white p-5 sm:p-6" aria-live="polite">
                        <h3 class="text-sm font-bold text-slate-900">Récapitulatif avant envoi</h3>
                        <p class="text-xs text-slate-500">Relisez vos informations. Utilisez « Modifier » pour corriger une rubrique.</p>
                        <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
                            <div class="rounded-xl border border-slate-200 p-4">
                                <div class="flex items-center justify-between">
                                    <h4 class="text-xs font-bold uppercase tracking-wider text-slate-500">Informations</h4>
                                    <button type="button" @click="allerEtape(2)" class="text-xs font-bold text-primary-800 underline underline-offset-2 hover:text-primary-600">Modifier</button>
                                </div>
                                <dl class="mt-2 space-y-1.5 text-xs">
                                    <template x-for="ligne in recap.identite" :key="ligne[0]">
                                        <div class="flex justify-between gap-2"><dt class="text-slate-500" x-text="ligne[0]"></dt><dd class="font-bold text-slate-900 text-right break-all" x-text="ligne[1]"></dd></div>
                                    </template>
                                </dl>
                            </div>
                            <div class="rounded-xl border border-slate-200 p-4">
                                <div class="flex items-center justify-between">
                                    <h4 class="text-xs font-bold uppercase tracking-wider text-slate-500">Coordonnées</h4>
                                    <button type="button" @click="allerEtape(3)" class="text-xs font-bold text-primary-800 underline underline-offset-2 hover:text-primary-600">Modifier</button>
                                </div>
                                <dl class="mt-2 space-y-1.5 text-xs">
                                    <template x-for="ligne in recap.coordonnees" :key="ligne[0]">
                                        <div class="flex justify-between gap-2"><dt class="text-slate-500" x-text="ligne[0]"></dt><dd class="font-bold text-slate-900 text-right break-all" x-text="ligne[1]"></dd></div>
                                    </template>
                                </dl>
                            </div>
                            <div class="rounded-xl border border-slate-200 p-4">
                                <div class="flex items-center justify-between">
                                    <h4 class="text-xs font-bold uppercase tracking-wider text-slate-500">Formation</h4>
                                    <button type="button" @click="allerEtape(4)" class="text-xs font-bold text-primary-800 underline underline-offset-2 hover:text-primary-600">Modifier</button>
                                </div>
                                <dl class="mt-2 space-y-1.5 text-xs">
                                    <template x-for="ligne in recap.formation" :key="ligne[0]">
                                        <div class="flex justify-between gap-2"><dt class="text-slate-500" x-text="ligne[0]"></dt><dd class="font-bold text-slate-900 text-right break-all" x-text="ligne[1]"></dd></div>
                                    </template>
                                </dl>
                            </div>
                            <div class="rounded-xl border border-slate-200 p-4">
                                <div class="flex items-center justify-between">
                                    <h4 class="text-xs font-bold uppercase tracking-wider text-slate-500">Documents</h4>
                                    <button type="button" @click="allerEtape(5)" class="text-xs font-bold text-primary-800 underline underline-offset-2 hover:text-primary-600">Consulter</button>
                                </div>
                                <p class="mt-2 text-xs text-slate-600">
                                    <span class="font-bold text-slate-900" x-text="(checklists[categorie] || []).length"></span> pièce(s) attendue(s)
                                    (<span class="font-semibold text-rose-700" x-text="(checklists[categorie] || []).filter(p => p.obligatoire).length + ' obligatoire(s)'"></span>).
                                </p>
                            </div>
                        </div>
                    </div>

                    {{-- ── Diagnostic de conformité et contrôle pré-soumission (LOOP 09) ── --}}
                    <div class="rounded-xl border border-slate-200 bg-white p-5 sm:p-6" aria-live="polite">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-sm font-bold text-slate-900 flex items-center gap-2">
                                <span class="flex h-5 w-5 items-center justify-center rounded-full text-xs font-black text-white"
                                      :class="(diagnostic.erreursBloquantes || []).length === 0 ? 'bg-emerald-600' : 'bg-rose-600'">
                                    <span x-show="(diagnostic.erreursBloquantes || []).length === 0">✓</span>
                                    <span x-show="(diagnostic.erreursBloquantes || []).length > 0">!</span>
                                </span>
                                Contrôle de conformité avant dépôt
                            </h3>
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-bold"
                                  :class="(diagnostic.erreursBloquantes || []).length === 0 ? 'bg-emerald-100 text-emerald-800 border border-emerald-300' : 'bg-rose-100 text-rose-800 border border-rose-300'"
                                  x-text="(diagnostic.erreursBloquantes || []).length === 0 ? 'Dossier complet' : (diagnostic.erreursBloquantes || []).length + ' élément(s) bloquant(s)'">
                            </span>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">Ce diagnostic vérifie la présence de tous les prérequis obligatoires avant la transmission définitive.</p>

                        {{-- Alertes bloquantes --}}
                        <template x-if="(diagnostic.erreursBloquantes || []).length > 0">
                            <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50/70 p-4 space-y-2">
                                <p class="text-xs font-bold text-rose-900 flex items-center gap-1.5">
                                    <span class="text-rose-600 font-black">✕</span>
                                    Éléments obligatoires manquants (soumission bloquée) :
                                </p>
                                <ul class="space-y-1.5 text-xs text-rose-800 pl-4 list-disc">
                                    <template x-for="(err, idx) in diagnostic.erreursBloquantes" :key="idx">
                                        <li class="flex items-center justify-between gap-2">
                                            <span><strong x-text="err.champ + ' : '"></strong><span x-text="err.message"></span></span>
                                            <button type="button" @click="allerEtape(err.etape)" class="text-[11px] font-bold text-rose-700 underline underline-offset-2 hover:text-rose-900 ml-2">Corriger à l'étape <span x-text="err.etape"></span></button>
                                        </li>
                                    </template>
                                </ul>
                            </div>
                        </template>

                        {{-- Avertissements non bloquants --}}
                        <template x-if="(diagnostic.avertissements || []).length > 0">
                            <div class="mt-3 rounded-xl border border-amber-200 bg-amber-50/70 p-3.5 space-y-1.5">
                                <p class="text-xs font-bold text-amber-900 flex items-center gap-1.5">
                                    <span class="text-amber-600 font-bold">ℹ</span>
                                    Avertissements et rappels non bloquants :
                                </p>
                                <ul class="space-y-1 text-xs text-amber-800 pl-4 list-disc">
                                    <template x-for="(av, idx) in diagnostic.avertissements" :key="idx">
                                        <li x-text="av.message"></li>
                                    </template>
                                </ul>
                            </div>
                        </template>

                        {{-- Confirmation dossier prêt --}}
                        <template x-if="(diagnostic.erreursBloquantes || []).length === 0 && (diagnostic.avertissements || []).length === 0">
                            <div class="mt-3 rounded-xl border border-emerald-200 bg-emerald-50/60 p-3 text-xs text-emerald-800 flex items-center gap-2">
                                <span class="text-emerald-600 font-bold">✓</span>
                                Toutes les sections requises sont valides. Vous pouvez attester sur l'honneur pour déposer votre dossier.
                            </div>
                        </template>
                    </div>

                    <div class="rounded-xl border border-slate-300 bg-slate-50 p-4 sm:p-5">
                        <label for="confirmation" class="flex items-start gap-3 cursor-pointer">
                            <input type="checkbox" id="confirmation" :disabled="!periode.interactif" name="confirmation" value="1" x-model="confirmation" required
                                   class="mt-0.5 h-5 w-5 rounded border-slate-300 text-primary-800 focus:ring-4 focus:ring-primary-500/25">
                            <span class="text-xs sm:text-sm text-slate-700">Je confirme que les informations saisies sont exactes et j'atteste sur l'honneur de leur exactitude. <span class="text-rose-600 font-bold">*</span></span>
                        </label>
                    </div>

                    <div class="flex flex-col sm:flex-row justify-between gap-3">
                        <button type="button" @click="etapePrecedente()" :disabled="submitting"
                                class="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-6 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-4 focus:ring-primary-500/25 transition min-h-[48px] disabled:opacity-60">
                            <span aria-hidden="true">←</span> Modifier
                        </button>
                        <button type="submit" :disabled="submitting || horsLigne || !confirmation || !periode.interactif || (diagnostic.erreursBloquantes || []).length > 0" @disabled(! $etatInscription['interactif'])
                                class="inline-flex items-center justify-center gap-2 rounded-xl bg-primary-800 px-6 py-4 text-sm sm:text-base font-black uppercase tracking-wide text-white shadow-sm hover:bg-primary-700 focus:outline-none focus:ring-4 focus:ring-primary-500/25 transition min-h-[52px] disabled:opacity-60 disabled:cursor-not-allowed">
                            <span x-show="!submitting && !horsLigne && periode.interactif">Confirmer mon inscription</span>
                            <span x-show="!submitting && horsLigne">⚡ Connexion réseau requise</span>
                            <span x-show="!submitting && !periode.interactif">🔒 Inscriptions fermées</span>
                            <span x-show="submitting" class="inline-flex items-center gap-2">
                                <svg class="h-5 w-5 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                                Enregistrement sécurisé en cours…
                            </span>
                        </button>
                    </div>
                    <p class="text-center text-[11px] text-slate-400">Protection contre le double envoi : le bouton se désactive pendant l'enregistrement, et tout doublon CIN / email est refusé par le serveur.</p>
                </div>
            </form>
