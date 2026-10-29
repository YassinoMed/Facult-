<?php

/*
|--------------------------------------------------------------------------
| Configuration du module AI-ASSISTANT (LOOP-...-AI-FOUNDATION-01)
|--------------------------------------------------------------------------
| Sécurité :
|  - Aucune clé API dans ce fichier (variables d'env d'exploitation
|    uniquement, jamais écrites par les tests ni par ce LOOP).
|  - Aucune source sensible n'est indexée par défaut (liste explicite).
|  - Plafonds de consommation et délais bornés serveur.
| Le module est désactivé par défaut (cf. config/modules.php).
*/

return [

    // Fournisseur actif (section ci-dessous). 'factice' = déterministe,
    // réservé aux tests et à la démonstration hors réseau.
    'defaut' => env('AI_FOURNISSEUR', 'factice'),

    'fournisseurs' => [
        'factice' => [
            'driver' => 'factice',
        ],

        // Adaptateur HTTP compatible OpenAI (/v1/chat/completions). Sert
        // aussi bien un fournisseur distant qu'un serveur local (base_url
        // configurable) — un seul client, deux destinations déclarées.
        'distant_compat' => [
            'driver' => 'compatible_openai',
            'base_url' => env('AI_DISTANT_BASE_URL', ''),
            'modele' => env('AI_DISTANT_MODELE', ''),
            'cle' => env('AI_DISTANT_CLE', ''), // JAMAIS affichée ni journalisée.
            'secondes' => 30,
        ],
        'local_compat' => [
            'driver' => 'compatible_openai',
            'base_url' => env('IA_LOCAL_BASE_URL', 'http://127.0.0.1:11434/v1'),
            'modele' => env('IA_LOCAL_MODELE', 'llama3'),
            'cle' => env('IA_LOCAL_CLE', ''),
            'secondes' => 20,
        ],

        // Serveur LOCAL Ollama (API native /api/chat) — LOCAL-STRICT :
        // base_url en boucle uniquement refusée au-delà, catalogue /api/tags
        // vérifié avant génération (modèle absent = erreur typée, JAMAIS de
        // pull), modèles « -cloud » refusés, aucun fallback cloud, aucune clé.
        'ollama' => [
            'driver' => 'ollama',
            'base_url' => env('AI_OLLAMA_BASE_URL', 'http://127.0.0.1:11434'),
            'modele' => env('AI_OLLAMA_MODELE', 'nvidia/NVIDIA-Nemotron-3.5-Bolt-E4B-4GB-DFlash'),
            'secondes' => 60,
            // Options Ollama transmises telles quelles (num_ctx, keep_alive...).
            'options' => [],
            // Vérification du catalogue local activée par défaut.
            'verifier_modele' => true,
        ],
    ],

    // Plafonds serveur (inviolables côté client).
    'limites' => [
        'caracteres_message' => 4000,
        'appels_par_heure' => 20, // par utilisateur (mode développeur : 8).
        'appels_par_heure_developpeur' => 8,
        'jetons_max' => 2000, // budget par réponse.
        'iterations_agent_max' => 4, // anti-boucle d'outils.
        'chunks_rag_max' => 5, // limitation des résultats RAG par requête.
    ],

    // Contexte conversationnel : derniers N messages, budget en caractères,
    // résumé applicatif (jamais de chaîne de raisonnement privée).
    'contexte' => [
        'messages_derniers' => 12,
        'caracteres_max' => 6000,
    ],

    // Base documentaire : liste EXPLICITE (aucune découverte automatique).
    'rag' => [
        'profondeur_chunks' => 6, // nb lignes max par chunk.
        'sources' => [
            'typo_docs' => [
                'docs/inscriptions/rules.md',
                'docs/inscriptions/re-enrollment.md',
                'docs/students-groups/assignment.md',
                'docs/BACK-OFFICE.md',
                'docs/DATA-EXCHANGE.md',
                'docs/ARCHITECTURE.md',
            ],
            'rapports_tech' => [
                'docs/engineering/AI-ASSISTANT-ARCHITECTURE.md',
            ],
        ],

        // Défense en profondeur : même listé explicitement, un fichier
        // interdit reste interdit (aucun secret/PII ne doit être indexé).
        'exclusions' => ['.env', '.key', '.pem', 'vendor/', 'node_modules/', 'storage/', 'database/'],

        // Empreintes de PII à détecter AVANT indexation : un fichier
        // positif est sauté entier (données jamais indexées ni signalées).
        'motifs_pii' => [
            'email' => '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\\.[A-Za-z]{2,}',
            'secret' => '(?i)(mot_de_passe|mot.de.passe|password|secret|api[_-]?key|cle[_-]?api)\\s*[:=]',
            'cin' => '(?i)\\bcin\\b\\s*[:=]',
        ],

        // Bornes de découpage (mots-clés conservés pour la recherche).
        'taille_morceau' => 1000, // caractères max par morceau.
        'extrait_max' => 240, // longueur restituée par recherche.
        'morceaux_max_par_fichier' => 30,
    ],

    // Approbations humaines (Lots 7/9) : les correctifs de code restent
    // TOUJOURS une décision 4 yeux ; jamais d'application automatique.
    'approbations' => [
        'heures_validite' => 24,
        'taille_diff_max' => 8000,
        'cibles_max' => 5,
        'impacts_resume_defaut' => 'Proposition IA — JAMAIS exécutée automatiquement ; décision humaine requise.',
    ],

    // Rétention (jours) — à valider avec la vie privée métier (Phase 0 §8).
    'retention' => [
        'conversations_jours' => 90,
        'approbations_jours' => 365,
    ],

    // Journalisation : champs autorisés (aucun contenu de prompt,
    // aucune réponse brute, aucune PII — cf. Lots 5/11/12).
    'journalisation' => [
        'canal' => 'ai-assistant',
        'champs_autorises' => ['fournisseur', 'modele', 'latence_ms', 'jetons', 'chunks', 'outil', 'etat'],
    ],
];
