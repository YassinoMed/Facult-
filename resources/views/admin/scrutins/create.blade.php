@extends('admin.layouts.app')

@section('content')
<div class="py-8">
    <div class="max-w-3xl mx-auto">
        <!-- En-tête -->
        <div class="card card-smrounded mb-6">
            <div class="p-6">
                <h2 class="text-2xl font-bold text-gray-900" data-motion-block>Créer un nouveau scrutin</h2>
                <p class="text-sm text-gray-500">Configurez les paramètres du scrutin secret.</p>
            </div>
        </div>

        <form wire:submit="store" class="space-y-6">
            @csrf

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Titre du scrutin</label>
                <input type="text" wire:model="titre" name="titre" required
                    class="mt-1 block w-full rounded border py-2 px-3 focus:outline-none focus:ring-2 focus:ring-primary"
                    placeholder="Ex: Vote sur la vie étudiante 2024-2025">
                <p class="text-xs text-gray-500 mt-1">Le titre s'affichera aux électeurs lors du vote.</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                <textarea wire:model="description" name="description" rows="3"
                    class="mt-1 block w-full rounded border py-2 px-3 focus:outline-none focus:ring-2 focus:ring-primary"
                    placeholder="Description détaillée du scrutin..."></textarea>
                <p class="text-xs text-gray-500 mt-1">Optionnel : description détaillée du scrutin.</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">État initial</label>
                <div class="grid grid-cols-2 gap-2">
                    <label class="group relative cursor-pointer select-none rounded border p-2 px-3 {{ request('etat') === 'brouillon' ? 'bg-blue-100' : '' }} {{ request('etat') === 'brouillon' || !request('etat') ? 'border-blue-500' : 'border-transparent' }}">
                        <input type="radio" name="etat" value="brouillon" wire:model="etat" required
                            class="absolute opacity-0 group-input focus:outline-none">
                        <div class="flex items-center gap-2">
                            <svg class="h-5 w-5 text-gray-600 group-hover:text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2m1.66-6.33a2 2 0 01-.589.29l-2.5 1.5a2 2 0 01-.58-.84l.83-2.91a2 2 0 011.29-.5l2.5-1.5a2 2 0 01.59.29H15a2 2 0 012 2v4.58l.15.08a1 1 0 01-.8 1.51l-1.46 2.58c-.32.59-.86.59-1.18 0l-1.18-2.58.15-.08a1 1 0 01-.8-1.51L15 5.08V4.2l-.64-1.46c-.2-.57-.44-.86-.72-1.05l-.13-.06a2 2 0 01-.78-.02H7a2 2 0 01-2-2 2 2 0 01-2-2H2a2 2 0 01-2-2v3.06l.53 1.87a2 2 0 01-.441.88l-.32 1.04a2 2 0 01-.76 0l-.32-1.04a2 2 0 01-.438-.87l.16-2.35c0-.45.16-.71.44-.88l.34-1.3.38.07a1 1 0 011. 18.07l-.31 1.06c-.26.52-.31.87-.28 1.09.04.23.1.48.1 .72zM7 10l 4.5 1.19L 17 15l -4.5 1.18L 7 10z"/>
                            </svg>
                            Brouillon
                        </div>
                    </label>
                    <label class="group relative cursor-pointer select-none rounded border p-2 px-3 {{ request('etat') === 'programme' ? 'bg-blue-100' : '' }} {{ request('etat') === 'programme' ? 'border-blue-500' : 'border-transparent' }}">
                        <input type="radio" name="etat" value="programme" wire:model="etat"
                            class="absolute opacity-0 group-input focus:outline-none">
                        <div class="flex items-center gap-2">
                            <svg class="h-5 w-5 text-gray-600 group-hover:text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h1m5 12v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5m14-2v-5a2 2 0 01-2-2h-1m-2 0h-1M21 12a2 2 0 11-4 0 2 2 0 014 0zM5 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                            </svg>
                            Programme
                        </div>
                    </label>
                    <label class="group relative cursor-pointer select-none rounded border p-2 px-3 {{ request('etat') === 'ouvert' ? 'bg-green-100' : '' }} {{ request('etat') === 'ouvert' ? 'border-green-500' : 'border-transparent' }}">
                        <input type="radio" name="etat" value="ouvert" wire:model="etat"
                            class="absolute opacity-0 group-input focus:outline-none">
                        <div class="flex items-center gap-2">
                            <svg class="h-5 w-5 text-gray-600 group-hover:text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-.18m0 0A7.001 7.001 0 0012 7a7.001 7.001 0 00-7 6.999m0-3.18V5a2 2 0 10-4 0 2 2 0 004 0v4.82m-6.938 4h13.876c1.18 0 2.091.402 2.598 1M21 21l-6-6m0 0l-6 6m6-6l6 6"/>
                            </svg>
                            Ouvert
                        </div>
                    </label>
                    <label class="group relative cursor-pointer select-none rounded border p-2 px-3 {{ request('etat') === 'clos' ? 'bg-red-100' : '' }} {{ request('etat') === 'clos' ? 'border-red-500' : 'border-transparent' }}">
                        <input type="radio" name="etat" value="clos" wire:model="etat"
                            class="absolute opacity-0 group-input focus:outline-none">
                        <div class="flex items-center gap-2">
                            <svg class="h-5 w-5 text-gray-600 group-hover:text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                            Fermé
                        </div>
                    </label>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Type de scrutin</label>
                <div class="grid grid-cols-2 gap-2">
                    <label class="group relative cursor-pointer select-none rounded border p-2 px-3 {{ $scrutin->type_mode === 0 ? 'bg-primary-100' : '' }} {{ request('type_mode') === '0' || !request('type_mode') ? 'border-primary-500' : 'border-transparent' }}">
                        <input type="radio" name="type_mode" value="0" wire:model="type_mode" required
                            class="absolute opacity-0 group-input focus:outline-none">
                        <div class="flex items-center gap-2">
                            <svg class="h-5 w-5 text-gray-600 group-hover:text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016L18 9.6l-3.182 5.385-1.639-1.638M13.5 5.5L8.1 12.9m0 0l 4.5-4.5m-4.5 4.5H15"/>
                            </svg>
                            Choix unique
                        </div>
                    </label>
                    <label class="group relative cursor-pointer select-none rounded border p-2 px-3 {{ $scrutin->type_mode === 1 ? 'bg-primary-100' : '' }} {{ request('type_mode') === '1' ? 'border-primary-500' : 'border-transparent' }}">
                        <input type="radio" name="type_mode" value="1" wire:model="type_mode"
                            class="absolute opacity-0 group-input focus:outline-none">
                        <div class="flex items-center gap-2">
                            <svg class="h-5 w-5 text-gray-600 group-hover:text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4v2m6-6v2m2-4v2m6 4v2m-6-4l-6 6M12 15V3"/>
                            </svg>
                            Choix multiple
                        </div>
                    </label>
                    <label class="group relative cursor-pointer select-none rounded border p-2 px-3 {{ $scrutin->type_mode === 2 ? 'bg-primary-100' : '' }} {{ request('type_mode') === '2' ? 'border-primary-500' : 'border-transparent' }}">
                        <input type="radio" name="type_mode" value="2" wire:model="type_mode"
                            class="absolute opacity-0 group-input focus:outline-none">
                        <div class="flex items-center gap-2">
                            <svg class="h-5 w-5 text-gray-600 group-hover:text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-4v13M13.5 3.5l4.5 4.5M3.5 13.5l4.5 4.5M3 10h12"/>
                            </svg>
                            Oui / Non
                        </div>
                    </label>
                    <label class="group relative cursor-pointer select-none rounded border p-2 px-3 {{ $scrutin->type_mode === 3 ? 'bg-primary-100' : '' }} {{ request('type_mode') === '3' ? 'border-primary-500' : 'border-transparent' }}">
                        <input type="radio" name="type_mode" value="3" wire:model="type_mode"
                            class="absolute opacity-0 group-input focus:outline-none">
                        <div class="flex items-center gap-2">
                            <svg class="h-5 w-5 text-gray-600 group-hover:text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2 9-2V5a1 1 0 00-2 0v14m0 0l-9 2-9-2v5a1 1 0 01-2 0v-14m0 0l9-2 9 2v-5a1 1 0 012 0v14Z"/>
                            </svg>
                            Texte libre
                        </div>
                    </label>
                    <label class="group relative cursor-pointer select-none rounded border p-2 px-3 {{ $scrutin->type_mode === 4 ? 'bg-primary-100' : '' }} {{ request('type_mode') === '4' ? 'border-primary-500' : 'border-transparent' }}">
                        <input type="radio" name="type_mode" value="4" wire:model="type_mode"
                            class="absolute opacity-0 group-input focus:outline-none">
                        <div class="flex items-center gap-2">
                            <svg class="h-5 w-5 text-gray-600 group-hover:text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 13l5 5m0 0l-5-5m5 5H10"/>
                            </svg>
                            Classement
                        </div>
                    </label>
                </div>
            </div>

            <div v-if="type_mode === 1">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Nombre minimum de réponses</label>
                    <input type="number" wire:model="nbOptionsMin" name="nbOptionsMin" min="0"
                        class="mt-1 block w-full rounded border py-2 px-3 focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Nombre maximum de réponses</label>
                    <input type="number" wire:model="nbOptionsMax" name="nbOptionsMax" min="1"
                        class="mt-1 block w-full rounded border py-2 px-3 focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Dates du scrutin</label>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Date de début</label>
                        <input type="date" wire:model="date_debut" name="date_debut"
                            class="mt-1 block w-full rounded border py-2 px-3 focus:outline-none focus:ring-2 focus:ring-primary">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Date de fin</label>
                        <input type="date" wire:model="date_fin" name="date_fin"
                            class="mt-1 block w-full rounded border py-2 px-3 focus:outline-none focus:ring-2 focus:ring-primary">
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Afficher les résultats après clôture</label>
                <div class="flex items-center gap-2">
                    <input type="checkbox" wire:model="resultats_autorises" name="resultats_autorises"
                        class="rounded border-accent cursor-pointer">
                    <span class="text-sm text-gray-600">Autoriser la consultation des résultats après la clôture du scrutin</span>
                </div>
            </div>

            <div class="flex gap-3">
                <button type="button" wire:click="passerEnProgramme" class="btn-secondary flex-1">
                    Enregistrer en mode programme
                </button>
                <button type="submit" wire:click="store" class="btn-primary flex-1">
                    {{ $scrutin->id ? 'Mettre à jour' : 'Créer le scrutin' }}
                </button>
            </div>
        </form>
    </div>
</div>