@extends('layouts.app')

@section('title', 'Listes et exports')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Listes et exports" description="Filtrez, prévisualisez, exportez ou imprimez — une seule population."
            :fil="['Listes' => '#']" />

        <form method="get" action="{{ route('admin.listes.preview') }}" class="mt-4 ds-card grid gap-3 p-5 sm:grid-cols-3">
            <div>
                <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="f-annee">Année</label>
                <select id="f-annee" name="annee_universitaire_id" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                    <option value="">Toutes</option>
                    @foreach($filtres['annees'] as $annee)
                        <option value="{{ $annee->id }}">{{ $annee->libelle }}</option>
                    @endforeach
                </select>
            </div>
            @if($filtres['facultes']->isNotEmpty())
                <div>
                    <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="f-fac">Faculté</label>
                    <select id="f-fac" name="faculte_id" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                        <option value="">Toutes</option>
                        @foreach($filtres['facultes'] as $faculte)
                            <option value="{{ $faculte->id }}">{{ $faculte->nom }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div>
                <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="f-prog">Programme</label>
                <select id="f-prog" name="programme_id" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                    <option value="">Tous</option>
                    @foreach($filtres['programmes'] as $programme)
                        <option value="{{ $programme->id }}">{{ $programme->code }} — {{ $programme->nom }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="f-niv">Niveau</label>
                <select id="f-niv" name="niveau" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                    <option value="">Tous</option>
                    @foreach(['L1','L2','L3','M1','M2','D1','D2','D3'] as $niveau)
                        <option value="{{ $niveau }}">{{ $niveau }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="f-groupe">Groupe</label>
                <select id="f-groupe" name="groupe_id" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                    <option value="">Tous</option>
                    @foreach($filtres['groupes'] as $groupe)
                        <option value="{{ $groupe->id }}">{{ $groupe->code }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="f-ssgroupe">Sous-groupe</label>
                <select id="f-ssgroupe" name="sous_groupe_id" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                    <option value="">Tous</option>
                    @foreach($filtres['sous_groupes'] as $sg)
                        <option value="{{ $sg->id }}">{{ $sg->code }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="f-statut">Statut</label>
                <select id="f-statut" name="statut" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                    <option value="">Tous</option>
                    @foreach(['en_attente','valide','rejete'] as $statut)
                        <option value="{{ $statut }}">{{ $statut }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="f-matiere">ECUE</label>
                <select id="f-matiere" name="matiere_id" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                    <option value="">Toutes</option>
                    @foreach($filtres['matieres'] as $matiere)
                        <option value="{{ $matiere->id }}">{{ $matiere->code }} — {{ $matiere->nom }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="f-ens">Enseignant</label>
                <select id="f-ens" name="enseignant_id" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                    <option value="">Tous</option>
                    @foreach($filtres['enseignants'] as $enseignant)
                        <option value="{{ $enseignant->id }}">{{ $enseignant->nom }} {{ $enseignant->prenom }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="f-titre">Titre du document</label>
                <input id="f-titre" type="text" name="titre" maxlength="150" placeholder="Liste des étudiants" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
            </div>
            <div>
                <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="f-regroup">Regrouper par</label>
                <select id="f-regroup" name="regroupement" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                    <option value="">Sans regroupement</option>
                    @foreach(['programme' => 'Programme', 'niveau' => 'Niveau', 'groupe' => 'Groupe', 'sous_groupe' => 'Sous-groupe', 'statut' => 'Statut', 'faculte' => 'Faculté', 'semestre' => 'Semestre', 'matiere' => 'ECUE / Module'] as $valeur => $libelle)
                        <option value="{{ $valeur }}">{{ $libelle }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="f-orient">Orientation PDF</label>
                <select id="f-orient" name="orientation" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                    <option value="auto">Automatique</option>
                    <option value="portrait">Portrait</option>
                    <option value="paysage">Paysage</option>
                </select>
            </div>
            <div class="flex items-end gap-4">
                <label class="inline-flex items-center gap-1 text-sm"><input type="checkbox" name="sans_groupe" value="1" /> Sans groupe</label>
                <label class="inline-flex items-center gap-1 text-sm"><input type="checkbox" name="emargement" value="1" /> Émargement</label>
                <label class="inline-flex items-center gap-1 text-sm"><input type="checkbox" name="zone_signature" value="1" /> Zone signature</label>
            </div>
            <div class="sm:col-span-3">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Colonnes (CIN/email/téléphone selon permission)</span>
                <div class="mt-1 flex flex-wrap gap-2">
                    @foreach($catalogue as $colonne)
                        <label class="inline-flex items-center gap-1 text-xs rounded-full border border-slate-200 px-2.5 py-1">
                            <input type="checkbox" name="colonnes[]" value="{{ $colonne }}" checked /> {{ $colonne }}
                        </label>
                    @endforeach
                </div>
            </div>
            <div class="sm:col-span-3 flex gap-2">
                <x-btn type="submit">Prévisualiser</x-btn>
            </div>
        </form>
    </div>
@endsection
