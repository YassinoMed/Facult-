<?php

/*
|--------------------------------------------------------------------------
| Durcissement transversal (LOOP-SECURITY-REMEDIATION-01)
|--------------------------------------------------------------------------
| Hôtes de confiance (anti Host header injection), origine canonique,
| obligations MFA et exposition des sondes de supervision. Aucun secret
| ici : uniquement des politiques, surchargeables par variables
| d'environnement.
*/

// Hôtes autorisés : TRUSTED_HOSTS (CSV) + hôte de APP_URL + boucle locale.
// Le port est ignoré (comparaison sur `getHost()`).
$hotesAutorises = array_values(array_filter(array_map(
    fn ($h) => mb_strtolower(trim((string) $h)),
    explode(',', (string) env('TRUSTED_HOSTS', ''))
)));

$hoteApplication = parse_url((string) env('APP_URL', ''), PHP_URL_HOST);

foreach ([$hoteApplication, 'localhost', '127.0.0.1', '::1', '[::1]'] as $hoteDefaut) {
    if (is_string($hoteDefaut) && $hoteDefaut !== '') {
        $hotesAutorises[] = mb_strtolower($hoteDefaut);
    }
}

return [

    /*
     | Hôtes acceptés dans l'en-tête `Host`. Un hôte absent de cette
     | liste est rejeté (400) avant toute génération d'URL — les liens
     | absolus ne peuvent donc pas être empoisonnés par un Host forgé.
     | Les motifs `*.exemple.com` acceptent les sous-domaines.
     */
    'trusted_hosts' => array_values(array_unique($hotesAutorises)),

    /*
     | Force l'origine canonique (APP_URL) pour toute URL absolue générée.
     | Actif par défaut en production uniquement : en local/test, l'hôte
     | de la requête est conservé (repli sûr : il est déjà filtré par
     | `trusted_hosts`).
     */
    'force_canonical_url' => (bool) env('FORCE_CANONICAL_URL', env('APP_ENV', 'production') === 'production'),

    /*
     | Rôles à privilèges : le double facteur (TOTP) y est OBLIGATOIRE.
     | Un compte de ces rôles sans MFA est forcé à l'enrôlement avant
     | tout accès au back-office. Le paramètre applicatif
     | `securite.mfa_obligatoire_admin` étend l'obligation à tous les
     | comptes back-office.
     */
    'mfa_required_roles' => array_values(array_filter(array_map(
        fn ($r) => trim((string) $r),
        explode(',', (string) env('MFA_REQUIRED_ROLES', 'super_admin,admin_scolarite,admin_faculte'))
    ))),

    // Nombre de codes de secours MFA générés à l'activation.
    'mfa_recovery_codes_count' => max(4, (int) env('MFA_RECOVERY_CODES_COUNT', 8)),

    /*
     | Données de démonstration : opt-in EXPLICITE pour autoriser les
     | seeders à mots de passe connus hors local/testing (staging de
     | démonstration uniquement — jamais une production réelle).
     */
    'seed_demo_data' => (bool) env('SEED_DEMO_DATA', false),

    /*
     | Mot de passe initial du compte super-administrateur créé par
     | AdminSeeder hors local/testing (exigé). À définir via l'environnement
     | le temps du seeding, puis à retirer — jamais de valeur par défaut.
     */
    'admin_initial_password' => env('ADMIN_INITIAL_PASSWORD'),

    /*
     | Adresses IP (CSV) autorisées à lire le détail des sondes de
     | supervision (`/api/v1/ready`). Vide = détail jamais exposé
     | publiquement (seul `status` est renvoyé).
     */
    'supervision_allowed_ips' => array_values(array_filter(array_map(
        fn ($ip) => trim((string) $ip),
        explode(',', (string) env('SUPERVISION_ALLOWED_IPS', ''))
    ))),

];
