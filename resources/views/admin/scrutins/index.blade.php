@extends('admin.layouts.app')

@section('content')
<div class="py-8">
    <div class="max-w-7xl mx-auto">
        <!-- En-tête -->
        <div class="flex flex-col sm:flex-row items-center justify-between gap-4 mb-6">
            <div>
                <h1 class="text-3xl font-bold text-gray-900" data-motion-block>Gestion des scrutins</h1>
                <p class="text-sm text-gray-500">Créez et administrez les scrutins secrets pour la communauté universitaire.</p>
            </div>

            <button onclick="window.location.href='{{ route('admin.scrutins.create') }}'" class="btn-primary">
                <span class="flex items-center gap-2">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Créer un scrutin
                </span>
            </button>
        </div>

        <!-- Filtres et état -->
        <div class="card card-smrounded mb-6">
            <div class="p-4">
                <form method="GET" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">État</label>
                        <select name="etat" onchange="this.form.submit()" class="select w-full rounded border py-2 px-3 focus:outline-none focus:ring-2 focus:ring-primary">
                            <option value="">Tous les états</option>
                            <option value="brouillon" {{ request('etat') === 'brouillon' ? 'selected' : '' }}>Brouillon</option>
                            <option value="programme" {{ request('etat') === 'programme' ? 'selected' : '' }}>Programmé</option>
                            <option value="ouvert" {{ request('etat') === 'ouvert' ? 'selected' : '' }}>Ouvert</option>
                            <option value="clos" {{ request('etat') === 'clos' ? 'selected' : '' }}>Fermé</option>
                            <option value="archive" {{ request('etat') === 'archive' ? 'selected' : '' }}>Archivé</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Recherche</label>
                        <input type="text" name="recherche" value="{{ request('recherche', '') }}" class="input w-full rounded border py-2 px-3 focus:outline-none focus:ring-2 focus:ring-primary" placeholder="Titre ou description">
                    </div>
                </form>
            </div>
        </div>

        <!-- Tableau des scrutins -->
        <div class="overflow-x-auto rounded-lg shadow-sm">
            <table class="min-w-full">
                <thead>
                    <tr class="bg-gray-50 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">
                        <th scope="col" class="py-3 px-6">Titre</th>
                        <th scope="col" class="py-3 px-6">État</th>
                        <th scope="col" class="py-3 px-6">Période</th>
                        <th scope="col" class="py-3 px-6">Électeurs</th>
                        <th scope="col" class="py-3 px-6">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($scrutins as $scrutin)
                        <tr class="border-b transition-colors hover:bg-gray-50">
                            <td class="py-4 px-6 font-medium text-gray-900">
                                <a href="{{ route('admin.scrutins.edit', $scrutin->id) }}">{{ $scrutin->titre }}</a>
                            </td>
                            <td class="py-4 px-6">
                                <span class="px-2 inline-flex text-xs font-semibold rounded {{ match($scrutin->etat, 'brouillon', 'bg-gray-100 text-gray-800', 'programme', 'bg-blue-100 text-blue-800', 'ouvert', 'bg-green-100 text-green-800', 'clos', 'bg-red-100 text-red-800', 'archive', 'bg-gray-100 text-gray-800', 'bg-gray-100 text-gray-800') }}">
                                    {{ match($scrutin->etat, 'brouillon' => 'Brouillon', 'programme' => 'Programmé', 'ouvert' => 'Ouvert', 'clos' => 'Fermé', 'archive' => 'Archivé', $scrutin->etat) }}
                                </span>
                            </td>
                            <td class="py-4 px-6 text-sm text-gray-500">
                                {{ $scrutin->date_debut->format('d/m/Y') }} - {{ $scrutin->date_fin->format('d/m/Y') }}
                            </td>
                            <td class="py-4 px-6 text-sm text-gray-500">
                                {{ $scrutin->participations_count ?: '0' }} participants
                            </td>
                            <td class="py-4 px-6">
                                <div class="flex gap-2">
                                    <button onclick="window.location.href='{{ route('admin.scrutins.show', $scrutin->id) }}'" class="text-primary hover:underline">
                                        Voir
                                    </button>
                                    @if ($scrutin->etat !== 'clos')
                                        <button wire:click="openEdit({{ $scrutin->id }})" class="text-blue-600 hover:underline">
                                            Modifier
                                        </button>
                                    @endif
                                    @if ($scrutin->etat !== 'brouillon' && $scrutin->etat !== 'archive')
                                        <button onclick="if(confirm('Êtes-vous sûr de vouloir clôturer ce scrutin ?')) window.location='{{ route('admin.scrutins.cloturer', $scrutin->id) }}'" class="text-red-600 hover:underline">
                                            Clôturer
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <!-- Message si aucun scrutin -->
        @if ($scrutins->isEmpty())
            <div class="py-12 text-center text-gray-500">
                <p>Aucun scrutin trouvé.</p>
            </div>
        @endif
    </div>
</div>
@endsection