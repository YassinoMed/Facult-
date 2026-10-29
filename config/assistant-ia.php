<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Activation globale du module UniRegistre AI
    |--------------------------------------------------------------------------
    */
    'actif' => env('ASSISTANT_IA_ACTIF', true),

    /*
    |--------------------------------------------------------------------------
    | Fournisseur par défaut ('faux', 'distant', 'local')
    |--------------------------------------------------------------------------
    */
    'fournisseur' => env('ASSISTANT_IA_FOURNISSEUR', 'faux'),

    /*
    |--------------------------------------------------------------------------
    | Routage hybride local-first (LOOP 17)
    |--------------------------------------------------------------------------
    | LOCAL par défaut. Le fournisseur distant n'est jamais utilisé sans
    | (1) autorisation organisationnelle explicite (distant_autorise),
    | (2) permission utilisateur dédiée, (3) contenu non sensible, et
    | (4) consentement serveur lié à l'empreinte de la requête.
    | Aucune bascule silencieuse : un local indisponible reste local.
    */
    'routage' => [
        'local_first' => true,
        'distant_autorise' => env('ASSISTANT_IA_DISTANT_AUTORISE', false),
        'permission_distant' => 'assistant_ia.fournisseur_distant',
        'interdire_fallback_automatique' => true,
        'duree_validite_consentement_secondes' => 300,
        'consentement_ttl_minutes' => 15,
        'sanitisation_pre_routage' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Configuration des passerelles de modèles
    |--------------------------------------------------------------------------
    */
    'fournisseurs' => [
        'faux' => [
            'nom' => 'Simulateur Déterministe Test',
            'modele' => 'fake-model-v1',
            'delai_ms' => 0,
        ],
        'distant' => [
            'nom' => 'Fournisseur Distant (OpenAI / vLLM / Compatible)',
            'actif' => env('ASSISTANT_IA_DISTANT_ACTIF', false),
            'forcer_https' => env('ASSISTANT_IA_DISTANT_FORCER_HTTPS', true),
            'base_url' => env('ASSISTANT_IA_DISTANT_URL', 'https://api.openai.com/v1'),
            'cle_api' => env('ASSISTANT_IA_DISTANT_CLE', ''),
            'modele' => env('ASSISTANT_IA_DISTANT_MODELE', 'gpt-4o-mini'),
            'modeles_autorises' => ['gpt-4o-mini', 'gpt-4o', 'claude-3-5-sonnet', 'mistral-large'],
            'timeout_secondes' => (int) env('ASSISTANT_IA_DISTANT_TIMEOUT', 30),
            'max_caracteres_contexte' => 32000,
        ],
        'local' => [
            'nom' => 'Fournisseur Local Souverain (Ollama / vLLM Local)',
            'actif' => env('ASSISTANT_IA_LOCAL_ACTIF', true),
            'base_url' => env('ASSISTANT_IA_LOCAL_URL', 'http://127.0.0.1:11434/v1'),
            'cle_api' => env('ASSISTANT_IA_LOCAL_CLE', 'local-token'),
            'modele' => env('ASSISTANT_IA_LOCAL_MODELE', 'nvidia/NVIDIA-Nemotron-3.5-Bolt-E4B-4GB-DFlash'),
            'modele_embeddings' => env('ASSISTANT_IA_LOCAL_EMBEDDINGS', 'all-minilm'),
            'profil' => 'nvidia_nemotron_bolt_4gb', // NVIDIA Nemotron 3.5 Bolt (E4B 4GB DFlash)
            'modeles_autorises' => [
                'nvidia/NVIDIA-Nemotron-3.5-Bolt-E4B-4GB-DFlash',
                'nemotron-3.5-bolt',
                'all-minilm',
            ],
            'timeout_secondes' => (int) env('ASSISTANT_IA_LOCAL_TIMEOUT', 60),
            'max_caracteres_entree' => 100000,
        ],
        'groq' => [
            'nom' => 'GroqCloud AI (Inférence Ultra-Rapide)',
            'actif' => (bool) env('AI_ENABLED', false) || (bool) env('ASSISTANT_IA_GROQ_ACTIF', false),
            'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
            'cle_api' => env('GROQ_API_KEY', ''),
            'modele' => (env('GROQ_MODEL') === 'llama-3.3-70b-versatile') ? 'qwen/qwen3.8-27b' : env('GROQ_MODEL', 'qwen/qwen3.8-27b'),
            'modeles_autorises' => [
                'qwen/qwen3.8-27b',
                'openai/gpt-oss-120b',
                'openai/gpt-oss-20b',
                'llama-3.3-70b-versatile',
                'llama-3.1-8b-instant',
                'mixtral-8x7b-32768',
                'gemma2-9b-it',
            ],
            'timeout_secondes' => (int) env('GROQ_TIMEOUT', 30),
            'connect_timeout_secondes' => (int) env('GROQ_CONNECT_TIMEOUT', 5),
            'max_caracteres_contexte' => 32000,
            'max_completion_tokens' => (int) env('GROQ_MAX_TOKENS', 4096),
            'temperature' => (float) env('GROQ_TEMPERATURE', 0.2),
        ],
        'colab' => [
            'nom' => 'Moteur Distant Google Colab (Open Source T4)',
            'actif' => (bool) env('ASSISTANT_IA_COLAB_ACTIF', false),
            'forcer_https' => (bool) env('ASSISTANT_IA_COLAB_FORCER_HTTPS', true),
            'base_url' => env('ASSISTANT_IA_COLAB_URL', ''),
            'cle_api' => env('ASSISTANT_IA_COLAB_TOKEN', ''),
            'modele' => env('ASSISTANT_IA_COLAB_MODELE', 'qwen2.5-coder-7b-instruct'),
            'modeles_autorises' => [
                'qwen2.5-coder-7b-instruct',
                'qwen2.5-7b-instruct',
                'llama-3.1-8b-instruct',
                'mistral-7b-instruct',
            ],
            'timeout_secondes' => (int) env('ASSISTANT_IA_COLAB_TIMEOUT', 30),
            'connect_timeout_secondes' => (int) env('ASSISTANT_IA_COLAB_CONNECT_TIMEOUT', 5),
            'max_caracteres_contexte' => 32000,
            'max_completion_tokens' => (int) env('ASSISTANT_IA_COLAB_MAX_TOKENS', 4096),
            'temperature' => (float) env('ASSISTANT_IA_COLAB_TEMPERATURE', 0.2),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Plafonds et limites de sécurité
    |--------------------------------------------------------------------------
    */
    'limites' => [
        'max_tokens_reponse' => 4096,
        'max_messages_contexte' => 20,
        'max_iterations_agent' => 5,
        'max_longueur_message_caracteres' => 4000,
        'quotas_journaliers_utilisateur' => [
            'super_admin' => 500,
            'admin_scolarite' => 200,
            'admin_faculte' => 200,
            'agent_scolarite' => 100,
            'consultation' => 50,
            'defaut' => 50,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Workflow d'approbation et contrôle à quatre yeux
    |--------------------------------------------------------------------------
    */
    'approbations' => [
        'duree_validite_minutes' => 60,
        'separer_demandeur_approbateur' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Indexation RAG et base documentaire
    |--------------------------------------------------------------------------
    */
    'rag' => [
        'max_extraits_pertinents' => 5,
        'taille_morceau_caracteres' => 1200,
        'chevauchement_caracteres' => 150,
        'dossiers_autorises' => [
            'docs/engineering',
            'docs/architecture',
            'docs/regles-metier',
            'docs',
            'doc',
            '.agents/skills',
            '.opencode',
            'salima_import_csv_2026_2027',
            '.',
        ],
        'extensions_autorisees' => ['md', 'txt', 'json'],
        'motifs_strictement_exclus' => [
            '.env',
            '*.key',
            '*.pem',
            '*.crt',
            'storage/app/private',
            'storage/app/documents',
            'storage/framework',
            'storage/logs',
            'vendor',
            'node_modules',
            'database/backups',
        ],
    ],
];
