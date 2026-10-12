@extends('layouts.app')

@section('title', 'Pointer ma présence — Espace Étudiant')

@section('content')
    <div class="mx-auto max-w-xl px-4 sm:px-6 py-8">
        <x-page-header titre="Pointer ma présence" description="Saisissez le code QR affiché par votre enseignant pendant l'appel."
            :fil="['Tableau de bord' => route('etudiant.dashboard'), 'Pointage' => '#']" />

        @if(session('success'))
            <div class="mt-4"><x-alert type="success" :message="session('success')" /></div>
        @endif
        @if($errors->any())
            <div class="mt-4"><x-alert type="error" :message="$errors->first()" /></div>
        @endif

        <form method="POST" action="{{ route('etudiant.appel.pointer') }}" class="mt-4 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6 space-y-4 shadow-sm">
            @csrf
            <div>
                <label for="jeton" class="mb-1 block text-xs font-bold text-slate-700 uppercase tracking-wider">Code du QR *</label>
                <textarea id="jeton" name="jeton" rows="3" required placeholder="Collez ici le code sous le QR…"
                    class="w-full rounded-xl border px-3.5 py-2.5 font-mono text-xs text-slate-900 bg-white border-slate-300 focus:border-primary-700 focus:outline-none">{{ old('jeton') }}</textarea>
            </div>
            <x-btn type="submit">Pointer ma présence</x-btn>
        </form>

        <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
            Le code est nominatif (impossible de pointer pour un autre étudiant), valable 15 minutes,
            et refusé si l'appel est clôturé. Le QR seul ne vaut pas preuve absolue de présence :
            votre enseignant vérifie la feuille d'appel.
        </p>
    </div>
@endsection
