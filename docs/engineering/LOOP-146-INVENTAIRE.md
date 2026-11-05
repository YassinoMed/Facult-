# INVENTAIRE EXHAUSTIF DES 146 QUESTIONS MÉTIER (Q001 à Q146) — UNIREGISTRE

**Référence :** `LOOP-146-INVENTAIRE.md`
**Version :** 4.3 (ajoute la surface HTTP admin/étudiante, les tests de permission et le registre `questions/`)
**Date :** 2026-10-30 05:12 CET
**Nature :** inventaire documentaire ; aucune question inventée, aucune règle créée.

> **Avertissement d'intégrité.** Les versions 1.0 et 2.0 de ce fichier contenaient un bloc « Q113–Q146 » présenté comme `CONFIRMÉE`, avec pour seule source « Mandat §8.x ». Le §8 du mandat est une consigne de test, pas une réponse métier. Ces formulations étaient donc inventées et sont retirées.
>
> **Décision de l'utilisateur (2026-10-29).** L'utilisateur a explicitement désigné comme source de référence le fichier `~/Downloads/Nouveau dossier contenant des éléments 5/questions_reponses_LOOP_146.md`. Ce fichier est un registre produit par un agent le 2026-10-29 à 21:11:06. Il déclare lui-même : Q001–Q131 introuvables, Q132–Q145 = réponses confirmées avec **formulations de questions reconstruites à vérifier**, Q146 = réponse finale non confirmée. La désignation utilisateur fonde les **réponses** Q132–Q145, pas la vérification des formulations. Cette distinction est portée dans chaque ligne et dans le verdict.

---

## 1. PREUVES D'ABSENCE DE SOURCE (E1–E8)

| ID | Recherche | Commande / méthode | Résultat |
| :---: | :--- | :--- | :--- |
| **E1** | Dépôt Git (hors vendor/node_modules/rea) | `rg -l '\bQ0[0-9][0-9]\b|\bQ1[0-4][0-9]\b' . -g '!vendor/**' -g '!node_modules/**' -g '!.git/**' -g '!rea/**'` | 4 fichiers hors livrables, tous écrits par des agents d'implémentation (`NotificationService.php`, `EcheanceDerogationService.php`, `EcheanceDerogationServiceTest.php`, `NotificationPreuveRemiseTest.php`). Aucun corpus de questions. |
| **E2** | Base OpenCode | 21 229 messages, 133 sessions (relevé antérieur) | 2 occurrences, produites par l'agent LOOP lui-même. Aucune question utilisateur. |
| **E3** | Transcripts Gemini / Claude | Recherche de `Q001` et `Q002` | 0 fichier. |
| **E4** | Historique Git | `git log --all --oneline -S'Q001'` | 0 commit. |
| **E5** | Dossiers utilisateur | `find ~/Desktop ~/Downloads ~/Documents -maxdepth 3 -type f \( -iname '*question*' -o -iname '*q001*' -o -iname '*clarification*' \)` | Plusieurs fichiers homonymes sans rapport ; un seul candidat : `questions_reponses_LOOP_146.md` (voir E7). |
| **E6** | Stash et objets Git non atteignables | `git stash list` ; `git fsck --unreachable --no-reflogs` ; `git grep 'Q001' <commit>` sur les 21 commits non atteignables | 0 stash ; 21 commits non atteignables ; 0 contenant `Q001`. |
| **E7** | Candidat `~/Downloads/Nouveau dossier contenant des éléments 5/questions_reponses_LOOP_146.md` | `stat` + lecture + désignation utilisateur | Créé le **2026-10-29 à 21:11:06** par un agent concurrent. 926 lignes. **Désigné par l'utilisateur comme source de référence le 2026-10-29.** Le fichier déclare : Q001–Q131 = `SOURCE À RÉCUPÉRER` (131), Q132–Q145 = réponses confirmées avec formulations reconstruites à vérifier (14), Q146 = `À CONFIRMER` (1). Utilisé **uniquement** pour Q132–Q145 ; les 131 autres lignes restent sans source. |
| **E8** | Deuxième version du registre et documents candidats | `diff` octet par octet ; `pdftotext` sur 5 PDF ; `textutil` sur 2 DOCX ; `rg` sur 2 Markdown | **2026-10-30.** `questions_reponses_LOOP_146 (1).md` est **identique octet pour octet** à E7 (42 788 octets, 926 lignes, `diff` vide) : aucune source Q001–Q131 supplémentaire. Les 5 PDF (`Cahier_du_charge (1).pdf`, `PFE.pdf`, `PFE (1).pdf`, `المنظومة الخاصة يالتسجيل عن بعد.pdf`, `maryemm…pdf`), les 2 DOCX (`document-descriptif.docx`, `ReadMe (1).docx`) et les 2 Markdown (`cahier-des-charges-final.md`, `product-backlog.md`) contiennent **0 occurrence** de `Qxxx`, `146 questions` ou `questionnaire`. Seules occurrences restantes : le registre E7 et les fichiers `docs/loop/global-146/` produits par un agent. |

