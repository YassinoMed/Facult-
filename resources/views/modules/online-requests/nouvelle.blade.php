@extends('layouts.app')

@section('title', 'Nouvelle demande — Espace Étudiant')

@section('content')
    <div class="mx-auto max-w-3xl px-4 sm:px-6 py-8 sm:py-12">
        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Espace étudiant</p>
        <h1 class="mt-1 text-2xl sm:text-3xl font-black tracking-tight text-slate-900">Nouvelle demande</h1>

        @if($errors->any())
            <div class="mt-4 rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-xs text-rose-800" role="alert">
                <ul class="list-disc list-inside">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('etudiant.demandes.store') }}"
              x-data="{ typeId: '{{ old('request_type_id') }}', schemas: @js($types->mapWithKeys(fn ($t) => [$t->id => $t->form_schema ?? []])) }"
              class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
            @csrf

            <div>
                <label for="type" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Type de demande <span class="text-rose-600">*</span></label>
                <select id="type" name="request_type_id" required x-model="typeId"
                        class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm focus:border-primary-700 focus:outline-none">
                    <option value="">— Choisir —</option>
                    @foreach($types as $type)
                        <option value="{{ $type->id }}">{{ $type->name }} ({{ $type->processing_days }} j)</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="objet" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Objet <span class="text-rose-600">*</span></label>
                <input type="text" id="objet" name="objet" value="{{ old('objet') }}" required maxlength="150"
                       class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm focus:border-primary-700 focus:outline-none">
            </div>

            <div>
                <label for="description" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Description</label>
                <textarea id="description" name="description" rows="3" maxlength="2000"
                          class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm focus:border-primary-700 focus:outline-none">{{ old('description') }}</textarea>
            </div>

            {{-- Champs dynamiques du type (§17) --}}
            <template x-if="typeId && schemas[typeId] && schemas[typeId].length > 0">
                <fieldset class="rounded-xl border border-slate-200 bg-slate-50/60 p-4 space-y-3">
                    <legend class="px-2 text-xs font-bold uppercase tracking-wider text-slate-500">Informations complémentaires</legend>
                    <template x-for="champ in schemas[typeId]" :key="champ.name">
                        <div>
                            <label :for="'dyn-' + champ.name" class="mb-1 block text-xs font-bold text-slate-700">
                                <span x-text="champ.label"></span>
                                <span x-show="champ.required" class="text-rose-600">*</span>
                            </label>
                            <template x-if="champ.type === 'textarea'">
                                <textarea :id="'dyn-' + champ.name" :name="'form_data[' + champ.name + ']'" rows="2"
                                          :required="champ.required" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></textarea>
                            </template>
                            <template x-if="champ.type === 'select'">
                                <select :id="'dyn-' + champ.name" :name="'form_data[' + champ.name + ']'"
                                        :required="champ.required" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                    <option value="">— Choisir —</option>
                                    <template x-for="option in (champ.options || [])" :key="option">
                                        <option :value="option" x-text="option"></option>
                                    </template>
                                </select>
                            </template>
                            <template x-if="champ.type !== 'textarea' && champ.type !== 'select'">
                                <input type="text" :id="'dyn-' + champ.name" :name="'form_data[' + champ.name + ']'"
                                       :required="champ.required" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            </template>
                        </div>
                    </template>
                </fieldset>
            </template>

            <label for="confirmation" class="flex items-start gap-3 cursor-pointer text-xs text-slate-700">
                <input type="checkbox" id="confirmation" name="confirmation" value="1" required class="mt-0.5 h-5 w-5 rounded border-slate-300 text-primary-800">
                <span>Je confirme que les informations fournies sont exactes. <span class="text-rose-600 font-bold">*</span></span>
            </label>

            <div class="flex flex-col sm:flex-row gap-3">
                <a href="{{ route('etudiant.demandes.index') }}"
                   class="inline-flex items-center justify-center rounded-xl border border-slate-300 px-5 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 min-h-[48px]">Annuler</a>
                <button type="submit"
                        class="inline-flex items-center justify-center rounded-xl bg-primary-800 px-6 py-3 text-sm font-bold text-white hover:bg-primary-700 min-h-[48px]">
                    Enregistrer brouillon
                </button>
            </div>
        </form>
    </div>
@endsection
