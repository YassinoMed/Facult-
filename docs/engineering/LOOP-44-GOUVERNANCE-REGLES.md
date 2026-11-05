# LOOP 44 — GOUVERNANCE VERSIONNÉE DES RÈGLES ACADÉMIQUES

**Date :** 31 octobre 2026
**Objet :** rendre toute règle académique traçable, datée, approuvée par un tiers, publiable et
retirable, sans jamais inventer de seuil, de quota ou de délai officiel.

---

## 1. Audit préalable

| Besoin | Existant | Décision |
|---|---|---|
| Règles d'évaluation opérationnelles | Table `evaluation_regles` + modèle `RegleEvaluation` (`seuil_validation`, `seuil_rattrapage`, `note_eliminatoire`, compensation, arrondi, `version`, `actif`) | **Conservée telle quelle, non modifiée.** Stocke des paramètres consommés par le moteur, sans référence officielle, sans approbateur, sans date d'effet, sans retrait |
| Règles de parcours | Table `parcours_regles`, `ParcoursRegleService` | **Conservée, non modifiée** |
| Règles Sentinel | Table `sentinel_regles` | **Conservée, non modifiée** |
| Traçabilité officielle d'une règle (texte, référence, date d'effet, approbation, publication, retrait) | **Aucun dispositif** | Nouvelle table `regles_academiques_versions` + `GouvernanceReglesService` |

**Constat mesuré sur la base locale (`inscription_etudiants`) :**

| Table | Lignes |
|---|---|
| `evaluation_regles` | 0 |
| `parcours_regles` | 0 |
| `sentinel_regles` | 0 |
| `regles_academiques_versions` (nouvelle) | 0 |

Aucune règle officielle n'existe donc en base. Le socle livré ici n'en crée aucune.