### Conclusion des preuves

Aucune source accessible ne contient les questions Q001–Q131, leurs options ou des réponses confirmées. Le seul fichier candidat (E7) est un artefact d'agent produit pendant le LOOP ; l'utilisateur l'a désigné pour les **réponses Q132–Q145**, avec réserve explicite sur la formulation des questions. Il ne contient aucune question Q001–Q131 et aucune décision Q146. La recherche élargie E8 (deuxième copie identique, PDF, DOCX, Markdown) ne fournit aucun élément nouveau : les 131 questions restent `SOURCE_MISSING`. Utiliser ce fichier au-delà de Q132–Q145 reviendrait à blanchir une invention par un fichier généré.

---

## 2. BILAN DES 146 IDENTIFIANTS

| Statut de confirmation | Nombre |
| :--- | ---: |
| `SOURCE MANQUANTE` (Q001–Q131) | **131** |
| `CONFIRMÉE — réponse confirmée, formulation à vérifier` (Q132–Q145) | **14** |
| `À CONFIRMER` (Q146) | **1** |
| **Total** | **146** |

| Statut d'implémentation | Nombre |
| :--- | ---: |
| `BLOQUÉE` — aucune règle (Q001–Q131) | **131** |
| `PASS_WITH_RESERVATIONS` — service validé, réserve de formulation / reachability (Q132–Q145) | **14** |
| `SANS VERDICT FINAL` — option non confirmée (Q146) | **1** |

Aucune question n'est déclarée confirmée sans réserve. Aucune règle métier n'est déduite hors des réponses Q132–Q145 désignées. Aucun test métier issu des Q001–Q131 n'est déclaré exécuté.

---

## 3. MATRICE DÉTAILLÉE Q001–Q146

Les colonnes reprennent les champs exigés au §2 du mandat. Pour **Q001–Q131**, chaque ligne signifie : « aucune formulation, aucune réponse, aucune règle, aucune source, aucun composant et aucun test ne peuvent être établis sans invention ». Pour **Q132–Q145**, la réponse est celle de la source désignée ; la formulation de la question reste reconstruite. Pour **Q146**, aucune réponse finale n'existe : seuls les comportements indépendants de l'option sont décrits.

