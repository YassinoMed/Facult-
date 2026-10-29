@extends('layouts.app')

@section('title', 'Réinscription simplifiée — ' . $etudiant->nom_complet)

@section('content')
    <div class="mx-auto max-w-3xl px-4 sm:px-6 py-8" x-data="{ submitting: false, accepter: true }">
        <x-page-header titre="Réinscription {{ $contexte['annee_cible']->libelle }}" description="Parcours simplifié : dossier existant réutilisé, seule la situation académique est à valider." :fil="['Étudiant' => route('admin.etudiants.show', $etudiant), 'Réinscription' => '#']" />

        @if($errors->any())
            <div class="mt-4"><x-alert type="error" titre="Veuillez corriger les champs suivants.">
                <ul class="list-disc list-inside mt-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </x-alert></div>
        @endif

        @if($contexte['existante'] !== null)
            <div class="mt-4"><x-alert type="info" titre="Inscription déjà existante">Une inscription {{ $contexte['annee_cible']->libelle }} existe déjà (historique conservé, aucun doublon créé).</x-alert></div>
        @endif

        {{-- LOOP périodes UI §6 : bannière d'état centralisée (backend = vérité). --}}
        <div class="mt-4"><x-registration-period-banner :etat="$etatPeriode" /></div>

        <div class="mt-4 ds-card p-5">
            <h2 class="font-bold">Étudiant reconnu</h2>
            <dl class="mt-2 grid sm:grid-cols-2 gap-2 text-sm">
                <div><dt class="text-slate-500">Nom</dt><dd class="font-bold">{{ $etudiant->nom }} {{ $etudiant->prenom }}</dd></div>
                <div><dt class="text-slate-500">Matricule</dt><dd class="font-mono">{{ $etudiant->num_inscription }}</dd></div>
                <div><dt class="text-slate-500">Dernière inscription</dt><dd>{{ $contexte['derniere'] ? $contexte['derniere']->anneeUniversitaire?->libelle . ' — ' . ($contexte['derniere']->programmeRef?->nom ?? '—') . ' — ' . $contexte['derniere']->niveau : 'Aucune (première inscription)' }}</dd></div>
                <div><dt class="text-slate-500">Situation détectée</dt><dd><x-status-badge :statut="$contexte['situation']" /></dd></div>
            </dl>
        </div>

        @if($contexte['niveau_propose'] !== null)
            <div class="mt-4 ds-card p-5">
                <p class="text-sm">Niveau proposé automatiquement : <strong>{{ $contexte['niveau_propose']['code'] }}</strong></p>
                <p class="text-xs text-slate-500">Source : décision académique « {{ $contexte['source_decision'] }} ».</p>
                <label class="mt-2 flex items-center gap-2 text-sm">
                    <input type="checkbox" x-model="accepter" @disabled(! $etatPeriode['interactif']) class="h-5 w-5 rounded disabled:cursor-not-allowed">
                    Accepter la proposition (décocher pour choisir un autre niveau)
                </label>
            </div>
        @elseif($contexte['derniere'] !== null)
            <div class="mt-4"><x-alert type="warning" titre="Progression non déterminable">Aucune décision officielle ne permet de proposer un niveau. Vérifiez la décision académique avant de choisir.</x-alert></div>
        @endif

        <form method="POST" action="{{ route('admin.etudiants.reinscriptions.store', $etudiant) }}" @submit="submitting = true" class="mt-4 ds-card p-5">
            @csrf
            <input type="hidden" name="annee_universitaire_id" value="{{ $contexte['annee_cible']->id }}">

            {{-- LOOP périodes UI §4/§7 : fieldset natif = tous les contrôles
                réellement non interactifs (jamais opacity seule). --}}
            <fieldset @disabled(! $etatPeriode['interactif']) @class(['space-y-4', 'opacity-60' => ! $etatPeriode['interactif']]) aria-label="Formulaire de réinscription">
            <div class="grid sm:grid-cols-2 gap-4" x-show="!accepter">
                <div>
                    <label for="programme_id" class="mb-1 block text-xs font-bold uppercase">Formation</label>
                    <select id="programme_id" name="programme_id" class="w-full rounded-xl border px-3 py-2">
                        <option value="">— conserver —</option>
                        @foreach(\App\Modules\Referentiel\Models\Programme::where('faculte_id', $etudiant->faculte_id)->orderBy('nom')->get() as $programme)
                            <option value="{{ $programme->id }}" @selected((int) old('programme_id') === $programme->id)>{{ $programme->nom }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="niveau_id" class="mb-1 block text-xs font-bold uppercase">Niveau</label>
                    <select id="niveau_id" name="niveau_id" class="w-full rounded-xl border px-3 py-2">
                        <option value="">— proposition suivie —</option>
                        @foreach(\App\Modules\Referentiel\Models\Niveau::orderBy('ordre')->get() as $niveau)
                            <option value="{{ $niveau->id }}" @selected((int) old('niveau_id') === $niveau->id)>{{ $niveau->code }} — {{ $niveau->nom }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div>
                <h2 class="font-bold text-sm">Documents</h2>
                @if($contexte['documents_reutilisables']->isNotEmpty())
                    <ul class="mt-1 text-sm text-slate-600">
                        @foreach($contexte['documents_reutilisables'] as $document)
                            <li>✓ {{ $document->title ?? $document->document_type }} (réutilisé, non redemandé)</li>
                        @endforeach
                    </ul>
                @endif
                @if($contexte['documents_manquants'] !== [])
                    <p class="mt-1 text-sm font-bold text-amber-700">⚠ Pièces manquantes ou à renouveler : {{ implode(', ', $contexte['documents_manquants']) }}</p>
                @else
                    <p class="mt-1 text-sm text-slate-500">Aucune pièce supplémentaire requise par la configuration.</p>
                @endif
                @if(($contexte['documents_en_attente'] ?? []) !== [])
                    <p class="mt-1 text-sm font-bold text-amber-700">⏳ Déjà transmises, en attente de contrôle : {{ implode(', ', $contexte['documents_en_attente']) }}</p>
                @endif
            </div>

            <div class="rounded-xl bg-slate-50 p-3 text-sm">
                <p class="font-bold">Récapitulatif avant confirmation</p>
                <p class="mt-1 text-slate-600">✓ Identité récupérée · ✓ Dossier existant ·
                    @if($contexte['source_decision']) ✓ Décision vérifiée ({{ $contexte['source_decision'] }})
                    @else ⚠ Décision à vérifier manuellement
                    @endif</p>
            </div>

            <label class="flex items-center gap-2 text-sm font-bold">
                <input type="checkbox" name="confirmer" value="1" required class="h-5 w-5 rounded">
                Je confirme la réinscription (transaction + audit + notification)
            </label>

            <x-btn type="submit" x-bind:disabled="submitting" :disabled="! $etatPeriode['interactif']">
                {{ $etatPeriode['interactif'] ? 'Confirmer la réinscription' : '🔒 Inscriptions fermées' }}
            </x-btn>
            </fieldset>
        </form>
    </div>
@endsection
