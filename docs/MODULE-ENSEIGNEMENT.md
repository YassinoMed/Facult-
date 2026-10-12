# Module Enseignement — Phase 3

## §1. Cartographie (§2 : auditer avant de coder)

```
CONSERVÉ : UE (référentiel Phase 1), Matiere = ECUE (+ responsable,
  volumes, évaluation, prérequis via ecue_prerequis), Teacher, Group,
  année universitaire (cœur). AUCUNE entité dupliquée.
CRÉÉ : types_enseignement, enseignements (ECUE × type × année),
  affectations_enseignement, sous_groupes, enseignement_groupe,
  seuils_service, composantes_evaluation.
MODIFIÉ : matieres (+responsable_teacher_id).
```

## §2. Décisions

- **ECUE ≠ Enseignement** : l'ECUE est le contenu (Phase 1) ;
  l'enseignement est la manière de dispenser (type, volumes, groupes,
  enseignants, année) — unique par (ECUE × type × année).
- **Types enseignement** : table configurable, jamais codée en dur.
- **Volumes** : `volumeTotal()` (CM+TD+TP+Projet+Autre), négatifs
  refusés ; complet verrouillé (statut) → motif obligatoire.
- **Affectations** : unique par (enseignement × enseignant × rôle) ;
  enseignant actif requis ; groupe cohérent avec le programme.
- **Sous-groupes** : rattachés au groupe principal (TD/TP).
- **Services** : charge par type + seuils (grade > faculté > global),
  surcharge / sous-charge affichées (jamais bloquées par défaut).
- **Évaluation** : composantes dont la somme = 100 % ; responsable
  actif requis.
- **Audit** : event `EnseignementChanged` (ids + action, sans PII).
- **Import/export** : définition `enseignements` via DataExchange
  (références ECUE/type/année résolues serveur, rollback sur erreur).
- Flag `MODULE_ENSEIGNEMENT` (défaut true, fondation pour Phase 4).
