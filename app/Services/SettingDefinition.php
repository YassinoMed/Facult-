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
            'verrouillage.actif' => [
                'type' => 'booleen', 'portee' => 'global', 'defaut' => '1',
                'regles' => ['required', 'in:0,1,vrai,faux,true,false,oui,non'],
                'sensibilite' => 'interne', 'proprietaire' => 'Sécurité', 'impact' => 'fort',
            ],
            'verrouillage.delai_minutes' => [
                'type' => 'entier', 'portee' => 'global', 'defaut' => 15,
                'regles' => ['required', 'integer', 'min:5', 'max:480'],
                'sensibilite' => 'interne', 'proprietaire' => 'Sécurité', 'impact' => 'normal',
            ],
            'verrouillage.preavis_secondes' => [
                'type' => 'entier', 'portee' => 'global', 'defaut' => 60,
                'regles' => ['required', 'integer', 'min:0', 'max:300'],
                'sensibilite' => 'interne', 'proprietaire' => 'Sécurité', 'impact' => 'normal',
            ],
            'verrouillage.pin_longueur' => [
                'type' => 'entier', 'portee' => 'global', 'defaut' => 6,
                'regles' => ['required', 'integer', 'min:6', 'max:8'],
                'sensibilite' => 'interne', 'proprietaire' => 'Sécurité', 'impact' => 'normal',
            ],
            'verrouillage.tentatives_max' => [
                'type' => 'entier', 'portee' => 'global', 'defaut' => 5,
                'regles' => ['required', 'integer', 'min:3', 'max:10'],
                'sensibilite' => 'interne', 'proprietaire' => 'Sécurité', 'impact' => 'normal',
            ],
            'verrouillage.temporisation_secondes' => [
                'type' => 'entier', 'portee' => 'global', 'defaut' => 30,
                'regles' => ['required', 'integer', 'min:10', 'max:300'],
                'sensibilite' => 'interne', 'proprietaire' => 'Sécurité', 'impact' => 'normal',
            ],
            'securite.mfa_obligatoire_admin' => [
                'type' => 'booleen', 'portee' => 'global', 'defaut' => '0',
                'regles' => ['required', 'in:0,1,vrai,faux,true,false,oui,non'],
                'sensibilite' => 'interne', 'proprietaire' => 'Sécurité', 'impact' => 'fort',
            ],
            'securite.duree_session_minutes' => [
                'type' => 'entier', 'portee' => 'global', 'defaut' => 120,
                'regles' => ['required', 'integer', 'min:15', 'max:720'],
                'sensibilite' => 'interne', 'proprietaire' => 'Sécurité', 'impact' => 'normal',
            ],
            'securite.mot_de_passe_min_longueur' => [
                'type' => 'entier', 'portee' => 'global', 'defaut' => 10,
                'regles' => ['required', 'integer', 'min:8', 'max:32'],
                'sensibilite' => 'interne', 'proprietaire' => 'Sécurité', 'impact' => 'fort',
            ],
            'securite.connexion_tentatives_max' => [
                'type' => 'entier', 'portee' => 'global', 'defaut' => 5,
                'regles' => ['required', 'integer', 'min:3', 'max:15'],
                'sensibilite' => 'interne', 'proprietaire' => 'Sécurité', 'impact' => 'normal',
            ],
            'inscription.ouverte' => [
                'type' => 'booleen', 'portee' => 'global', 'defaut' => '1',
                'regles' => ['required', 'in:0,1,vrai,faux,true,false,oui,non'],
                'sensibilite' => 'publique', 'proprietaire' => 'Scolarité', 'impact' => 'fort',
            ],
            'inscription.prefill_cin_actif' => [
                'type' => 'booleen', 'portee' => 'global', 'defaut' => '1',
                'regles' => ['required', 'in:0,1,vrai,faux,true,false,oui,non'],
                'sensibilite' => 'interne', 'proprietaire' => 'Scolarité', 'impact' => 'normal',
            ],
            'inscription.scan_ocr_actif' => [
                'type' => 'booleen', 'portee' => 'global', 'defaut' => '1',
                'regles' => ['required', 'in:0,1,vrai,faux,true,false,oui,non'],
                'sensibilite' => 'interne', 'proprietaire' => 'Scolarité', 'impact' => 'normal',
            ],
            'presences.tolerance_retard_minutes' => [
                'type' => 'entier', 'portee' => 'global', 'defaut' => 15,
                'regles' => ['required', 'integer', 'min:0', 'max:120'],
                'sensibilite' => 'interne', 'proprietaire' => 'Scolarité', 'impact' => 'normal',
            ],
            'presences.fenetre_pointage_minutes' => [
                'type' => 'entier', 'portee' => 'global', 'defaut' => 60,
                'regles' => ['required', 'integer', 'min:10', 'max:240'],
                'sensibilite' => 'interne', 'proprietaire' => 'Scolarité', 'impact' => 'normal',
            ],
            'sauvegarde.conserver' => [
                'type' => 'entier', 'portee' => 'global', 'defaut' => 14,
                'regles' => ['required', 'integer', 'min:1', 'max:365'],
                'sensibilite' => 'interne', 'proprietaire' => 'Exploitation', 'impact' => 'fort',
            ],
            'sauvegarde.alerte_age_heures' => [
                'type' => 'entier', 'portee' => 'global', 'defaut' => 30,
                'regles' => ['required', 'integer', 'min:1', 'max:168'],
                'sensibilite' => 'interne', 'proprietaire' => 'Exploitation', 'impact' => 'normal',
            ],
            'sauvegarde.notifier_echec' => [
                'type' => 'booleen', 'portee' => 'global', 'defaut' => '1',
                'regles' => ['required', 'in:0,1,vrai,faux,true,false,oui,non'],
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
