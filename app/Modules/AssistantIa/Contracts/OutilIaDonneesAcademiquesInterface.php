<?php

namespace App\Modules\AssistantIa\Contracts;

/**
 * Marqueur de sécurité pour un outil qui restitue des données académiques
 * individuelles (notes, décisions, crédits, parcours).
 *
 * Un tel outil reste utilisable en local, mais son résultat ne doit jamais
 * être transmis à un fournisseur d'IA externe : le registre l'écarte des
 * définitions exposées au modèle distant et refuse son exécution distante.
 *
 * Ce marqueur ne remplace ni la permission RBAC, ni le contrôle de périmètre
 * facultaire, ni la validation métier de l'établissement.
 */
interface OutilIaDonneesAcademiquesInterface {}
