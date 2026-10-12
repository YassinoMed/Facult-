@extends('layouts.app')

@section('title', 'Sauvegardes database')

@section('content')
<div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
    {{-- Entête de page --}}
    <x-page-header titre="Sauvegardes database"
                   description="Gestion sécurisée et intégrité des sauvegardes MySQL hébergées hors du projet."
                   :fil="['Système' => route('admin.systeme.index'), 'Sauvegardes' => '#']">
        <x-slot:actions>
            @can('system.backups.settings')
                <a href="{{ route('admin.system.backups.planification') }}" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">Planning</a>
            @endcan
            @can('system.backups.create')
                <form method="POST" action="{{ route('admin.system.backups.store') }}" class="inline" onsubmit="return confirm('Lancer une nouvelle sauvegarde de la base de données maintenant ?')">
                    @csrf
                    <x-btn type="submit">
                        <svg class="mr-1.5 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Créer une sauvegarde
                    </x-btn>
                </form>
            @endcan
        </x-slot:actions>
    </x-page-header>

    {{-- Alertes de feedback --}}
    @if(session('success'))
        <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50/80 p-4 text-xs font-semibold text-emerald-800">
            ✓ {{ session('success') }}
        </div>
    @endif

    @if($errors->has('backup'))
        <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50/80 p-4 text-xs font-semibold text-rose-800">
            ⚠️ {{ $errors->first('backup') }}
        </div>
    @endif

    {{-- Cartes de synthèse --}}
    <div class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
        {{-- 1. Dernier backup --}}
        <div class="ds-card p-4">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Dernier backup</p>
            <p class="mt-1 text-base font-black tabular-nums text-slate-900">
                {{ $dernier?->completed_at?->format('d/m/Y H:i:s') ?? '—' }}
            </p>
            <p class="text-[11px] font-semibold {{ $dernier?->status === 'SUCCESS' ? 'text-emerald-600' : 'text-slate-400' }}">
                {{ $dernier ? ($dernier->status === 'SUCCESS' ? 'Succès ('.$dernier->completed_at?->diffForHumans().')' : $dernier->status) : 'Aucun backup' }}
            </p>
        </div>

        {{-- 2. Checksum du dernier backup --}}
        <div class="ds-card p-4">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Dernier Checksum</p>
            <p class="mt-1 text-xs font-mono font-bold text-slate-800 truncate" title="{{ $dernier?->checksum ?? 'Non calculé' }}">
                {{ $dernier?->checksum ? substr($dernier->checksum, 0, 16).'…' : '—' }}
            </p>
            <p class="text-[11px] text-slate-400">
                {{ $dernier?->verified_at ? 'Vérifié le '.$dernier->verified_at->format('d/m H:i') : 'SHA-256 standard' }}
            </p>
        </div>

        {{-- 3. Historique / Conservées --}}
        <div class="ds-card p-4">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Historique total</p>
            <p class="mt-1 text-lg font-black tabular-nums text-slate-900">{{ $statistiques['total'] }}</p>
            <p class="text-[11px] text-slate-500">
                <span class="text-emerald-600 font-bold">{{ $statistiques['succes'] }} succès</span> ·
                <span class="{{ $statistiques['echecs'] > 0 ? 'text-rose-600 font-bold' : 'text-slate-400' }}">{{ $statistiques['echecs'] }} échec(s)</span>
            </p>
        </div>

        {{-- 4. Taille & Répertoire --}}
        <div class="ds-card p-4">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Répertoire externe</p>
            <p class="mt-1 text-lg font-black tabular-nums text-slate-900">
                {{ number_format($statistiques['octets'] / 1048576, 1, ',', ' ') }} Mo
            </p>
            <p class="text-[11px] text-slate-400 font-mono truncate" title="{{ $dossier }}">
                {{ $dossier }}
            </p>
        </div>
    </div>

    {{-- Tableau d'historique --}}
    <div class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 bg-slate-50/50 px-6 py-4">
            <h2 class="text-sm font-black text-slate-900">Historique des sauvegardes de la base</h2>
            <p class="text-xs text-slate-500">
                Fichiers enregistrés au format <span class="font-mono text-slate-700">db_YYYY-MM-DD_HH-mm-ss.sql</span> avec empreinte de hachage SHA-256.
            </p>
        </div>

        @if($sauvegardes->isNotEmpty())
            <div class="overflow-x-auto">
                <table class="w-full min-w-[850px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400 bg-slate-50/30">
                            <th scope="col" class="px-5 py-3 font-bold">Date & Heure</th>
                            <th scope="col" class="px-4 py-3 font-bold">Fichier</th>
                            <th scope="col" class="px-4 py-3 font-bold">Base</th>
                            <th scope="col" class="px-4 py-3 font-bold">Taille</th>
                            <th scope="col" class="px-4 py-3 font-bold">Checksum SHA-256</th>
                            <th scope="col" class="px-4 py-3 font-bold">Statut</th>
                            <th scope="col" class="px-5 py-3 font-bold text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($sauvegardes as $sauvegarde)
                            <tr class="hover:bg-slate-50/60 transition">
                                <td class="px-5 py-3.5 tabular-nums text-slate-700 whitespace-nowrap">
                                    {{ $sauvegarde->completed_at?->format('d/m/Y H:i:s') ?? $sauvegarde->created_at->format('d/m/Y H:i:s') }}
                                </td>
                                <td class="px-4 py-3.5 font-mono text-xs font-semibold text-slate-900">
                                    {{ $sauvegarde->filename }}
                                </td>
                                <td class="px-4 py-3.5 font-mono text-xs text-slate-600">
                                    {{ $sauvegarde->database_name ?: config('database.connections.'.config('database.default').'.database') }}
                                </td>
                                <td class="px-4 py-3.5 tabular-nums text-slate-700 whitespace-nowrap">
                                    @if($sauvegarde->size_bytes > 1048576)
                                        {{ number_format($sauvegarde->size_bytes / 1048576, 2, ',', ' ') }} Mo
                                    @elseif($sauvegarde->size_bytes > 0)
                                        {{ number_format($sauvegarde->size_bytes / 1024, 1, ',', ' ') }} Ko
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-3.5 font-mono text-xs text-slate-600" title="{{ $sauvegarde->checksum }}">
                                    @if($sauvegarde->checksum)
                                        <span class="inline-flex items-center gap-1">
                                            <span class="bg-slate-100 rounded px-1.5 py-0.5 text-slate-800">{{ substr($sauvegarde->checksum, 0, 12) }}…</span>
                                        </span>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3.5">
                                    <x-status-badge :statut="$sauvegarde->status" />
                                </td>
                                <td class="px-5 py-3.5 text-right whitespace-nowrap">
                                    @can('system.backups.verify')
                                        @if($sauvegarde->status === 'SUCCESS' || $sauvegarde->status === 'VERIFICATION_FAILED')
                                            <form method="POST" action="{{ route('admin.system.backups.verify', $sauvegarde) }}" class="inline">
                                                @csrf
                                                <button type="submit"
                                                        title="Recalculer le hash SHA-256 du fichier pour vérifier son intégrité"
                                                        class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-bold text-primary-800 shadow-xs hover:bg-slate-50 transition">
                                                    <svg class="h-3.5 w-3.5 text-primary-700" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z" />
                                                    </svg>
                                                    Vérifier
                                                </button>
                                            </form>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="px-5 py-3 border-t border-slate-100 bg-slate-50/30">
                {{ $sauvegardes->links() }}
            </div>
        @else
            <div class="p-8">
                <x-empty-state message="Aucune sauvegarde enregistrée pour le moment. Cliquez sur « Créer une sauvegarde » pour générer le premier backup." />
            </div>
        @endif
    </div>

    {{-- Note de sécurité --}}
    <div class="mt-6 rounded-2xl border border-slate-200 bg-slate-50/70 p-4 text-xs text-slate-600">
        <p class="font-bold text-slate-800">🛡️ Politique de sécurité des sauvegardes (LOOP BACKUP-01)</p>
        <p class="mt-1">
            Les sauvegardes sont stockées en dehors du projet web dans <span class="font-mono text-slate-800">{{ $dossier }}</span>.
            Chaque fichier généré fait l'objet d'un calcul d'empreinte cryptographique SHA-256 stocké dans le registre. Le bouton « Vérifier » permet de contrôler à tout moment l'absence d'altération physique du fichier.
        </p>
    </div>
</div>
@endsection
