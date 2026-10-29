# UniRegistre — Examens Advanced — Règles métier

- **Date :** 2026-10-27
- **Statut :** version initiale issue des décisions D-01 à D-06
- **Source de décision :** `docs/engineering/UNIREGISTRE-EXAMENS-REGISTRE-DECISIONS.md`
- **Portée :** sessions, configurations, épreuves, éligibilité, groupes, capacités, planification, surveillance, publication, convocations
- **Règle absolue :** ce document ne remplace pas un règlement universitaire officiel ; il traduit uniquement les décisions validées.

---

## 1. Règles de niveau session

### R-01 — Session d’examen

Une session d’examen définit :

- l’année universitaire ;
- la période ;
- le type de session ;
- les dates de début et de fin ;
- le mode d’organisation par défaut.

### R-02 — Mode d’organisation par défaut

- Le mode par défaut est défini au niveau de la session.
- Valeurs possibles :
  - Mode A : groupes pédagogiques comme référence organisationnelle ;
  - Mode B : regroupement spécifique aux examens.
- Une configuration d’examen peut hériter du mode de session.
- Une exception peut être définie au niveau configuration ou épreuve, si elle est explicite.

### R-03 — Rattrapage

- Une session de rattrapage doit être reliée à une session principale de la même année universitaire.
- La règle d’éligibilité exacte en rattrapage reste **non validée** et ne doit pas être inventée.

---

## 2. Règles de configuration d’examen

### R-04 — Périmètre d’une configuration

Une configuration d’examen regroupe :

- une session d’examen ;
- un périmètre académique ;
- un ensemble de modules/ECUE ;
- un mode d’organisation ;
- des modalités d’évaluation.

### R-05 — Limite de modules

- Une configuration d’examen peut contenir **1 à 3 modules**.
- Cette limite s’applique **par configuration**.
- Une configuration vide ou dépassant 3 modules doit être refusée.
- Un module dupliqué dans une même configuration doit être refusé.

### R-06 — Épreuve distincte

- Par défaut, chaque module donne lieu à une épreuve distincte.
- Ce comportement doit rester le mode normal.

### R-07 — Épreuve combinée

Une épreuve combinée est possible uniquement si :

1. la configuration l’autorise explicitement ;
2. la configuration respecte la limite 1-3 modules ;
3. les modalités suivantes sont définies :
   - durée ;
   - notation ;
   - correction ;
   - convocation ;
   - restitution des résultats par module.

Interdictions :

- jamais de fusion automatique de notes ;
- jamais de fusion automatique de résultats ;
- jamais de convocation générée sans configuration explicite.

---

## 3. Règles d’éligibilité

### R-08 — Source de vérité

L’éligibilité est calculée à partir des sources métier officielles :

- inscription académique active ;
- inscription pédagogique au module ;
- année universitaire ;
- programme ;
- niveau ;
- session ;
- décision académique applicable.

### R-09 — Appartenance à un groupe

- L’appartenance à un groupe pédagogique n’est **pas** une preuve d’éligibilité.
- Un groupe peut servir de périmètre organisationnel, mais jamais de substitut à l’inscription pédagogique.

### R-10 — Décision explicite

Pour chaque candidat et chaque épreuve, le service d’éligibilité doit produire :

- décision : éligible / non éligible / anomalie ;
- source de données ;
- règle appliquée ;
- motif en cas d’exclusion ;
- anomalies de données le cas échéant.

### R-11 — Absence de données

- Une donnée absente ne vaut jamais autorisation.
- En cas de doute, le candidat est exclu ou signalé en anomalie, jamais inclus par défaut.
- Les anomalies doivent être traitées avant publication.

### R-12 — Éligibilité par module

- L’éligibilité est évaluée par candidat et par module.
- Un candidat peut être éligible à un module et non éligible à un autre.
- Une configuration combinée ne doit jamais masquer cette distinction.

---

## 4. Règles de groupes d’examen

### R-13 — Mode A

- Les groupes pédagogiques existants servent de référence organisationnelle.
- Les candidats réellement convoqués sont calculés séparément à partir de l’éligibilité.
- Le groupe n’est pas utilisé comme liste de candidats.

### R-14 — Mode B

- Les groupes d’examen sont temporaires.
- Ils sont construits à partir des candidats éligibles.
- Ils doivent respecter les capacités autorisées des salles.
- Ils doivent respecter les contraintes de conflit et de surveillance.
- Ils ne remplacent pas les groupes pédagogiques officiels.

### R-15 — Idempotence

- Répéter une génération ne doit pas créer de doublons.
- Une génération ne doit pas modifier silencieusement une version validée ou publiée.
- Toute modification après validation doit passer par un workflow explicite.

---

## 5. Règles de capacité

### R-16 — Deux niveaux de capacité

Une salle possède :

1. une capacité théorique ;
2. une capacité autorisée pour examen.

### R-17 — Capacité opposable

- La capacité autorisée pour examen est la seule opposable à la publication.
- Elle doit être :
  - connue ;
  - strictement positive ;
  - validée ;
  - datée ;
  - traçable.

### R-18 — Capacité inconnue

- Une capacité inconnue interdit la publication finale.
- Elle peut permettre une simulation théorique, mais jamais une validation officielle.
- Aucune capacité ne doit être inventée par le moteur.

