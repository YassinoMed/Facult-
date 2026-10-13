<?php

namespace App\Services;

/**
 * Registre des définitions de paramètres (LOOP C) : clé stable, type,
 * portée, défaut, règles de validation, sensibilité, propriétaire.
 *
 * Toute clé absente du registre retombe sur la validation par type du
 * contrôleur (comportement historique conservé). Portée unique admise :
 * `global` (aucune surcharge facultaire prouvée — matrice LOOP A).
 */
final class SettingDefinition
{
    /**
     * @return array<string, array{type: string, portee: string, defaut: mixed, regles: array<int, string>, sensibilite: string, proprietaire: string, impact: string}>
     */
    public static function catalogue(): array
    {
        return [
            'etablissement.nom' => [
                'type' => 'texte', 'portee' => 'global', 'defaut' => 'Université',
                'regles' => ['required', 'string', 'max:2000'],
                'sensibilite' => 'publique', 'proprietaire' => 'Direction', 'impact' => 'normal',
            ],
            'etablissement.fuseau' => [
                'type' => 'texte', 'portee' => 'global', 'defaut' => 'Africa/Casablanca',
                // Un fuseau invalide casserait tout calcul daté le jour où
                // la clé est consommée : garde préventive à coût nul.
                'regles' => ['required', 'timezone:all'],
                'sensibilite' => 'interne', 'proprietaire' => 'Direction', 'impact' => 'normal',
            ],
            'academique.seuil_validation' => [
                'type' => 'entier', 'portee' => 'global', 'defaut' => 10,
                // Échelle /20 partagée avec RegleEvaluation (min:0, max:20) ;
                // valeur indicative, les règles versionnées font foi.
                'regles' => ['required', 'integer', 'min:0', 'max:20'],
                'sensibilite' => 'interne', 'proprietaire' => 'Scolarité', 'impact' => 'normal',
            ],
            'assiduite.seuil_alerte' => [
                'type' => 'entier', 'portee' => 'global', 'defaut' => 5,
                'regles' => ['required', 'integer', 'min:0'],
                'sensibilite' => 'interne', 'proprietaire' => 'Scolarité', 'impact' => 'normal',
            ],
            'notifications.purge_jours' => [
                'type' => 'entier', 'portee' => 'global', 'defaut' => 180,
                'regles' => ['required', 'integer', 'min:1'],
                'sensibilite' => 'interne', 'proprietaire' => 'Exploitation', 'impact' => 'fort',
            ],
            'interface.pagination' => [
                'type' => 'entier', 'portee' => 'global', 'defaut' => 15,
                'regles' => ['required', 'integer', 'min:5', 'max:100'],
                'sensibilite' => 'publique', 'proprietaire' => 'Exploitation', 'impact' => 'normal',
            ],
            'sentinel.file_echecs_seuil' => [
                'type' => 'entier', 'portee' => 'global', 'defaut' => 5,
                'regles' => ['required', 'integer', 'min:1'],
                'sensibilite' => 'interne', 'proprietaire' => 'Exploitation', 'impact' => 'normal',
            ],
            'sentinel.file_attente_seuil' => [
                'type' => 'entier', 'portee' => 'global', 'defaut' => 1000,
                'regles' => ['required', 'integer', 'min:1'],
                'sensibilite' => 'interne', 'proprietaire' => 'Exploitation', 'impact' => 'normal',
            ],
            'sentinel.notif_cooldown_minutes' => [
                'type' => 'entier', 'portee' => 'global', 'defaut' => 60,
                'regles' => ['required', 'integer', 'min:1'],
                'sensibilite' => 'interne', 'proprietaire' => 'Exploitation', 'impact' => 'normal',
            ],
        ];
    }

    /**
     * @return array<int, string>|null
     */
    public static function reglesPour(string $cle): ?array
    {
        return self::catalogue()[$cle]['regles'] ?? null;
    }
}
