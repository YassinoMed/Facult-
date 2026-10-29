@extends('layouts.app')

@section('title', 'Référence MENS ' . $reference->cin_normalise . ' — Scolarité')

@section('content')
    <div class="mx-auto max-w-5xl px-4 sm:px-6 py-8">
        <x-page-header titre="Référence MENS — {{ $reference->cin_normalise }} ({{ $reference->annee }})"
            description="Fiche de consultation : données nationales, rapprochement local, traçabilité et actions contrôlées."
            :fil="['Données' => route('admin.donnees.hub'), 'MENS' => route('admin.mens.index'), 'Détails' => '#']">
            <x-slot:actions>
                <x-btn href="{{ route('admin.mens.edit', $reference) }}">Modifier</x-btn>
                <x-btn href="{{ route('admin.mens.index') }}" variant="secondary">Retour à la liste</x-btn>
            </x-slot:actions>
        </x-page-header>

        @if($errors->has('reference'))
            <div class="mt-4"><x-alert type="error" titre="Suppression bloquée">{{ $errors->first('reference') }}</x-alert></div>
        @endif

        <div class="mt-4 grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="ds-card p-5">
                <h2 class="text-sm font-bold text-slate-900">Identité (données nationales)</h2>
                <dl class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-3 text-sm">
                    <div>
                        <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400">CIN normalisée</dt>
                        <dd class="font-mono font-semibold">{{ $reference->cin_normalise }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400">CIN d'origine</dt>
                        <dd class="font-mono">{{ $reference->cin_original ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Date de naissance</dt>
                        <dd class="tabular-nums">{{ $reference->date_naissance?->format('d/m/Y') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Sexe</dt>
                        <dd>{{ $reference->sexe ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Nom (latin)</dt>
                        <dd class="font-bold">{{ $reference->nom ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Prénom (latin)</dt>
                        <dd>{{ $reference->prenom ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Nom (arabe)</dt>
                        <dd dir="rtl">{{ $reference->nom_ar ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Prénom (arabe)</dt>
                        <dd dir="rtl">{{ $reference->prenom_ar ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Nationalité</dt>
                        <dd>{{ $reference->nationalite ?? '—' }}</dd>
                    </div>
                </dl>
            </div>

            <div class="ds-card p-5">
                <h2 class="text-sm font-bold text-slate-900">Rattachement &amp; scolarité</h2>
                <dl class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-3 text-sm">
                    <div>
                        <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Année (clé)</dt>
                        <dd>{{ $reference->annee }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Programme</dt>
                        <dd class="font-mono">{{ $reference->programme_code ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Niveau</dt>
                        <dd>{{ $reference->niveau ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Matricule national</dt>
                        <dd class="font-mono">{{ $reference->matricule_national ?? '—' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Établissement</dt>
                        <dd>{{ $reference->etablissement ?? '—' }}</dd>
                    </div>
                </dl>
            </div>

            <div class="ds-card p-5">
                <h2 class="text-sm font-bold text-slate-900">Provenance &amp; écriture</h2>
                <dl class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-3 text-sm">
                    <div class="sm:col-span-2">
                        <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Provenance</dt>
                        <dd>
                            @if($reference->imported_at !== null)
                                Importée le {{ $reference->imported_at->format('d/m/Y H:i') }}@if($reference->imported_by !== null) (utilisateur #{{ $reference->imported_by }})@endif
                            @else
                                Saisie manuelle (hors pipeline d'import)
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Créée le</dt>
                        <dd class="tabular-nums">{{ $reference->created_at?->format('d/m/Y H:i') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Mise à jour le</dt>
                        <dd class="tabular-nums">{{ $reference->updated_at?->format('d/m/Y H:i') ?? '—' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Empreinte du contenu</dt>
                        <dd class="break-all font-mono text-[10px] text-slate-500">{{ $reference->source_hash }}</dd>
                    </div>
                </dl>
            </div>

            <div class="ds-card p-5">
                <h2 class="text-sm font-bold text-slate-900">Dossiers locaux rapprochés</h2>
                @if($etudiantsRapproches->isEmpty())
                    <p class="mt-2 text-sm text-slate-500">Aucun dossier local rapproché pour cette CIN.</p>
                @else
                    <ul class="mt-2 divide-y divide-slate-100 text-sm">
                        @foreach($etudiantsRapproches as $etudiant)
                            <li class="flex items-center justify-between gap-2 py-2">
                                <span>
                                    {{ $etudiant->prenom }} {{ $etudiant->nom }}
                                    <span class="text-xs text-slate-400">{{ $etudiant->faculte?->code }}</span>
                                </span>
                                <a href="{{ route('admin.etudiants.show', $etudiant) }}"
                                   class="text-xs font-bold text-primary-700 underline underline-offset-2">Fiche</a>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if($blocagesSuppression !== [])
                    <p class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
                        Suppression impossible : {{ implode(' ; ', $blocagesSuppression) }}.
                    </p>
                @endif
            </div>
        </div>

        <div class="mt-4 ds-card p-5">
            <h2 class="text-sm font-bold text-slate-900">Traçabilité (journal central)</h2>
            @if($journal->isEmpty())
                <p class="mt-2 text-sm text-slate-500">Aucune action journalisée pour cette référence.</p>
            @else
                <ul class="mt-2 divide-y divide-slate-100 text-sm">
                    @foreach($journal as $entree)
                        <li class="flex items-center justify-between gap-2 py-2">
                            <span>
                                {{ $entree->action_libelle }}
                                <span class="text-xs text-slate-400">— {{ $entree->utilisateur?->name ?? 'système' }}</span>
                            </span>
                            <span class="text-xs tabular-nums text-slate-500">{{ $entree->created_at?->format('d/m/Y H:i') }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        @if($blocagesSuppression === [])
            <form method="POST" action="{{ route('admin.mens.destroy', $reference) }}" class="mt-4"
                  x-data="{ confirmer: false }" @submit.prevent="confirmer = true">
                @csrf @method('DELETE')
                <x-btn variant="danger" type="submit">Supprimer cette référence</x-btn>
                <x-modal titre="Supprimer cette référence MENS ?" nom="confirmer">
                    <p>La référence <span class="font-mono font-bold">{{ $reference->cin_normalise }}</span> ({{ $reference->annee }}) sera supprimée définitivement. L'action est journalisée.</p>
                    <p class="mt-2 text-xs text-rose-700">Refusée automatiquement si un dossier local est rapproché ou si des traces historiques la référencent.</p>
                    <x-slot:actions>
                        <x-btn variant="secondary" @click="confirmer = false">Annuler</x-btn>
                        <x-btn variant="danger" @click="$root.requestSubmit()">Supprimer</x-btn>
                    </x-slot:actions>
                </x-modal>
            </form>
        @endif
    </div>
@endsection