| ID | Question / réponse disponibles | Statut de confirmation | Règle métier | Source / décision | Comportement attendu | Composants | Tests nécessaires | Statut d'implémentation | Preuve de validation | Blocages / dépendances |
| :---: | :--- | :---: | :--- | :--- | :--- | :--- | :--- | :---: | :--- | :--- |
| **Q001** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q002** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q003** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q004** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q005** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q006** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q007** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q008** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q009** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q010** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q011** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q012** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q013** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q014** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q015** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q016** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q017** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q018** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q019** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q020** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q021** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q022** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q023** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q024** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q025** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q026** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q027** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q028** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q029** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q030** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q031** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q032** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q033** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q034** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q035** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q036** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q037** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q038** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q039** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q040** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q041** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q042** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q043** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q044** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q045** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q046** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q047** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q048** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q049** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q050** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q051** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q052** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q053** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q054** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q055** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q056** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q057** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q058** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q059** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q060** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q061** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q062** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q063** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q064** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q065** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q066** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q067** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q068** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q069** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q070** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q071** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q072** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q073** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q074** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q075** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q076** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q077** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q078** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q079** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q080** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q081** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q082** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q083** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q084** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q085** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q086** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q087** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q088** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q089** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q090** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q091** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q092** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q093** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q094** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q095** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q096** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q097** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q098** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q099** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q100** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q101** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q102** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q103** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q104** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q105** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q106** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q107** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q108** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q109** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q110** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q111** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q112** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q113** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q114** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q115** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q116** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q117** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q118** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q119** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q120** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q121** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q122** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q123** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q124** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q125** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q126** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q127** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q128** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q129** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q130** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q131** | introuvable / aucune | `SOURCE MANQUANTE` | aucune | E1–E8 | aucun comportement défini | aucun | aucun | `BLOQUÉE` | absence documentée | corpus Q001–Q131 absent |
| **Q132** | reconstruite / confirmée | `CONFIRMÉE — formulation à vérifier` | `RECOURS-001` : enregistrer la contestation, orienter vers la voie officielle, ne pas modifier le statut automatiquement | E7 + désignation utilisateur | contestation conservée avec motif et pièce éventuelle ; statut de remise inchangé ; orientation sans procédure inventée | `NotificationService::enregistrerContestationRemise`, `NotificationController::contesterEtudiant`, `POST etudiant/notifications/{id}/contester`, vue notifications | `NotificationPreuveRemiseTest`, `Q132ContestationRemiseHttpTest`, `Loop146ConsultationEtIntegrationTest` | `PASS_WITH_RESERVATIONS` | 102 tests / 489 assertions au total (dont 9 HTTP Q132) ; `pint` PASS | formulation à vérifier ; voie officielle de recours non configurée |
| **Q133** | reconstruite / confirmée | `CONFIRMÉE — formulation à vérifier` | `RECOURS-002` : conserver la décision du recours, mettre à jour le statut de remise selon ses conclusions, tracer les effets possibles | E7 + désignation utilisateur | seuls `remise_confirmee` / `remise_non_confirmee` acceptés ; référence officielle obligatoire ; décision initiale conservée | `NotificationService::appliquerRecoursRemise`, `NotificationController::appliquerRecoursAdmin`, `POST admin/notifications/{notification}/recours` | `NotificationPreuveRemiseTest`, `Q133Q134NotificationRecoursAdminHttpTest` | `PASS_WITH_RESERVATIONS` | 9 tests PASS (5 service + 4 HTTP) ; 403 pour rôle lecture seule | formulation à vérifier ; aucune voie de recours institutionnelle configurée |
| **Q134** | reconstruite / confirmée | `CONFIRMÉE — formulation à vérifier` | `RECOURS-003` : appliquer la conclusion « remise », lister les conséquences possibles, ne pas modifier les dossiers associés sans fondement officiel | E7 + désignation utilisateur | conséquences retournées à titre informatif ; aucune mutation de dossier associé | `NotificationService::appliquerRecoursRemise` via la route admin mutualisée | `NotificationPreuveRemiseTest`, `Q133Q134NotificationRecoursAdminHttpTest` | `PASS_WITH_RESERVATIONS` | 1 test dédié + preuve HTTP mutualisée | formulation à vérifier ; fondement officiel absent pour les conséquences |
| **Q135** | reconstruite / confirmée | `CONFIRMÉE — formulation à vérifier` | `DELAI-001` : conserver le délai enregistré, signaler le risque, ne pas le modifier avant vérification d'un fondement officiel | E7 + désignation utilisateur | `depassement_constate`, `regle_officielle_identifiee=false`, `verification_requise=true`, échéance initiale intacte | `EcheanceDerogationService::constaterDepassementEcheance`, `Admin\DerogationGestionController::constaterDepassement`, `POST admin/derogations/.../constater-depassement` | `EcheanceDerogationServiceTest`, `Loop146QuestionsComplementairesTest`, `AdminDerogationGestionHttpTest` | `PASS_WITH_RESERVATIONS` | 6 tests + 1 HTTP mutualisé ; audit sans PII | formulation à vérifier ; aucun texte officiel de délai |
| **Q136** | reconstruite / confirmée | `CONFIRMÉE — formulation à vérifier` | `DELAI-002` : enregistrer le dépassement, conserver le délai initial, n'appliquer que les conséquences expressément prévues | E7 + désignation utilisateur | `consequences_appliquees=false` par défaut ; aucune forclusion automatique ; référence officielle requise pour qualifier une conséquence | `EcheanceDerogationService::constaterDepassementEcheance` + route admin mutualisée | `EcheanceDerogationServiceTest`, `Loop146QuestionsComplementairesTest`, `AdminDerogationGestionHttpTest` | `PASS_WITH_RESERVATIONS` | 3 tests + 1 HTTP mutualisé | formulation à vérifier ; conséquences officielles non fournies |
| **Q137** | reconstruite / confirmée | `CONFIRMÉE — formulation à vérifier` | `DEROG-001` : conserver demande et preuves, rechercher le mécanisme officiel, transmettre si nécessaire ; ne changer délai/conséquences qu'après décision autorisée et traçable | E7 + désignation utilisateur | dépôt conservé ; `mecanisme_officiel_identifie=false` par défaut ; statut `en_attente_etablissement_competence` ; délai non modifié | `EcheanceDerogationService::deposerDemandeDerogation`, `Etudiant\DerogationController::deposer`, `POST etudiant/derogations` | `EcheanceDerogationServiceTest`, `Loop146QuestionsComplementairesTest`, `Q137DerogationDepotHttpTest` | `PASS_WITH_RESERVATIONS` | 7 tests PASS (3 service/consultation + 4 HTTP) | formulation à vérifier ; mécanisme officiel absent |
| **Q138** | reconstruite / confirmée | `CONFIRMÉE — formulation à vérifier` | `DEROG-002` : conserver demande et preuves, documenter l'incertitude, rechercher une clarification, ne décider qu'après établissement de la compétence | E7 + désignation utilisateur | autorité hors liste = compétence non établie ; `statuerDerogation` fail-closed ; statut et délai préservés | `EcheanceDerogationService::deposerDemandeDerogation`, `::statuerDerogation`, route `POST admin/derogations/.../statuer` | `EcheanceDerogationServiceTest`, `Loop146QuestionsComplementairesTest`, `AdminDerogationGestionHttpTest` | `PASS_WITH_RESERVATIONS` | 5 tests PASS (4 service + 1 HTTP) | formulation à vérifier ; autorités compétentes non configurées |
| **Q139** | reconstruite / confirmée | `CONFIRMÉE — formulation à vérifier` | `DEROG-003` / `DELAI-003` : enregistrer l'absence de réponse, relancer uniquement via les mécanismes institutionnels existants, ne pas inventer de délai ni d'escalade | E7 + désignation utilisateur | canal obligatoire et configuré ; clé d'idempotence ; aucun délai généré | `EcheanceDerogationService::relancerDemandeDerogation`, route `POST admin/derogations/.../relancer` | `EcheanceDerogationServiceTest`, `Loop146QuestionsComplementairesTest`, `AdminDerogationGestionHttpTest` | `PASS_WITH_RESERVATIONS` | 6 tests PASS (5 service + 1 HTTP) ; déduplication sur audit canonique | formulation à vérifier ; canaux de relance non configurés |
| **Q140** | reconstruite / confirmée | `CONFIRMÉE — formulation à vérifier` | `DEROG-004` / `DELAI-004` : maintenir le dossier en attente, conserver la preuve de relance, ne changer ni délai ni statut sans fondement, documenter l'absence d'autre voie | E7 + désignation utilisateur | statut d'instruction inchangé ; `delai_promis=null` ; `decision_prejugee=false` | `EcheanceDerogationService::informerAttenteProlongee` + routes mutualisées `relancer` / `informer-attente` | `EcheanceDerogationServiceTest`, `Loop146QuestionsComplementairesTest`, `AdminDerogationGestionHttpTest` | `PASS_WITH_RESERVATIONS` | 3 tests + preuve HTTP mutualisée | formulation à vérifier ; voies de clarification non configurées |
| **Q141** | reconstruite / confirmée | `CONFIRMÉE — formulation à vérifier` | `NOTIF-001` : information factuelle et transparente sur la clarification en attente, les démarches et l'absence de réponse ; aucun délai promis, aucune décision préjugée | E7 + désignation utilisateur | message factuel ; aucun engagement de délai ; aucun préjugé ; notification centralisée au bon destinataire ; anti-doublon | `EcheanceDerogationService::informerAttenteProlongee`, `NotificationService::notifierEtudiant`, route `POST admin/derogations/.../informer-attente` | `EcheanceDerogationServiceTest`, `Q141InformationAttenteNotificationTest`, `Loop146QuestionsComplementairesTest`, `AdminDerogationGestionHttpTest` | `PASS_WITH_RESERVATIONS` | 9 tests PASS (3 service + 5 notification + 1 HTTP) | formulation à vérifier ; circuit éditorial du template non institutionnalisé |
| **Q142** | reconstruite / confirmée | `CONFIRMÉE — formulation à vérifier` | `RECOURS-004` : enregistrer la contestation, expliquer l'état du dossier, rechercher les voies officielles ; ne pas inventer de procédure, ne pas modifier le statut | E7 + désignation utilisateur | contestation tracée et idempotente ; `procedure_inventee=false` ; statut préservé ; session serveur et anti-IDOR | `EcheanceDerogationService::enregistrerContestationAttente`, `Etudiant\DerogationController::contesterAttente`, `POST etudiant/derogations/contester-attente` | `EcheanceDerogationServiceTest`, `Loop146QuestionsComplementairesTest`, `Q142ContestationAttenteProlongeeHttpTest`, `Loop146ConsultationEtIntegrationTest` | `PASS_WITH_RESERVATIONS` | 12 tests PASS (6 service/consultation + 6 HTTP) | formulation à vérifier ; voies de recours non configurées |
| **Q143** | reconstruite / confirmée | `CONFIRMÉE — formulation à vérifier` | `RECOURS-005` : vérifier les sources, transmettre par la voie prévue, enregistrer la transmission, informer qu'aucun délai confirmé n'est connu ; pas de délai arbitraire, pas de décision déduite du silence | E7 + désignation utilisateur | voie configurée + référence obligatoires ; `delai_confirme/fixe/garanti=null` ; preuve de transmission enregistrée avec destinataire et horodatage | `EcheanceDerogationService::transmettrePourReexamen`, route `POST admin/derogations/.../transmettre-reexamen` | `EcheanceDerogationServiceTest`, `Loop146QuestionsComplementairesTest`, `AdminDerogationGestionHttpTest` | `PASS_WITH_RESERVATIONS` | 6 tests PASS (5 service + 1 HTTP) | formulation à vérifier ; voies de réexamen non configurées |
| **Q144** | reconstruite / confirmée | `CONFIRMÉE — formulation à vérifier` | `RECOURS-006` : conserver la décision et les motifs tels que reçus, ne pas interpréter, ne pas compléter | E7 + désignation utilisateur | texte et motifs verbatim dans l'audit ; `motifs_completes=false` ; `interpretation_supplee=false` | `EcheanceDerogationService::enregistrerDecisionInsuffisammentMotivee`, route `POST admin/derogations/.../decision-insuffisamment-motivee` | `EcheanceDerogationServiceTest`, `Loop146QuestionsComplementairesTest`, `AdminDerogationGestionHttpTest` | `PASS_WITH_RESERVATIONS` | 4 tests PASS (3 service + 1 HTTP) | formulation à vérifier ; aucun circuit de signalement configuré |
| **Q145** | reconstruite / confirmée | `CONFIRMÉE — formulation à vérifier` | `RECOURS-007` : enregistrer la réception, signaler l'ambiguïté, ne considérer ni acceptée ni refusée avant clarification | E7 + désignation utilisateur | contenu intégral + hash ; `acceptation_presumee=false` ; `refus_presume=false` ; aucun statut final | `EcheanceDerogationService::enregistrerDecisionAmbiguë`, route `POST admin/derogations/.../decision-ambigue` | `EcheanceDerogationServiceTest`, `Loop146QuestionsComplementairesTest`, `AdminDerogationGestionHttpTest` | `PASS_WITH_RESERVATIONS` | 4 tests PASS (3 service + 1 HTTP) | formulation à vérifier ; clarification non encore reçue |
| **Q146** | reconstruite / **non confirmée** | `À CONFIRMER` | aucune règle finale ; options A/B/C connues mais non choisies. Seuls les comportements indépendants de l'option sont implémentés : conserver l'original, enregistrer la clarification (référence, auteur, date), aucune mutation automatique, aucun statut final | E7 + désignation utilisateur (aucune option choisie) | `statut_final=null`, `decision_finale_appliquee=false`, `option_q146_confirmee=false`, décision initiale consultable | `EcheanceDerogationService::appliquerClarificationDecision`, route `POST admin/derogations/.../appliquer-clarification` | `EcheanceDerogationServiceTest`, `Loop146QuestionsComplementairesTest`, `Loop146ConsultationEtIntegrationTest`, `AdminDerogationGestionHttpTest` | `SANS VERDICT FINAL` | 10 tests PASS (9 service/consultation + 1 HTTP) ; aucun statut final après la route | **option A/B/C non confirmée : aucune règle finale ne peut être posée** |