### R-19 — Capacité provisoire

- Une capacité provisoire ne peut être utilisée que si une exception explicite l’autorise.
- L’exception doit être approuvée par un rôle habilité et journalisée.

---

## 6. Règles de planification

### R-20 — Contraintes obligatoires

Une planification finale doit respecter :

- capacité autorisée ;
- disponibilité des salles ;
- créneaux ;
- conflits de candidats ;
- conflits de salles ;
- conflits de surveillants ;
- durées ;
- contraintes de session ;
- règles d’éligibilité.

### R-21 — Simulation vs publication

- Une simulation ne modifie jamais les affectations officielles.
- Une simulation réussie ne vaut pas publication.
- Un planning ne peut être publié que si toutes les contraintes obligatoires sont satisfaites au moment de la publication.

### R-22 — Diagnostic d’échec

En cas d’impossibilité, le système doit retourner des motifs précis :

- capacité insuffisante ;
- salle indisponible ;
- conflit candidat ;
- surveillant manquant ;
- donnée obligatoire inconnue ;
- contrainte de configuration non respectée.

### R-23 — Aucune affectation partielle présentée comme définitive

- Si une planification échoue, aucune affectation partielle ne peut être présentée comme officielle.
- Toute écriture partielle doit être annulée ou explicitement marquée comme simulation.

---

## 7. Règles de surveillance

### R-24 — Surveillants requis

- Une épreuve sans surveillants suffisants ne peut pas être publiée.
- La règle exacte « 2 actifs + 1 réserve » ou « minimum par salle » reste **à valider**.

### R-25 — Conflits de surveillance

Un surveillant ne peut pas être affecté simultanément à :

- deux épreuves qui se chevauchent ;
- un cours régulier incompatible ;
- une indisponibilité déclarée.

### R-26 — Historique

- Toute affectation, remplacement, désaffectation ou correction doit être journalisée.
- L’historique doit permettre de savoir :
  - qui a décidé ;
  - quand ;
  - pourquoi ;
  - sur quelle version.

---

## 8. Règles de publication

### R-27 — Version officielle obligatoire

- Une publication crée une version officielle.
- La version publiée est immuable.
- Le statut `published` seul n’est pas suffisant.

### R-28 — Contrôles avant publication

Avant publication, le système doit vérifier :

- éligibilité ;
- effectifs ;
- capacité autorisée ;
- disponibilités ;
- conflits ;
- surveillance suffisante ;
- complétude des données ;
- fraîcheur de la simulation ;
- cohérence entre configuration et épreuves.

### R-29 — Publication explicite

- La publication est une action explicite.
- Elle nécessite une permission dédiée.
- Une simulation ne peut jamais devenir officielle automatiquement.

### R-30 — Republication

- Une correction après publication ne modifie pas la version existante.
- Elle doit créer une nouvelle version.
- La procédure détaillée de republication reste **à valider**.

---

## 9. Règles de convocation

### R-31 — Interdiction avant publication

- Aucune convocation officielle ne peut être générée avant la publication.
- L’espace étudiant n’expose que les épreuves publiées.
- Un administrateur ne peut pas générer une convocation officielle pour une épreuve non publiée.

### R-32 — Lien avec la version publiée

- Une convocation doit référencer la version publiée.
- La version doit permettre de reconstituer :
  - date ;
  - heure ;
  - salle ;
  - place ;
  - module ;
  - modalités.

### R-33 — Traçabilité

Chaque génération et distribution doit enregistrer :

- acteur ;
- date ;
- version ;
- candidat ;
- état d’envoi ;
- échec éventuel ;
- nouvelle tentative éventuelle.

---

## 10. Règles de sécurité et d’audit

### R-34 — Permission dédiée

- La publication doit exiger une permission dédiée.
- La permission de modification ne doit pas suffire pour publier.

### R-35 — Anti-IDOR

- Aucun identifiant fourni par le client ne doit être cru.
- Les identifiants de session, épreuve, candidat, salle ou surveillant doivent être revalidés côté serveur.

### R-36 — Fraîcheur des données

- La publication doit revérifier les données critiques au moment de l’écriture.
- Une simulation ancienne ne suffit pas si les données ont changé.

### R-37 — Journalisation

Les actions sensibles doivent être journalisées :

- génération de candidats ;
- génération de groupes ;
- affectation de salles ;
- affectation de surveillants ;
- simulation ;
- validation ;
- publication ;
- republication ;
- génération de convocation ;
- approbation d’exception.

### R-38 — Confidentialité

- Aucune donnée personnelle inutile ne doit figurer dans les journaux, snapshots ou rapports.
- Les listes de candidats ne sont accessibles qu’aux rôles habilités.
- Les exports sont soumis aux mêmes contrôles que les écrans.

---

## 11. Règles encore non validées

| ID | Règle manquante | État |
|---|---|---|
| R-39 | Éligibilité exacte en rattrapage | À valider |
| R-40 | Décisions académiques bloquantes | À valider |
| R-41 | Ratio d’espacement officiel | À valider |
| R-42 | Minimum exact de surveillance | À valider |
| R-43 | Procédure détaillée de republication | À valider |
| R-44 | Rôle exact d’approbation d’exception | À valider |
| R-45 | Portée transversale ou facultaire des sessions | À valider |
