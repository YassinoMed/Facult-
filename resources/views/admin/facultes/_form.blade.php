{{-- Shared faculty form partial --}}
<div class="space-y-5">
    @if($errors->any())
        <div class="rounded-xl border border-red-200 bg-red-50 p-4">
            <div class="flex items-start gap-3">
                <svg class="h-5 w-5 flex-shrink-0 text-red-500 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                </svg>
                <div>
                    <h3 class="text-sm font-semibold text-red-800">Erreur de validation</h3>
                    <ul class="mt-1 list-disc list-inside space-y-1">
                        @foreach($errors->all() as $error)
                            <li class="text-sm text-red-700">{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    @endif

    <div>
        <label for="nom" class="mb-1.5 block text-sm font-semibold text-gray-700">Nom de la faculté <span class="text-red-500">*</span></label>
        <input type="text" id="nom" name="nom" value="{{ old('nom', $faculte->nom ?? '') }}" required
               class="w-full rounded-xl border border-gray-200 bg-gray-50/50 px-4 py-3 text-sm text-gray-900 placeholder-gray-400 transition-smooth focus:border-primary-500 focus:bg-white focus:ring-2 focus:ring-primary-500/20 focus:outline-none @error('nom') border-red-300 @enderror"
               placeholder="Ex : Faculté des Sciences et Techniques">
    </div>

    <div>
        <label for="code" class="mb-1.5 block text-sm font-semibold text-gray-700">Code unique <span class="text-red-500">*</span></label>
        <input type="text" id="code" name="code" value="{{ old('code', $faculte->code ?? '') }}" required
               class="w-full rounded-xl border border-gray-200 bg-gray-50/50 px-4 py-3 text-sm text-gray-900 placeholder-gray-400 uppercase transition-smooth focus:border-primary-500 focus:bg-white focus:ring-2 focus:ring-primary-500/20 focus:outline-none @error('code') border-red-300 @enderror"
               placeholder="Ex : FST">
        <p class="mt-1 text-xs text-gray-500">Code court unique pour identifier la faculté</p>
    </div>

    <div>
        <label for="description" class="mb-1.5 block text-sm font-semibold text-gray-700">Description</label>
        <textarea id="description" name="description" rows="3"
                  class="w-full rounded-xl border border-gray-200 bg-gray-50/50 px-4 py-3 text-sm text-gray-900 placeholder-gray-400 transition-smooth focus:border-primary-500 focus:bg-white focus:ring-2 focus:ring-primary-500/20 focus:outline-none resize-none @error('description') border-red-300 @enderror"
                  placeholder="Description de la faculté (optionnel)">{{ old('description', $faculte->description ?? '') }}</textarea>
    </div>

    <div class="flex items-center gap-3">
        <label for="active" class="relative inline-flex cursor-pointer items-center" x-data="{ enabled: {{ old('active', isset($faculte) ? ($faculte->active ? 'true' : 'false') : 'true') }} }">
            <input type="hidden" name="active" value="0">
            <input type="checkbox" id="active" name="active" value="1" class="sr-only peer" x-model="enabled"
                   {{ old('active', isset($faculte) ? $faculte->active : true) ? 'checked' : '' }}>
            <div class="h-6 w-11 rounded-full bg-gray-300 after:absolute after:top-0.5 after:left-0.5 after:h-5 after:w-5 after:rounded-full after:bg-white after:shadow-sm after:transition-all peer-checked:bg-primary-600 peer-checked:after:translate-x-full"></div>
        </label>
        <span class="text-sm font-medium text-gray-700">Faculté active</span>
    </div>
</div>
