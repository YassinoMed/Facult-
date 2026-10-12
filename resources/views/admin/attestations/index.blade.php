@extends('layouts.app')

@section('title', 'Attestations — Scolarité')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Guichet des attestations" description="CIN ou matricule → vérification automatique des conditions → génération → impression."
            :fil="['Documents académiques' => route('admin.documents-center.index'), 'Attestations' => '#']" />

        <form method="GET" action="{{ route('admin.attestations.index') }}" class="mt-4 flex flex-col sm:flex-row gap-2" role="search">
            <x-input id="recherche-etudiant" label="CIN ou matricule" name="q" :value="$recherche" placeholder="Ex. AB123456 ou MAT-00001" required />
            <div class="flex items-end">
                <x-btn type="submit">Rechercher</x-btn>
            </div>
        </form>

        @error('document')
            <div class="mt-4"><x-alert type="error">{{ $message }}</x-alert></div>
        @enderror

        @if($introuvable)
            <div class="mt-4"><x-alert type="warning" titre="Aucun étudiant trouvé.">Vérifiez le CIN ou le matricule saisi.</x-alert></div>
        @endif

        @if($etudiant !== null)
            <div class="mt-4 ds-card p-5">
                <dl class="grid sm:grid-cols-2 gap-2 text-sm">
                    <div><dt class="text-slate-500">Étudiant</dt><dd class="font-bold">{{ $etudiant->prenom }} {{ $etudiant->nom }}</dd></div>
                    <div><dt class="text-slate-500">CIN</dt><dd class="font-mono">{{ $etudiant->cin }}</dd></div>
                    <div><dt class="text-slate-500">Matricule</dt><dd class="font-mono">{{ $etudiant->dossierAcademique?->matricule ?? '—' }}</dd></div>
                    <div><dt class="text-slate-500">Statut du dossier</dt><dd>{{ $etudiant->statut }}</dd></div>
                </dl>

                @if($inscriptions->isNotEmpty())
                    <form method="GET" action="{{ route('admin.attestations.index') }}" class="mt-4 flex flex-col sm:flex-row gap-2">
                        <input type="hidden" name="q" value="{{ $recherche }}">
                        <x-select id="choix-inscription" label="Inscription" name="inscription_id" :options="$inscriptions->mapWithKeys(fn ($i) => [$i->id => ($i->anneeUniversitaire?->libelle ?? '—').' — '.$i->statut])->all()" :valeur="$inscription?->id" />
                        <div class="flex items-end">
                            <x-btn type="submit" variant="secondary">Vérifier</x-btn>
                        </div>
                    </form>
                @else
                    <div class="mt-4"><x-empty-state message="Aucune inscription pour cet étudiant : aucun document ne peut être généré." /></div>
                @endif
            </div>

            @if($inscription !== null)
                <div class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <table class="w-full min-w-[720px] text-left text-xs sm:text-sm">
                        <thead>
                            <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                                <th scope="col" class="px-4 py-3 font-bold">Document</th>
                                <th scope="col" class="px-4 py-3 font-bold">Conditions remplies</th>
                                <th scope="col" class="px-4 py-3 font-bold">Blocages</th>
                                <th scope="col" class="px-4 py-3 font-bold text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($controles as $type => $controle)
                                <tr class="hover:bg-slate-50/60">
                                    <td class="px-4 py-3 font-bold text-slate-900">{{ $catalogue[$type]['libelle'] ?? $type }}</td>
                                    <td class="px-4 py-3">
                                        @if(count($controle['satisfaits']) > 0)
                                            <ul class="space-y-1 text-emerald-700">
                                                @foreach($controle['satisfaits'] as $satisfait)
                                                    <li>✓ {{ $satisfait }}</li>
                                                @endforeach
                                            </ul>
                                        @else
                                            <span class="text-slate-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        @if(count($controle['blocages']) > 0)
                                            <ul class="space-y-1 text-rose-700">
                                                @foreach($controle['blocages'] as $blocage)
                                                    <li>{{ $blocage['message'] }}</li>
                                                @endforeach
                                            </ul>
                                        @else
                                            <span class="text-slate-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        @if($controle['eligible'])
                                            <form method="POST" action="{{ route('admin.attestations.generer') }}" class="inline-flex items-center gap-2">
                                                @csrf
                                                <input type="hidden" name="etudiant_id" value="{{ $etudiant->id }}">
                                                <input type="hidden" name="inscription_id" value="{{ $inscription->id }}">
                                                <input type="hidden" name="type" value="{{ $type }}">
                                                @if($catalogue[$type]['semestre_requis'] ?? false)
                                                    <select name="semestre" required aria-label="Semestre" class="rounded-xl border border-slate-200 bg-white px-2 py-1.5 text-xs">
                                                        <option value="1">S1</option>
                                                        <option value="2">S2</option>
                                                    </select>
                                                @endif
                                                <x-btn type="submit">Générer</x-btn>
                                            </form>
                                        @else
                                            <span class="text-[11px] font-bold text-slate-400">Non générable</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 text-[11px] text-slate-500">Après génération : fiche du document → approbation → émission → téléchargement ou impression.</p>
            @endif
        @endif
    </div>
@endsection
