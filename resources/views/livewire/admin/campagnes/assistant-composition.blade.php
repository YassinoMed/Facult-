<div class="mx-auto max-w-5xl px-4 sm:px-6 py-8">
    {{-- Fil d'ariane & En-tête --}}
    <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-primary-700">
        <a href="{{ route('admin.courriels.index') }}" class="hover:underline">Courriels</a>
        <span>&rsaquo;</span>
        <a href="{{ route('admin.campagnes-courriel.index') }}" class="hover:underline">Campagnes</a>
        <span>&rsaquo;</span>
        <span>Assistant de composition</span>
    </div>
    <div class="mt-2 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-6 border-b border-slate-200">
        <div>
            <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">
                {{ $campagneId ? 'Modifier la campagne' : 'Composer une campagne' }}
            </h1>
            <p class="mt-1 text-sm text-slate-500">
                Étape {{ $etape }} sur 5 : 
                @if($etape === 1) Type d'envoi et ciblage
                @elseif($etape === 2) Sélection des destinataires
                @elseif($etape === 3) Contenu du message
                @elseif($etape === 4) Contrôle et vérification
                @elseif($etape === 5) Exécution et planification
                @endif
            </p>
        </div>
        <div class="flex items-center gap-2">
            <button wire:click="sauvegarderBrouillon" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-xs font-bold text-slate-700 shadow-sm hover:bg-slate-50 transition">
                Enregistrer brouillon
            </button>
            <a href="{{ route('admin.campagnes-courriel.index') }}" class="rounded-xl px-3 py-2 text-xs font-semibold text-slate-500 hover:text-slate-800">
                Quitter
            </a>
        </div>
    </div>

    {{-- Indicateur de progression (Stepper) --}}
    <div class="mt-6">
        <nav aria-label="Progression">
            <ol class="grid grid-cols-5 gap-2 sm:gap-4">
                @php
                    $etapes = [
                        1 => '1. Type',
                        2 => '2. Destinataires',
                        3 => '3. Message',
                        4 => '4. Contrôle',
                        5 => '5. Exécution',
                    ];
                @endphp
                @foreach($etapes as $num => $label)
                    <li>
                        <button type="button" wire:click="changerEtape({{ $num }})" class="w-full text-left group">
                            <div class="h-1.5 w-full rounded-full transition {{ $etape >= $num ? 'bg-primary-600' : 'bg-slate-200' }}"></div>
                            <span class="mt-2 block text-[11px] sm:text-xs font-bold {{ $etape === $num ? 'text-primary-700' : ($etape > $num ? 'text-slate-700' : 'text-slate-400') }}">
                                {{ $label }}
                            </span>
                        </button>
                    </li>
                @endforeach
            </ol>
        </nav>
    </div>

    @if($messageErreur)
        <div class="mt-6 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-800">
            ⚠️ {{ $messageErreur }}
        </div>
    @endif

    {{-- Contenu des étapes --}}
    <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8 shadow-sm">
        {{-- ==================== ÉTAPE 1 : TYPE D'ENVOI ==================== --}}
        @if($etape === 1)
            <div class="space-y-6">
                <div>
                    <h2 class="text-lg font-bold text-slate-900">Choisissez le type d’audience</h2>
                    <p class="text-sm text-slate-500">Sélectionnez la méthode de constitution de votre liste de destinataires.</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <label class="relative flex cursor-pointer rounded-2xl border p-4 shadow-sm transition hover:border-slate-300 {{ $typeCiblage === 'dynamique_etudiants' ? 'border-primary-600 bg-primary-50/30 ring-1 ring-primary-600' : 'border-slate-200' }}">
                        <input type="radio" wire:model.live="typeCiblage" value="dynamique_etudiants" class="sr-only" />
                        <div class="flex flex-col">
                            <span class="text-sm font-black text-slate-900">🎓 Groupe dynamique d’étudiants</span>
                            <span class="mt-1 text-xs text-slate-500">Ciblage automatique selon la faculté, le programme, le niveau ou l’année académique.</span>
                        </div>
                    </label>

                    <label class="relative flex cursor-pointer rounded-2xl border p-4 shadow-sm transition hover:border-slate-300 {{ $typeCiblage === 'dynamique_enseignants' ? 'border-primary-600 bg-primary-50/30 ring-1 ring-primary-600' : 'border-slate-200' }}">
                        <input type="radio" wire:model.live="typeCiblage" value="dynamique_enseignants" class="sr-only" />
                        <div class="flex flex-col">
                            <span class="text-sm font-black text-slate-900">👨‍🏫 Groupe dynamique d’enseignants</span>
                            <span class="mt-1 text-xs text-slate-500">Ciblage selon le département, le grade académique ou la spécialité.</span>
                        </div>
                    </label>

                    <label class="relative flex cursor-pointer rounded-2xl border p-4 shadow-sm transition hover:border-slate-300 {{ $typeCiblage === 'liste_fixe' ? 'border-primary-600 bg-primary-50/30 ring-1 ring-primary-600' : 'border-slate-200' }}">
                        <input type="radio" wire:model.live="typeCiblage" value="liste_fixe" class="sr-only" />
                        <div class="flex flex-col">
                            <span class="text-sm font-black text-slate-900">📋 Liste personnalisée</span>
                            <span class="mt-1 text-xs text-slate-500">Sélection manuelle d'une liste de personnes avec cases à cocher.</span>
                        </div>
                    </label>

                    <label class="relative flex cursor-pointer rounded-2xl border p-4 shadow-sm transition hover:border-slate-300 {{ $typeCiblage === 'groupe_enregistre' ? 'border-primary-600 bg-primary-50/30 ring-1 ring-primary-600' : 'border-slate-200' }}">
                        <input type="radio" wire:model.live="typeCiblage" value="groupe_enregistre" class="sr-only" />
                        <div class="flex flex-col">
                            <span class="text-sm font-black text-slate-900">📁 Groupe enregistré</span>
                            <span class="mt-1 text-xs text-slate-500">Réutilisation d'un groupe préconfiguré (dynamique ou liste fixe).</span>
                        </div>
                    </label>

                    <label class="relative flex cursor-pointer rounded-2xl border p-4 shadow-sm transition hover:border-slate-300 sm:col-span-2 {{ $typeCiblage === 'individuel' ? 'border-primary-600 bg-primary-50/30 ring-1 ring-primary-600' : 'border-slate-200' }}">
                        <input type="radio" wire:model.live="typeCiblage" value="individuel" class="sr-only" />
                        <div class="flex flex-col">
                            <span class="text-sm font-black text-slate-900">👤 Envoi individuel</span>
                            <span class="mt-1 text-xs text-slate-500">Envoi direct à une personne spécifique ou une adresse e-mail institutionnelle.</span>
                        </div>
                    </label>
                </div>
            </div>
        @endif

        {{-- ==================== ÉTAPE 2 : DESTINATAIRES ==================== --}}
        @if($etape === 2)
            <div class="space-y-6">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-bold text-slate-900">Filtrage et sélection des destinataires</h2>
                        <p class="text-sm text-slate-500">Ajustez les critères réels de la base de données pour cibler votre audience.</p>
                    </div>

                    {{-- Badge estimation --}}
                    <div class="flex items-center gap-3 rounded-2xl border border-primary-200 bg-primary-50 px-4 py-3">
                        <div class="text-right">
                            <p class="text-[11px] font-bold uppercase tracking-wider text-primary-800">Destinataires estimés</p>
                            <p class="text-xl font-black text-primary-900 tabular-nums">{{ number_format($estimationValides) }}</p>
                        </div>
                        @if($estimationDoublons > 0 || $estimationInvalides > 0)
                            <div class="border-l border-primary-200 pl-3 text-[11px] text-primary-700">
                                @if($estimationDoublons > 0)
                                    <p>{{ $estimationDoublons }} doublon(s) exclu(s)</p>
                                @endif
                                @if($estimationInvalides > 0)
                                    <p>{{ $estimationInvalides }} adresse(s) invalide(s)</p>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Mode 1 : Dynamique étudiants --}}
                @if($typeCiblage === 'dynamique_etudiants')
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 p-5 rounded-2xl bg-slate-50 border border-slate-200">
                        <div>
                            <label class="block text-xs font-bold text-slate-700">Faculté</label>
                            <select wire:model.live="filtreFaculteId" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                                <option value="">Toutes les facultés</option>
                                @foreach($facultes as $f)
                                    <option value="{{ $f->id }}">{{ $f->nom }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700">Programme / Filière</label>
                            <select wire:model.live="filtreProgrammeId" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                                <option value="">Tous les programmes</option>
                                @foreach($programmes as $p)
                                    <option value="{{ $p->id }}">{{ $p->nom }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700">Groupe / TD</label>
                            <select wire:model.live="filtreGroupeId" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                                <option value="">Tous les groupes</option>
                                @foreach($groupes as $g)
                                    <option value="{{ $g->id }}">{{ $g->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700">Niveau</label>
                            <select wire:model.live="filtreNiveau" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                                <option value="">Tous les niveaux</option>
                                <option value="L1">L1</option>
                                <option value="L2">L2</option>
                                <option value="L3">L3</option>
                                <option value="M1">M1</option>
                                <option value="M2">M2</option>
                                <option value="D1">D1</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700">Année académique</label>
                            <select wire:model.live="filtreAnneeAcademique" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                                <option value="">Toutes les années</option>
                                <option value="2025-2026">2025-2026</option>
                                <option value="2026-2027">2026-2027</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700">Statut de dossier</label>
                            <select wire:model.live="filtreStatutEtudiant" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                                <option value="">Tous les statuts</option>
                                <option value="valide">Validé</option>
                                <option value="en_attente">En attente</option>
                                <option value="rejete">Rejeté</option>
                            </select>
                        </div>
                    </div>
                @endif

                {{-- Mode 2 : Dynamique enseignants --}}
                @if($typeCiblage === 'dynamique_enseignants')
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 p-5 rounded-2xl bg-slate-50 border border-slate-200">
                        <div>
                            <label class="block text-xs font-bold text-slate-700">Département</label>
                            <select wire:model.live="filtreDepartementId" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                                <option value="">Tous les départements</option>
                                @foreach($departements as $d)
                                    <option value="{{ $d->id }}">{{ $d->nom }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700">Grade académique</label>
                            <select wire:model.live="filtreGradeId" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                                <option value="">Tous les grades</option>
                                @foreach($grades as $gr)
                                    <option value="{{ $gr->id }}">{{ $gr->nom }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700">Spécialité</label>
                            <select wire:model.live="filtreSpecialiteId" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                                <option value="">Toutes les spécialités</option>
                                @foreach($specialites as $sp)
                                    <option value="{{ $sp->id }}">{{ $sp->nom }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                @endif

                {{-- Mode 3 : Groupe enregistré --}}
                @if($typeCiblage === 'groupe_enregistre')
                    <div class="p-5 rounded-2xl bg-slate-50 border border-slate-200 space-y-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-700">Sélectionner un groupe enregistré</label>
                            <select wire:model.live="groupeEnregistreId" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm">
                                <option value="">-- Choisir un groupe --</option>
                                @foreach($groupesEnregistres as $ge)
                                    <option value="{{ $ge->id }}">{{ $ge->nom }} ({{ $ge->type }})</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                @endif

                {{-- Mode 4 : Envoi individuel --}}
                @if($typeCiblage === 'individuel')
                    <div class="p-5 rounded-2xl bg-slate-50 border border-slate-200 grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-700">Adresse e-mail du destinataire</label>
                            <input type="email" wire:model.live="destinataireIndividuelEmail" placeholder="etudiant@univ.test" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700">Nom complet (facultatif)</label>
                            <input type="text" wire:model.live="destinataireIndividuelNom" placeholder="Jean Dupont" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm" />
                        </div>
                    </div>
                @endif

                {{-- Mode 5 : Sélection fixe paginée --}}
                @if($typeCiblage === 'liste_fixe')
                    <div class="space-y-4">
                        <p class="text-xs font-bold text-slate-700">Sélectionnez les personnes à inclure :</p>
                        
                        @if($apercuEtudiants)
                            <div class="rounded-xl border border-slate-200 overflow-hidden">
                                <div class="bg-slate-50 px-4 py-2 text-xs font-bold text-slate-700 border-b border-slate-200">
                                    Étudiants ({{ count(array_filter($selectionEtudiants)) }} sélectionné(s))
                                </div>
                                <div class="max-h-60 overflow-y-auto divide-y divide-slate-100 text-sm">
                                    @foreach($apercuEtudiants as $etudiant)
                                        <label class="flex items-center gap-3 px-4 py-2 hover:bg-slate-50 cursor-pointer">
                                            <input type="checkbox" wire:model.live="selectionEtudiants.{{ $etudiant->id }}" class="rounded text-primary-600 focus:ring-primary-500" />
                                            <div class="flex-1">
                                                <span class="font-semibold text-slate-900">{{ $etudiant->nomComplet() }}</span>
                                                <span class="text-xs text-slate-500 ml-2">&lt;{{ $etudiant->email }}&gt;</span>
                                            </div>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        @endif

        {{-- ==================== ÉTAPE 3 : MESSAGE & MODÈLE ==================== --}}
        @if($etape === 3)
            <div class="space-y-6">
                <div>
                    <h2 class="text-lg font-bold text-slate-900">Composition du message</h2>
                    <p class="text-sm text-slate-500">Définissez l'objet, le modèle ou le contenu de votre communication.</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700">Titre interne de la campagne</label>
                        <input type="text" wire:model.live="titre" placeholder="Ex : Relance inscription Master 2026" maxlength="150" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700">Objet de l'e-mail</label>
                        <input type="text" wire:model.live="sujet" placeholder="Ex : Clôture imminente de votre dossier" maxlength="255" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm" />
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700">Modèle de courriel (Studio e-mail)</label>
                    <select wire:model.live="modeleId" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                        <option value="">-- Aucun (Message texte direct) --</option>
                        @foreach($modelesCourriel as $mod)
                            <option value="{{ $mod->id }}">{{ $mod->nom }} ({{ $mod->code }})</option>
                        @endforeach
                    </select>
                </div>

                @if(! $modeleId)
                    <div>
                        <label class="block text-xs font-bold text-slate-700">Corps du message (texte brut / markdown léger)</label>
                        <textarea wire:model.live="contenuTexte" rows="6" placeholder="Saisissez ici le texte de votre message..." class="mt-1 w-full rounded-xl border border-slate-200 bg-white p-3 text-sm focus:border-primary-500 focus:outline-none"></textarea>
                    </div>
                @endif

                {{-- Prévisualisation intégrée --}}
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">Aperçu du rendu</p>
                    <div class="rounded-xl border border-slate-200 bg-white p-4 max-h-72 overflow-y-auto">
                        {!! $apercuHtml !!}
                    </div>
                </div>
            </div>
        @endif

        {{-- ==================== ÉTAPE 4 : CONTRÔLE & VÉRIFICATION ==================== --}}
        @if($etape === 4)
            <div class="space-y-6">
                <div>
                    <h2 class="text-lg font-bold text-slate-900">Vérification avant exécution</h2>
                    <p class="text-sm text-slate-500">Vérifiez l’ensemble des paramètres et confirmez l’envoi de votre campagne.</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Expéditeur institutionnel</p>
                        <p class="mt-1 text-sm font-semibold text-slate-900">{{ config('app.name') }} &lt;{{ config('inscriptions.contact_scolarite.email', 'scolarite@univ.test') }}&gt;</p>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Objet final</p>
                        <p class="mt-1 text-sm font-semibold text-slate-900">{{ $sujet }}</p>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Destinataires valides retenus</p>
                        <p class="mt-1 text-2xl font-black text-emerald-600 tabular-nums">{{ number_format($estimationValides) }}</p>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Destinataires exclus</p>
                        <p class="mt-1 text-sm text-slate-600">
                            {{ $estimationDoublons }} doublon(s) dédupliqué(s) · {{ $estimationInvalides }} adresse(s) manquante(s) ou invalide(s)
                        </p>
                    </div>
                </div>

                @if($estimationValides > 500)
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                        <span class="font-bold">⚠️ Volume important détecté :</span> Cette campagne cible plus de 500 destinataires. L'envoi sera automatiquement découpé en lots asynchrones maîtrisés pour respecter les quotas SMTP.
                    </div>
                @endif

                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" wire:model.live="confirmationExplicite" class="mt-1 rounded text-primary-600 focus:ring-primary-500" />
                        <span class="text-sm font-semibold text-slate-900">
                            Je confirme expressément l'exactitude des critères d'audience et du message à destination des personnes ciblées.
                        </span>
                    </label>
                </div>
            </div>
        @endif

        {{-- ==================== ÉTAPE 5 : EXÉCUTION ==================== --}}
        @if($etape === 5)
            <div class="space-y-6">
                <div>
                    <h2 class="text-lg font-bold text-slate-900">Modalités d'exécution</h2>
                    <p class="text-sm text-slate-500">Choisissez quand et comment la campagne doit être déclenchée.</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    {{-- Option 1 : Envoi immédiat --}}
                    <div class="rounded-2xl border border-slate-200 p-6 flex flex-col justify-between hover:border-slate-300 transition">
                        <div>
                            <span class="text-2xl">⚡</span>
                            <h3 class="mt-2 text-base font-bold text-slate-900">Envoi immédiat</h3>
                            <p class="mt-1 text-xs text-slate-500">Les lots de messages seront immédiatement injectés dans la file d'attente pour envoi asynchrone.</p>
                        </div>
                        <div class="mt-6">
                            <button wire:click="envoyerImmediatement" class="w-full rounded-xl bg-primary-600 px-4 py-2.5 text-xs font-bold text-white shadow-sm hover:bg-primary-700 transition">
                                Envoyer maintenant ({{ number_format($estimationValides) }} destinataires)
                            </button>
                        </div>
                    </div>

                    {{-- Option 2 : Programmation différée --}}
                    <div class="rounded-2xl border border-slate-200 p-6 flex flex-col justify-between hover:border-slate-300 transition">
                        <div>
                            <span class="text-2xl">🕒</span>
                            <h3 class="mt-2 text-base font-bold text-slate-900">Programmation différée</h3>
                            <p class="mt-1 text-xs text-slate-500">La campagne sera mise en attente et déclenchée automatiquement par le scheduler à la date fixée.</p>
                            
                            <div class="mt-4 grid grid-cols-2 gap-2">
                                <div>
                                    <label class="block text-[11px] font-bold text-slate-600">Date</label>
                                    <input type="date" wire:model.live="dateProgrammation" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs" />
                                </div>
                                <div>
                                    <label class="block text-[11px] font-bold text-slate-600">Heure</label>
                                    <input type="time" wire:model.live="heureProgrammation" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs" />
                                </div>
                            </div>
                        </div>
                        <div class="mt-6">
                            <button wire:click="planifierCampagne" class="w-full rounded-xl bg-blue-600 px-4 py-2.5 text-xs font-bold text-white shadow-sm hover:bg-blue-700 transition">
                                Programmer la campagne
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- Boutons Suivant / Précédent --}}
        <div class="mt-8 pt-6 border-t border-slate-200 flex items-center justify-between">
            @if($etape > 1)
                <button wire:click="changerEtape({{ $etape - 1 }})" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-xs font-bold text-slate-700 shadow-sm hover:bg-slate-50 transition">
                    &larr; Précédent
                </button>
            @else
                <div></div>
            @endif

            @if($etape < 5)
                <button wire:click="changerEtape({{ $etape + 1 }})" class="rounded-xl bg-primary-600 px-5 py-2 text-xs font-bold text-white shadow-sm hover:bg-primary-700 transition">
                    Suivant &rarr;
                </button>
            @endif
        </div>
    </div>
</div>
