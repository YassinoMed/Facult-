{{-- Moteur de formulaire générique du référentiel : champs pilotés par EntitesReferentiel. --}}
@foreach($def['champs'] as $champ)
    @php($valeur = old($champ['nom'], $modele?->{$champ['nom']} ?? ($champ['type'] === 'checkbox' ? true : '')))
    @if($champ['type'] === 'textarea')
        <x-textarea :id="$champ['nom']" :name="$champ['nom']" :label="$champ['label']" :rows="3">{{ $valeur }}</x-textarea>
    @elseif($champ['type'] === 'select')
        @php($message = $errors->first($champ['nom']))
        @php($selection = old($champ['nom'], $valeur))
        <div>
            <label for="{{ $champ['nom'] }}" class="mb-1 block text-xs font-bold text-slate-700 uppercase tracking-wider">
                {{ $champ['label'] }}@if($champ['requis'] ?? false) <span class="text-rose-600" aria-hidden="true">*</span>@endif
            </label>
            <select id="{{ $champ['nom'] }}" name="{{ $champ['nom'] }}" @if($champ['requis'] ?? false) required @endif
                    aria-invalid="{{ $message ? 'true' : 'false' }}"
                    class="w-full rounded-xl border px-3.5 py-2.5 text-sm text-slate-900 bg-white transition focus:outline-none min-h-[44px] {{ $message ? 'border-rose-400 bg-rose-50/50' : 'border-slate-300 focus:border-primary-700 focus:ring-4 focus:ring-primary-500/20' }}">
                @if(!($champ['requis'] ?? false))
                    <option value="">— Choisir —</option>
                @endif
                @foreach($options[$champ['options']] ?? [] as $option)
                    <option value="{{ $option['id'] }}" @selected((string) $selection === (string) $option['id'])>{{ $option['label'] }}</option>
                @endforeach
            </select>
            @if($message)
                <p class="mt-1 text-xs font-semibold text-rose-700" role="alert">⚠ {{ $message }}</p>
            @endif
        </div>
    @elseif($champ['type'] === 'checkbox')
        <label class="flex items-center gap-2.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm font-semibold text-slate-700 cursor-pointer min-h-[44px]">
            <input type="checkbox" name="{{ $champ['nom'] }}" value="1" @checked((bool) old($champ['nom'], $modele?->{$champ['nom']} ?? true)) class="h-5 w-5 rounded border-slate-300">
            {{ $champ['label'] }}
        </label>
    @elseif($champ['type'] === 'number' || $champ['type'] === 'decimal')
        <x-input :id="$champ['nom']" :name="$champ['nom']" :label="$champ['label']" type="number" :value="$valeur" @if($champ['requis'] ?? false) required @endif />
    @elseif($champ['type'] === 'date')
        <x-input :id="$champ['nom']" :name="$champ['nom']" :label="$champ['label']" type="date" :value="$valeur instanceof \DateTimeInterface ? $valeur->format('Y-m-d') : $valeur" />
    @else
        <x-input :id="$champ['nom']" :name="$champ['nom']" :label="$champ['label']" :value="$valeur" @if($champ['requis'] ?? false) required @endif />
    @endif
@endforeach
