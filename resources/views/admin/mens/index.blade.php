@extends('layouts.app')

@section('title', 'Référentiel MENS — Scolarité')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 py-8" x-data="{ modaleDossier: false, mensCourant: null }" @mens-creer-dossier.window="mensCourant = $event.detail; modaleDossier = true">
        <x-page-header titre="Référentiel MENS" description="Données nationales importées pour les inscriptions de la faculté (lecture seule — import via le centre Données)."
            :fil="['Données' => route('admin.donnees.hub'), 'MENS' => '#']">
            <x-slot:actions>
                <x-btn href="{{ route('admin.donnees.import.form', 'referentiel-mens') }}" variant="secondary">Importer un fichier</x-btn>
                <x-btn href="{{ route('admin.donnees.modele', ['referentiel-mens', 'csv']) }}" variant="ghost">Modèle CSV</x-btn>
            </x-slot:actions>
        </x-page-header>

        <div class="mt-4 ds-card p-4 flex flex-col sm:flex-row sm:items-center gap-2 text-xs text-slate-600">
            <span class="font-bold text-slate-900 tabular-nums">{{ number_format($total, 0, ',', ' ') }}</span>
            <span>référence(s) concernée(s).</span>
            <span class="text-slate-400">Formats d'import : CSV, XLSX, JSON, XML (pipeline : analyse → prévisualisation → confirmation → rapport, upsert idempotent, jamais de vidage).</span>
        </div>

        <form method="GET" action="{{ route('admin.mens.index') }}" class="mt-4 flex flex-col sm:flex-row gap-2" role="search">
            <x-input id="recherche-mens" label="Recherche (nom, prénom, CIN)" name="q" :value="$search" placeholder="Ex. BENALI ou BK123456" />
            <x-select id="filtre-annee" label="Année" name="annee" :options="collect($annees)->mapWithKeys(fn ($a) => [$a => $a])->all()" :valeur="request('annee')" vide="Toutes" />
            <x-select id="filtre-appariement" label="Dossier local" name="appariement" :options="['apparies' => 'Appariés', 'non_apparies' => 'Non appariés']" :valeur="request('appariement')" vide="Tous" />
            <div class="flex items-end gap-2">
                <x-btn type="submit" variant="secondary">Filtrer</x-btn>
                <x-btn href="{{ route('admin.mens.index') }}" variant="ghost">Réinitialiser</x-btn>
            </div>
        </form>

        <div class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            @if($references->isNotEmpty())
                <table class="w-full min-w-[840px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-4 py-3 font-bold">CIN</th>
                            <th scope="col" class="px-4 py-3 font-bold">Nom</th>
                            <th scope="col" class="px-4 py-3 font-bold">Prénom</th>
                            <th scope="col" class="px-4 py-3 font-bold">Naissance</th>
                            <th scope="col" class="px-4 py-3 font-bold">Année</th>
                            <th scope="col" class="px-4 py-3 font-bold">Programme</th>
                            <th scope="col" class="px-4 py-3 font-bold">Matricule nat.</th>
                            <th scope="col" class="px-4 py-3 font-bold">Dossier</th>
                            <th scope="col" class="px-4 py-3 font-bold"><span class="sr-only">Action</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($references as $reference)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-4 py-3 font-mono text-xs font-semibold">{{ $reference->cin_normalise }}</td>
                                <td class="px-4 py-3 font-bold">{{ $reference->nom ?? '—' }}</td>
                                <td class="px-4 py-3">{{ $reference->prenom ?? '—' }}</td>
                                <td class="px-4 py-3 tabular-nums">{{ $reference->date_naissance?->format('d/m/Y') ?? '—' }}</td>
                                <td class="px-4 py-3">{{ $reference->annee }}</td>
                                <td class="px-4 py-3 font-mono text-xs">{{ $reference->programme_code ?? '—' }}</td>
                                <td class="px-4 py-3 font-mono text-xs">{{ $reference->matricule_national ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    @if((int) ($reference->appariement_local ?? 0) === 1)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">Apparié</span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-600">Sans dossier</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @if((int) ($reference->appariement_local ?? 0) !== 1)
                                        <button type="button" @click="$dispatch('mens-creer-dossier', {{ Js::from(['id' => $reference->id, 'nom' => $reference->nom, 'prenom' => $reference->prenom, 'sexe' => $reference->sexe, 'niveau' => $reference->niveau, 'programme' => $reference->programme_code, 'annee' => $reference->annee]) }})" class="rounded-lg bg-primary-50 px-2.5 py-1 text-[11px] font-bold text-primary-700 hover:bg-primary-100">Créer le dossier</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-5 py-3 border-t border-slate-100">{{ $references->links() }}</div>
            @else
                <div class="p-5"><x-empty-state message="Aucune référence MENS. Importez le fichier officiel depuis le centre Données." /></div>
            @endif
        </div>

        {{-- Création du dossier facultaire depuis MENS (Cas C) : l'e-mail et
            l'adresse manquent toujours côté MENS, l'agent les fournit ;
            tout le reste est repris de la référence, sans écrasement. --}}
        <div x-show="modaleDossier" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true">
            <div class="fixed inset-0 bg-slate-900/60" @click="modaleDossier = false"></div>
            <div class="relative w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-slate-900 border border-slate-200 dark:border-slate-800">
                <h3 class="text-base font-bold text-slate-900 dark:text-white">Créer le dossier facultaire</h3>
                <p class="mt-1 text-xs text-slate-500" x-text="mensCourant ? mensCourant.prenom + ' ' + mensCourant.nom + ' — ' + (mensCourant.programme || 'sans programme') + ' (' + mensCourant.annee + ')' : ''"></p>
                <form method="POST" :action="'/admin/mens/' + (mensCourant ? mensCourant.id : 0) + '/creer-dossier'" class="mt-4 space-y-3">
                    @csrf
                    <div>
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1" for="mens-email">E-mail institutionnel <span class="text-rose-500">*</span></label>
                        <input type="email" id="mens-email" name="email" required value="{{ old('email') }}" autocomplete="email" class="w-full rounded-xl border border-slate-200 p-2 text-xs dark:border-slate-700 dark:bg-slate-800">
                        @error('email')<p class="mt-1 text-[11px] font-bold text-rose-600">{{ $message }}</p>@enderror
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1" for="mens-sexe">Sexe</label>
                            <select id="mens-sexe" name="sexe" class="w-full rounded-xl border border-slate-200 p-2 text-xs dark:border-slate-700 dark:bg-slate-800">
                                <option value="">Référence MENS</option>
                                <option value="M">M</option>
                                <option value="F">F</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1" for="mens-niveau">Niveau</label>
                            <select id="mens-niveau" name="niveau" class="w-full rounded-xl border border-slate-200 p-2 text-xs dark:border-slate-700 dark:bg-slate-800">
                                <option value="">Référence MENS</option>
                                @foreach(['L1','L2','L3','M1','M2','D1','D2','D3'] as $niveau)
                                    <option value="{{ $niveau }}">{{ $niveau }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1" for="mens-adresse">Adresse <span class="text-rose-500">*</span></label>
                        <input type="text" id="mens-adresse" name="adresse" required maxlength="500" value="{{ old('adresse') }}" class="w-full rounded-xl border border-slate-200 p-2 text-xs dark:border-slate-700 dark:bg-slate-800">
                        @error('adresse')<p class="mt-1 text-[11px] font-bold text-rose-600">{{ $message }}</p>@enderror
                    </div>
                    <div class="grid grid-cols-3 gap-2">
                        <div class="col-span-1">
                            <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1" for="mens-ville">Ville <span class="text-rose-500">*</span></label>
                            <input type="text" id="mens-ville" name="ville" required maxlength="100" value="{{ old('ville') }}" class="w-full rounded-xl border border-slate-200 p-2 text-xs dark:border-slate-700 dark:bg-slate-800">
                        </div>
                        <div class="col-span-1">
                            <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1" for="mens-cp">Code postal <span class="text-rose-500">*</span></label>
                            <input type="text" id="mens-cp" name="code_postal" required maxlength="20" value="{{ old('code_postal') }}" class="w-full rounded-xl border border-slate-200 p-2 text-xs dark:border-slate-700 dark:bg-slate-800">
                        </div>
                        <div class="col-span-1">
                            <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1" for="mens-bac">Année bac <span class="text-rose-500">*</span></label>
                            <input type="text" id="mens-bac" name="annee_bac" required maxlength="10" value="{{ old('annee_bac') }}" inputmode="numeric" class="w-full rounded-xl border border-slate-200 p-2 text-xs dark:border-slate-700 dark:bg-slate-800">
                        </div>
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="modaleDossier = false" class="rounded-xl border px-3 py-1.5 text-xs text-slate-700">Annuler</button>
                        <button type="submit" class="rounded-xl bg-primary-600 px-4 py-1.5 text-xs font-bold text-white">Créer le dossier</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