**Frontière assumée :** `regles_academiques_versions` est le **registre de l'acte de gouvernance**
(version, référence, date d'effet, approbateur, publicateur, retrait). `evaluation_regles` reste la
**projection opérationnelle** consommée par le module Évaluation. Les deux ne sont pas encore reliés :
aucun moteur ne lit le registre à ce stade, et le registre ne modifie aucune table existante. Cette
convergence est une réserve explicite (RES-44-02), pas un acquis.

---

## 2. Modèle de données

`database/migrations/2026_10_31_100000_creer_regles_academiques_versions.php`

| Colonne | Type | Rôle |
|---|---|---|
| `code` | varchar(120), indexé | Code fonctionnel normalisé (majuscules, chiffres, `.`, `-`, `_`) |
| `version` | unsigned int | Version strictement croissante par `code` |
| `statut` | varchar(20), indexé | `BROUILLON`, `EN_REVUE`, `APPROUVEE`, `PUBLIEE`, `RETIREE` |
| `contenu` | json | Contenu de la règle, structure libre validée par le service |
| `reference_officielle` | varchar(255), nullable | Texte ou décision qui fonde la règle — **obligatoire pour approuver/publier** |
| `date_effet`, `date_fin` | date, nullable | Période d'application — `date_effet` obligatoire pour approuver/publier |
| `checksum` | varchar(64) | SHA-256 du contenu canonique (clés triées récursivement) |
| `cree_par_id`, `approuve_par_id`, `publie_par_id`, `retire_par_id` | FK `users`, nullable | Traçabilité des acteurs, `nullOnDelete` |
| `approuve_le`, `publie_le`, `retire_le` | timestamp, nullable | Horodatage des actes |
| `motif_retrait` | text, nullable | Motif obligatoire au retrait |

Contraintes : `unique(code, version)`, index `(code, statut, date_effet)`, index `code`, index `statut`.
La migration est **additive** : aucune table existante n'est altérée ; `down()` ne supprime que la
nouvelle table.

---

## 3. Cycle de vie et gardes

| Transition | Acteur | Gardes |
|---|---|---|
| ∅ → `BROUILLON` | Rédacteur | Code valide, contenu non vide et sérialisable (≤ 65 535 octets), référence ≤ 255, période cohérente ; version = `max(version) + 1` par code, 3 tentatives en cas de collision d'unicité |
| `BROUILLON` → `EN_REVUE` | Rédacteur | Le statut doit être exactement `BROUILLON` |
| `BROUILLON`/`EN_REVUE` → modifié | Rédacteur | Une modification en revue **annule la revue** (retour `BROUILLON`, approbation effacée) ; empreinte recalculée |
| `EN_REVUE` → `APPROUVEE` | Approbateur | **Auteur ≠ approbateur** ; référence officielle non vide ; `date_effet` présente ; statut `EN_REVUE` |
| `APPROUVEE` → `PUBLIEE` | Autorité de publication | **Auteur ≠ publicateur** ; référence et date d'effet présentes ; **aucun chevauchement** avec une autre version publiée du même code |
| `PUBLIEE` → `RETIREE` | Autorité de publication | Statut `PUBLIEE` ; **motif non vide** ; contenu et empreinte restent figés |

**Immuabilité (modèle + service) :** dès le statut `APPROUVEE`, les champs `code`, `version`,
`contenu`, `checksum`, `reference_officielle`, `date_effet`, `date_fin` sont figés. Toute tentative
de modification lève une `DomainException`. Seul un `BROUILLON` est supprimable.

**Consultation historique :** une version `RETIREE` reste lisible et reste **applicable pour les
dates strictement antérieures à son retrait**. Un audit passé retrouve donc la règle réellement en
vigueur, sans réécrire l'histoire. À partir de la date de retrait, `regleApplicable()` renvoie `null`
si aucune version successeur n'est publiée.

**Fail-closed :**

- aucune version applicable ⇒ `null` ;
- plusieurs versions applicables (corruption, insertion hors service) ⇒ `DomainException`
  `Ambiguïté` ;
- code inconnu ⇒ `null` ;
- aucune règle métier n'est fournie par le socle.

---

## 4. Service livré

`app/Services/GouvernanceReglesService.php`

| Méthode | Effet |
|---|---|
| `creerBrouillon(array $donnees, User $auteur)` | Crée une version `BROUILLON`, normalise le code, valide contenu/référence/période, calcule l'empreinte |
| `mettreAJourBrouillon(...)` | Modifie un brouillon ou une revue ; une revue modifiée redevient brouillon |
| `soumettre(...)` | `BROUILLON` → `EN_REVUE` |
| `approuver(...)` | `EN_REVUE` → `APPROUVEE`, quatre yeux, référence + date exigées |
| `publier(...)` | `APPROUVEE` → `PUBLIEE`, séparation des fonctions, anti-chevauchement |
| `retirer(...)` | `PUBLIEE` → `RETIREE`, motif obligatoire |
| `regleApplicable(string $code, $date = null)` | Version en vigueur à une date, ou `null`, ou exception sur ambiguïté |
| `historique(string $code)` | Toutes les versions d'un code, ordonnées |
| `verifierCoherence()` | Audit en lecture seule, sans écriture |
| `checksum(string $code, int $version, array $contenu)` | Empreinte canonique SHA-256 |

### Classes d'anomalies détectées

`PUBLIEE_SANS_REFERENCE`, `PUBLIEE_SANS_DATE_EFFET`, `PUBLIEE_SANS_APPROBATION`,
`RETIREE_SANS_DATE`, `RETIREE_SANS_MOTIF`, `DATE_FIN_AVANT_EFFET`, `AUTO_APPROBATION`,
`AUTO_PUBLICATION`, `CHECKSUM_INVALIDE`, `CHEVAUCHEMENT_PUBLIE`.

`verifierCoherence()` ne corrige rien : il constate. Toute correction reste un acte humain tracé.

---

## 5. Séparation des pouvoirs (RBAC)

`app/Support/Permissions.php` — nouvelles permissions :

`regles.consulter`, `regles.rediger`, `regles.soumettre`, `regles.approuver`, `regles.publier`,
`regles.retirer`, `regles.auditer`.

| Rôle | Permissions accordées |
|---|---|
| `admin_scolarite` | `consulter`, `rediger`, `soumettre` |
| `direction_etudes` | `consulter`, `approuver`, `auditer` |
| `autorite_publication` | `consulter`, `publier`, `retirer`, `auditer` |
| `certificateur_qualite` | `consulter`, `auditer` |
| `auditeur` | `consulter`, `auditer` |
| `consultation` | `consulter` |
| `super_admin` | Toutes (rôle technique, journalisé) |

La séparation est donc double : au niveau RBAC (aucun rôle ne cumule rédaction + approbation +
publication) et au niveau service (contrôle nominatif auteur ≠ approbateur ≠ publicateur). Un
`super_admin` techniquement capable de tout reste soumis au contrôle nominatif du service : il ne
peut pas approuver ni publier sa propre règle.

---

## 6. Migration appliquée

| Élément | Preuve |
|---|---|
| Mode | `php artisan migrate --path=database/migrations/2026_10_31_100000_creer_regles_academiques_versions.php --force` |
| Portée | **Un seul fichier** ; les 10 autres migrations en attente n'ont pas été exécutées |
| Résultat | `DONE`, batch `[28]` |
| Table | `regles_academiques_versions` présente |
| Lignes créées | **0** |
| Autres tables | `evaluation_regles`, `parcours_regles`, `sentinel_regles` inchangées (0 ligne chacune) |

Aucune migration destructive, aucun `migrate:fresh`, aucun `db:wipe`, aucun seed.

---

## 7. Ce qui n'est pas livré

- **Aucune interface** (contrôleur, route, vue) : le cycle n'est exercé que par le service et les tests.
- **Aucune règle réelle** : 0 ligne, aucun seuil, aucun quota, aucun délai.
- **Aucun consommateur métier** : `MoteurDecisionAcademiqueService`, `ReglementationAcquisService` et
  `RegleEvaluation` ne lisent pas encore le registre.
- **Aucune notification** d'un approbateur ou d'un publicateur.
- **Aucune signature électronique** ni chaîne de confiance : seul un checksum d'intégrité est calculé.
- **Aucun test de concurrence MySQL** sur `max(version) + 1` ; la protection repose sur
  `unique(code, version)` + 3 tentatives, vérifiée uniquement en SQLite.
- **Aucun lien** entre `regles_academiques_versions` et `evaluation_regles`.

---

## 8. Risques et parades

| Risque | Parade actuelle | Limite |
|---|---|---|
| Règle publiée sans base officielle | Référence obligatoire à l'approbation et à la publication | Le contenu de la référence n'est pas vérifié par le système |
| Auto-approbation / auto-publication | Contrôle nominatif + RBAC séparé | `super_admin` doit être restreint opérationnellement |
| Réécriture d'une règle passée | Champs figés + empreinte SHA-256 | Une modification directe en base reste détectable, pas empêchée |
| Chevauchement de deux versions | Refus à la publication + audit | Une insertion hors service reste possible, détectée par `verifierCoherence()` |
| Double source de vérité (`evaluation_regles`) | Frontière documentée, aucune écriture croisée | Convergence non réalisée (RES-44-02) |
| Ambiguïté d'application | Exception explicite, jamais de choix silencieux | Les appelants futurs devront traiter l'exception |