---

## 4. CE QUE CE DOCUMENT NE PROUVE PAS

1. Il ne prouve pas que le comportement actuel est conforme aux Q001–Q131 : aucune attente métier n'est documentée pour ces 131 questions.
2. Il ne transforme pas le code existant en exigence : un comportement présent n'est pas une décision confirmée.
3. Il valide **Q132–Q145 au niveau de la règle, du service et de la surface HTTP protégée**, avec réserve de formulation et de compétence institutionnelle ; Q140 reste couverte par la route mutualisée `informer-attente`. Il ne valide ni les textes institutionnels absents ni le périmètre facultaire.
4. **Q146 n'est pas validée** : seuls les comportements indépendants de l'option A/B/C sont couverts.
5. Le lot `S-SEC-01` (dérogation auto-déclarée) est indépendant et documenté dans `LOOP-146-SECURITE.md` et `LOOP-146-PLAN-IMPLEMENTATION.md`.
6. Il ne remplace pas la fourniture du corpus original par l'utilisateur.

---

## 5. CONDITIONS DE DÉBLOCAGE

Pour transformer cet inventaire en matrice exploitable, il faut fournir :

1. Le texte original des 131 questions Q001–Q131 (formulation et options).
2. La vérification des formulations reconstruites Q132–Q145 contre le questionnaire original.
3. La décision finale Q146 (option A, B ou C) — aucune option ne peut être choisie par l'agent.
4. Les textes institutionnels applicables : délais de recours et de forclusion, autorité compétente pour les dérogations, valeur du silence administratif, modalités de notification réputée acquise.
5. Le tableau de délégation de signature.

Sans ces éléments, toute ligne Q001–Q131 marquée `CONFIRMÉE` serait une fabrication, et toute ligne Q146 marquée d'un statut final serait une décision inventée. Le LOOP s'y refuse.

---

## 6. FICHES D'EXÉCUTION DÉTAILLÉES

Les fiches par question (critères d'acceptation, tests, preuves, réserves) sont dans `LOOP-146-FICHES-Q132-Q146.md` pour la synthèse et dans `questions/Q001.md` à `questions/Q146.md` + `questions/README.md` pour le registre individuel des 146 phases.

---
