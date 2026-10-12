@extends('layouts.app')

@section('title', 'Prévisualisation import — Scolarité')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8" x-data="{ remplacement: false }">
        <x-page-header :titre="'Prévisualisation : ' . $definition->libelle()" description="Rien n'est enregistré à ce stade."
            :fil="['Données' => route('admin.donnees.hub'), 'Prévisualisation' => '#']" />

        <div class="mt-4">
            <x-stepper :etapes="[['Fichier', true], ['Validation', true], ['Prévisualisation', false], ['Confirmation', false], ['Terminé', false]]" :courant="3" />
        </div>

        <div class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div class="rounded-2xl border border-emerald-300 bg-emerald-50 p-4">
                <p class="text-2xl font-black tabular-nums text-emerald-900">{{ count($resultat->valides) }}</p>
                <p class="text-[11px] font-bold uppercase text-emerald-700">✓ Lignes valides</p>
            </div>
            <div class="rounded-2xl border border-amber-300 bg-amber-50 p-4">
                <p class="text-2xl font-black tabular-nums text-amber-900">{{ count($resultat->avertissements) }}</p>
                <p class="text-[11px] font-bold uppercase text-amber-700">⚠ Avertissements</p>
            </div>
            <div class="rounded-2xl border border-rose-300 bg-rose-50 p-4">
                <p class="text-2xl font-black tabular-nums text-rose-900">{{ count($resultat->erreurs) }}</p>
                <p class="text-[11px] font-bold uppercase text-rose-700">❌ Erreurs</p>
            </div>
        </div>

        @if(count($resultat->avertissements) > 0)
            <div class="mt-4"><x-alert type="warning">
                <ul class="list-disc list-inside space-y-1">
                    @foreach(array_slice($resultat->avertissements, 0, 10) as $avertissement)
                        <li>{{ $avertissement }}</li>
                    @endforeach
                </ul>
            </x-alert></div>
        @endif

        @if(count($resultat->erreurs) > 0)
            <div class="mt-4"><x-alert type="error" titre="Ces lignes seront ignorées (les lignes valides peuvent être importées).">
                <ul class="space-y-1.5 max-h-64 overflow-y-auto mt-1">
                    @foreach(array_slice($resultat->erreurs, 0, 50) as $erreur)
                        <li><strong>Ligne {{ $erreur['ligne'] }}</strong> [{{ $erreur['champ'] }}] <code class="rounded bg-rose-100 px-1 font-mono">{{ $erreur['code'] ?? 'INVALID' }}</code> — {{ $erreur['message'] }}</li>
                    @endforeach
                </ul>
            </x-alert></div>
        @endif

        @if(! empty($diagnostic['mapping'] ?? []))
            <section aria-label="Correspondance des colonnes" class="mt-4 rounded-2xl border border-slate-200 bg-white shadow-sm p-4">
                <h2 class="text-sm font-black text-slate-900">Correspondance des colonnes</h2>
                <ul class="mt-2 space-y-1 text-xs text-slate-700">
                    @foreach($diagnostic['mapping'] as $m)
                        <li>
                            <span class="font-mono font-bold">{{ $m['brut'] }}</span>
                            @if(($m['statut'] ?? '') === 'inconnue')
                                <span class="rounded bg-slate-100 px-1.5 py-0.5 font-bold text-slate-500">ignorée</span>
                                @if(! empty($m['suggestion']))<span class="text-slate-500">— vouliez-vous dire : <span class="font-mono">{{ $m['suggestion'] }}</span> ?</span>@endif
                            @else
                                <span aria-hidden="true">→</span> <span class="font-mono">{{ $m['champ'] }}</span>
                                <span class="text-slate-400">({{ $m['statut'] }})</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if(count($resultat->corrections) > 0)
            <section aria-label="Corrections automatiques" class="mt-4 rounded-2xl border border-emerald-200 bg-emerald-50/50 shadow-sm p-4">
                <h2 class="text-sm font-black text-emerald-900">Corrections automatiques ({{ count($resultat->corrections) }})</h2>
                <p class="mt-1 text-[11px] text-emerald-800">Normalisations déterministes appliquées avant validation — champs sensibles jamais touchés.</p>
                <ul class="mt-2 max-h-48 space-y-1 overflow-y-auto text-xs text-slate-700">
                    @foreach(array_slice($resultat->corrections, 0, 50) as $correction)
                        <li><strong>Ligne {{ $correction['ligne'] }}</strong> [{{ $correction['champ'] }}] <span class="font-mono line-through text-slate-400">{{ $correction['originale'] }}</span> <span aria-hidden="true">→</span> <span class="font-mono font-bold">{{ $correction['proposee'] }}</span> <code class="rounded bg-emerald-100 px-1 font-mono">{{ $correction['regle'] }}</code></li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if(count($resultat->aRevoir) > 0)
            <section aria-label="Points à vérifier" class="mt-4 rounded-2xl border border-amber-300 bg-amber-50/60 shadow-sm p-4" x-data="revueImport(@js(route('admin.donnees.import.revoir', $entite)), @js($token), @js(collect($resultat->aRevoir)->map(fn ($r) => $r + ['saisie' => (string) ($r['valeur'] ?? ''), 'traite' => false])->all()))">
                <h2 class="text-sm font-black text-amber-900">Points à vérifier avant confirmation ({{ count($resultat->aRevoir) }})</h2>
                <p class="mt-1 text-[11px] text-amber-800">Chaque décision est validée côté serveur. Sans décision, ces lignes restent importées telles quelles — ou décidez puis confirmez (la confirmation exige une revue complète dès qu'elle est entamée).</p>
                <p x-show="message" x-text="message" role="alert" class="mt-2 rounded-xl bg-rose-100 px-3 py-2 text-xs font-bold text-rose-800"></p>
                <ul class="mt-2 space-y-2">
                    <template x-for="point in points" :key="point.ligne + ':' + point.champ">
                        <li class="rounded-xl border border-amber-200 bg-white p-3 text-xs text-slate-700">
                            <p><strong>Ligne <span x-text="point.ligne"></span></strong> [<span x-text="point.champ"></span>] — <span class="font-mono" x-text="point.valeur"></span></p>
                            <p class="mt-0.5 text-[11px] text-slate-500" x-text="point.raison"></p>
                            <div x-show="! point.traite" class="mt-2 flex flex-wrap items-center gap-2">
                                <button type="button" @click="decider(point, 'accepter')" :disabled="envoi" class="rounded-lg bg-emerald-600 px-2.5 py-1.5 font-bold text-white disabled:opacity-50">Accepter</button>
                                <button type="button" @click="decider(point, 'rejeter')" :disabled="envoi" class="rounded-lg bg-rose-600 px-2.5 py-1.5 font-bold text-white disabled:opacity-50">Rejeter</button>
                                <button type="button" @click="decider(point, 'attente')" :disabled="envoi" class="rounded-lg bg-slate-200 px-2.5 py-1.5 font-bold text-slate-700 disabled:opacity-50">Plus tard</button>
                                <input type="text" x-model="point.saisie" :disabled="envoi" :aria-label="'Nouvelle valeur pour ligne ' + point.ligne" class="min-w-0 flex-1 rounded-lg border border-slate-200 px-2.5 py-1.5 font-mono">
                                <button type="button" @click="modifier(point)" :disabled="envoi" class="rounded-lg bg-primary-600 px-2.5 py-1.5 font-bold text-white disabled:opacity-50">Appliquer</button>
                            </div>
                            <p x-show="point.traite" class="mt-2 text-[11px] font-bold text-emerald-700">✓ Décision enregistrée côté serveur.</p>
                        </li>
                    </template>
                </ul>
            </section>
            <script>
                function revueImport(url, token, points) {
                    return {
                        points: points,
                        envoi: false,
                        message: '',
                        decider(point, action) { this.envoyer(point, action, {}); },
                        modifier(point) {
                            const valeurs = {};
                            valeurs[point.champ] = point.saisie;
                            this.envoyer(point, 'modifier', valeurs);
                        },
                        async envoyer(point, action, valeurs) {
                            this.envoi = true;
                            this.message = '';
                            try {
                                const reponse = await fetch(url, {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'Accept': 'application/json',
                                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                    },
                                    body: JSON.stringify({ token: token, decisions: [{ ligne: point.ligne, action: action, valeurs: valeurs }] }),
                                });
                                const donnees = await reponse.json();
                                if (! reponse.ok) {
                                    this.message = donnees.message || 'Revue refusée par le serveur.';
                                    return;
                                }
                                point.traite = true;
                            } catch (e) {
                                this.message = 'Service momentanément indisponible, réessayez.';
                            } finally {
                                this.envoi = false;
                            }
                        },
                    };
                }
            </script>
        @endif

        @if(count($resultat->apercu) > 0)
            <section aria-label="Aperçu des données" class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                <table class="w-full min-w-[560px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                            @foreach($definition->colonnes() as $colonne)
                                <th scope="col" class="px-4 py-3 font-bold">{{ $colonne }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($resultat->apercu as $ligne)
                            <tr>
                                @foreach($definition->colonnes() as $colonne)
                                    <td class="px-4 py-2.5 text-slate-700">{{ $ligne[$colonne] ?? '—' }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endif

        <div class="mt-6 flex flex-col sm:flex-row gap-3">
            <form method="POST" action="{{ route('admin.donnees.import.annuler', $entite) }}" class="contents">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <x-btn variant="secondary" type="submit">Annuler</x-btn>
            </form>

            @if(count($resultat->valides) > 0)
                <form method="POST" action="{{ route('admin.donnees.import.confirmer', $entite) }}" class="contents">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">
                    <input type="hidden" name="mode" value="creer">
                    <x-btn type="submit">Confirmer l'import ({{ count($resultat->valides) }} lignes{{ count($resultat->erreurs) > 0 ? ', ' . count($resultat->erreurs) . ' ignorées' : '' }})</x-btn>
                </form>
                @if(in_array('remplacer', $definition->modes(), true))
                    <x-btn variant="secondary" @click="remplacement = true">Remplacer…</x-btn>
                    <x-modal titre="Remplacer les données ?" nom="remplacement">
                        <p>⚠ Le remplacement <strong>supprime les données existantes</strong> avant d'importer. Cette action est journalisée.</p>
                        <x-slot:actions>
                            <form method="POST" action="{{ route('admin.donnees.import.confirmer', $entite) }}" class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
                                @csrf
                                <input type="hidden" name="token" value="{{ $token }}">
                                <input type="hidden" name="mode" value="remplacer">
                                <label class="flex items-center gap-2 rounded-xl border-2 border-amber-300 bg-amber-50 px-3 py-2 text-xs font-bold text-amber-900 cursor-pointer">
                                    <input type="checkbox" name="confirmation" value="1" required class="h-5 w-5">
                                    Je comprends et je confirme
                                </label>
                                <x-btn variant="danger" type="submit">Remplacer</x-btn>
                            </form>
                        </x-slot:actions>
                    </x-modal>
                @endif
            @else
                <p class="text-xs text-slate-500 self-center">Aucune ligne valide : corrigez le fichier et recommencez.</p>
            @endif
        </div>
    </div>
@endsection
