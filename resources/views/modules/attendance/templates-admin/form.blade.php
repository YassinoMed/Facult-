@extends('layouts.app')

@section('title', ($modeEdition ? 'Modifier' : 'Nouveau') . ' modèle de fiche — Scolarité')

@section('content')
    <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 py-8 sm:py-10">
        <div class="mb-6">
            <a href="{{ route('admin.presences.modeles.index') }}" class="text-xs text-slate-500 hover:text-slate-800 font-semibold inline-flex items-center gap-1">
                ← Retour à la liste des modèles
            </a>
            <h1 class="mt-2 text-2xl font-black text-slate-900">
                {{ $modeEdition ? 'Modifier le modèle : ' . $template->name : 'Créer un modèle de fiche personnalisé' }}
            </h1>
            <p class="text-sm text-slate-500 mt-1">
                Configurez l'orientation, les dimensions et les colonnes d'émargement de la fiche de présence.
            </p>
        </div>

        @if($errors->any())
            <div class="mb-6 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
                <p class="font-bold">Des erreurs ont été détectées :</p>
                <ul class="mt-1.5 list-disc list-inside space-y-1 text-xs">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ $modeEdition ? route('admin.presences.modeles.update', $template) : route('admin.presences.modeles.store') }}"
              class="space-y-6 bg-white p-6 sm:p-8 rounded-2xl border border-slate-200 shadow-sm">
            @csrf
            @if($modeEdition)
                @method('PUT')
            @endif

            <!-- Informations générales -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="name" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                        Nom du modèle <span class="text-rose-500">*</span>
                    </label>
                    <input type="text" id="name" name="name" value="{{ old('name', $template->name) }}" required
                           class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500">
                </div>

                <div>
                    <label for="code" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                        Code technique (identifiant unique)
                    </label>
                    <input type="text" id="code" name="code" value="{{ old('code', $template->code) }}"
                           placeholder="Ex: custom_l3_physique"
                           @if($template->is_system) readonly class="w-full rounded-xl border border-slate-200 bg-slate-100 px-3.5 py-2.5 text-sm font-mono text-slate-500 cursor-not-allowed"
                           @else class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm font-mono focus:border-primary-500 focus:ring-1 focus:ring-primary-500" @endif>
                    @if($template->is_system)
                        <p class="text-[11px] text-slate-400 mt-1">Le code d'un modèle système est protégé et immuable.</p>
                    @endif
                </div>

                <div>
                    <label for="type" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                        Type / Famille de fiche <span class="text-rose-500">*</span>
                    </label>
                    <select id="type" name="type" required class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500">
                        @foreach($types as $cle => $libelle)
                            <option value="{{ $cle }}" @selected(old('type', $template->type) === $cle)>{{ $libelle }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label for="orientation" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Orientation <span class="text-rose-500">*</span>
                        </label>
                        <select id="orientation" name="configuration[orientation]" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500">
                            <option value="portrait" @selected(old('configuration.orientation', $template->orientation()) === 'portrait')>Portrait</option>
                            <option value="landscape" @selected(old('configuration.orientation', $template->orientation()) === 'landscape')>Paysage</option>
                        </select>
                    </div>

                    <div>
                        <label for="paper" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Format papier
                        </label>
                        <select id="paper" name="configuration[paper]" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500">
                            <option value="A4" @selected(old('configuration.paper', $template->formatPapier()) === 'A4')>A4 Standard</option>
                            <option value="A3" @selected(old('configuration.paper', $template->formatPapier()) === 'A3')>A3 Grand format</option>
                        </select>
                    </div>
                </div>

                <div class="sm:col-span-2">
                    <label for="description" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                        Description / Consignes d'utilisation
                    </label>
                    <textarea id="description" name="description" rows="2"
                              class="w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500">{{ old('description', $template->description) }}</textarea>
                </div>

                <div class="sm:col-span-2 flex items-center gap-2 pt-1">
                    <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $template->is_active ?? true))
                           class="w-4 h-4 rounded text-primary-600 focus:ring-primary-500 border-slate-300">
                    <label for="is_active" class="text-sm font-semibold text-slate-800">
                        Modèle actif (disponible dans le générateur et les exports)
                    </label>
                </div>
            </div>

            <!-- Colonnes configurables -->
            <div class="pt-6 border-t border-slate-200">
                <div class="flex items-center justify-between mb-3">
                    <div>
                        <h2 class="text-base font-bold text-slate-900">Colonnes du tableau d'émargement</h2>
                        <p class="text-xs text-slate-500">Cochez les colonnes à inclure et adaptez leurs intitulés et largeurs relatives.</p>
                    </div>
                </div>

                @php
                    $colonnesActuelles = $template->configuration['columns'] ?? [];
                    $colonnesParCle = collect($colonnesActuelles)->keyBy('key')->all();
                    $toutesLesCles = array_unique(array_merge(
                        array_keys($colonnesParCle),
                        array_keys($colonnesDisponibles)
                    ));
                    $indexCol = 0;
                @endphp

                <div class="overflow-x-auto rounded-xl border border-slate-200 bg-slate-50/50">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="border-b border-slate-200 text-slate-500 bg-slate-100/70">
                                <th class="px-4 py-2.5 font-bold w-12 text-center">Active</th>
                                <th class="px-4 py-2.5 font-bold w-40">Donnée</th>
                                <th class="px-4 py-2.5 font-bold">Libellé d'en-tête</th>
                                <th class="px-4 py-2.5 font-bold w-28">Largeur (%)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 bg-white">
                            @foreach($toutesLesCles as $cle)
                                @php
                                    $colConfig = $colonnesParCle[$cle] ?? null;
                                    $estVisible = $colConfig ? ($colConfig['visible'] ?? true) : false;
                                    $labelParDefaut = $colConfig['label'] ?? $colonnesDisponibles[$cle] ?? ucfirst($cle);
                                    $largeur = $colConfig['width'] ?? '';
                                    $i = $indexCol++;
                                @endphp
                                <tr class="hover:bg-slate-50/60">
                                    <td class="px-4 py-2 text-center">
                                        <input type="hidden" name="configuration[columns][{{ $i }}][key]" value="{{ $cle }}">
                                        <input type="checkbox" name="configuration[columns][{{ $i }}][visible]" value="1"
                                               @checked(old("configuration.columns.{$i}.visible", $estVisible))
                                               class="w-4 h-4 rounded text-primary-600 focus:ring-primary-500 border-slate-300">
                                    </td>
                                    <td class="px-4 py-2 font-mono text-[11px] text-slate-700">
                                        {{ $colonnesDisponibles[$cle] ?? $cle }}
                                    </td>
                                    <td class="px-4 py-2">
                                        <input type="text" name="configuration[columns][{{ $i }}][label]"
                                               value="{{ old("configuration.columns.{$i}.label", $labelParDefaut) }}"
                                               class="w-full rounded-lg border border-slate-300 px-2.5 py-1 text-xs focus:border-primary-500">
                                    </td>
                                    <td class="px-4 py-2">
                                        <input type="text" name="configuration[columns][{{ $i }}][width]"
                                               value="{{ old("configuration.columns.{$i}.width", $largeur) }}"
                                               placeholder="Ex: 15%"
                                               class="w-full rounded-lg border border-slate-300 px-2.5 py-1 text-xs focus:border-primary-500">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-6 border-t border-slate-200">
                <a href="{{ route('admin.presences.modeles.index') }}" class="px-4 py-2 text-sm font-semibold text-slate-700 hover:text-slate-900">
                    Annuler
                </a>
                <x-btn type="submit" variant="primary">
                    {{ $modeEdition ? 'Enregistrer les modifications' : 'Créer le modèle' }}
                </x-btn>
            </div>
        </form>
    </div>
@endsection
