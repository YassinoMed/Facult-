# UniRegistre — Examens Advanced — Registre des décisions

- **Date :** 2026-10-27
- **Statut :** ouvert
- **Source :** validation métier explicite reçue pendant la phase de cadrage
- **Référentiel principal :** `docs/engineering/LOOP-UNIREGISTRE-EXAMENS-ADVANCED.md`

---

## 1. Règles de gestion du registre

1. Une décision métier ne peut pas être déduite du code existant.
2. Toute règle non validée ici reste une question ouverte et ne doit pas être implémentée comme une règle officielle.
3. Une décision validée peut être modifiée uniquement par une nouvelle décision explicite, datée et justifiée.
4. Les décisions ci-dessous doivent rester compatibles avec les exigences de sécurité, d’intégrité et de non-publication d’un planning non conforme.

---

## 2. Décisions validées

## D-01 — Portée du mode d’organisation

- **Décision :** le choix entre Mode A et Mode B est **mixte hiérarchique**.
- **Détail :**
  - un mode par défaut est défini au niveau de la session d’examen ;
  - une configuration d’examen peut définir une exception ;
  - une épreuve peut définir une exception locale uniquement si cela est explicitement prévu.
- **Conséquence technique :**
  - le mode doit être persisté au moins au niveau session ;
  - les niveaux inférieurs peuvent hériter du mode parent ;
  - toute exception doit être explicite, validée et traçable.
- **Statut :** validé.

## D-02 — Limite de modules

- **Décision :** la limite « 1 à 3 modules » s’applique **par configuration d’examen**.
- **Conséquence technique :**
  - une configuration d’examen peut regrouper de 1 à 3 modules/ECUE ;
  - la limite ne s’applique pas globalement à une session ;
  - la validation doit compter les modules réellement rattachés à la configuration.
- **Statut :** validé.

## D-03 — Épreuve combinée

- **Décision :** une épreuve combinée est **explicitement autorisée**.
- **Condition :**
  - elle doit être prévue par une configuration d’examen explicite ;
  - les modalités suivantes doivent être définies avant publication :
    - durée ;
    - notation ;
    - correction ;
    - convocation ;
    - restitution des résultats par module.
- **Interdiction :**
  - ne jamais fusionner automatiquement des notes ou des résultats de modules distincts.
- **Statut :** validé.

## D-04 — Capacité utilisable pour un examen

- **Décision :** le modèle de capacité est **à deux niveaux**.
- **Détail :**
  - capacité théorique de la salle ;
  - capacité autorisée pour un examen, validée, datée et traçable.
- **Conséquence technique :**
  - la capacité autorisée est la seule opposable à la publication ;
  - une capacité inconnue ou non validée bloque la publication finale ;
  - une capacité provisoire ne peut être acceptée que par une exception approuvée.
- **Statut :** validé.

## D-05 — Contrôle de publication

- **Décision :** publication par **version officielle**.
- **Détail :**
  - permission dédiée de publication ;
  - version candidate ;
  - version publiée immuable ;
  - convocations liées à une version publiée.
- **Conséquence technique :**
  - une simulation valide ne devient officielle que par une publication explicite ;
  - toute republication doit créer une nouvelle version et historiser la précédente ;
  - un statut `published` côté application ne suffit pas sans version persistée.
- **Statut :** validé.

## D-06 — Convocation avant publication

- **Décision :** la génération de convocation avant publication est **interdite**.
- **Détail :**
  - aucune convocation officielle n’est produite avant la version publiée ;
  - l’espace étudiant n’expose que les épreuves publiées ;
  - un administrateur ne peut pas générer une convocation officielle pour une épreuve non publiée.
- **Statut :** validé.

---

## 3. Décisions dérivées techniques

Ces décisions techniques découlent directement des décisions métier validées. Elles ne créent pas de nouvelle règle académique.

## T-01 — Éligibilité centralisée

- L’éligibilité doit être calculée par un service central unique.
- Le service doit renvoyer pour chaque candidat et chaque épreuve :
  - décision ;
  - source de données ;
  - règle appliquée ;
  - motif d’exclusion ;
  - anomalies de données.
- Une appartenance à un groupe ne suffit jamais à déclarer un candidat éligible.

## T-02 — Deux modes, un socle unique

- Mode A et Mode B doivent utiliser les mêmes règles centrales :
  - éligibilité ;
  - capacité ;
  - conflits ;
  - surveillance ;
  - publication ;
  - audit.

## T-03 — Publication = version, pas simple statut

- Le statut `published` ne peut être qu’un indicateur d’affichage.
- La source de vérité de la publication officielle est la version publiée.

## T-04 — Convocation dépendante de la version

- Une convocation doit référencer la version publiée utilisée.
- Une correction après publication doit passer par une nouvelle version.

---

## 4. Questions ouvertes

| ID | Question | Impact si non tranchée |
|---|---|---|
| Q-01 | Quelle est la règle officielle d’éligibilité en rattrapage ? | Bloque la génération des listes de rattrapage |
| Q-02 | Quelles décisions académiques bloquent l’éligibilité ? | Risque d’inclure un étudiant non autorisé |
| Q-03 | Le ratio d’espacement 50 % est-il une règle officielle ? | Capacité utile d’examen non opposable |
| Q-04 | Le minimum réel de surveillance est-il « 2 actifs + 1 réserve » ou un minimum par salle ? | Contrôle de publication incomplet |
| Q-05 | Quelle est la procédure de republication après correction ? | Historisation et traçabilité incomplètes |
| Q-06 | Qui peut approuver une exception et avec quel rôle exact ? | Impossible de garantir une exception autorisée |
| Q-07 | Les sessions restent-elles transversales ou deviennent-elles départementales/facultaires ? | Impacte la politique de périmètre et l’anti-IDOR |

---

## 5. Historique

| Date | Événement |
|---|---|
| 2026-10-27 | Création du registre |
| 2026-10-27 | Validation des décisions D-01 à D-06 |
| 2026-10-27 | Ouverture des questions Q-01 à Q-07 |
