# RÉFÉRENTIEL DES RÈGLES MÉTIER CONSOLIDÉES — LOOP 146

**Référence :** `02-regles-metier.md`  
**Application :** UniRegistre  
**Version :** 2.0  
**Date :** 2026-10-29  

---

## 1. Principes d'Ingénierie Non Négociables

1. **Non-invention :** Aucune règle métier, sanction, délai ou compétence n'est inventée sans source opposable.
2. **Découplage et non-mutation en cascade (`BUS-004`) :** La résolution d'une contestation ou dérogation n'entraîne aucune modification automatique des inscriptions, examens ou notes connexes.
3. **Immuabilité des dates initiales (`HIST-007`) :** Tout dépassement d'échéance ou régularisation conserve inaltérée l'échéance d'origine dans le journal d'audit.
4. **Fail-Closed :** Tout acte administratif d'exception sans base légale vérifiée est rejeté par `DomainException`.

---

## 2. Catalogue des Exigences Métier Atomiques

### Notifications et Preuves de Remise (`NOTIF-xxx`, `STAT-xxx`, `HIST-xxx`)
- **`NOTIF-001` :** Tout échec d'acheminement externe doit être enregistré avec statut `echec` sans exposer de PII.
- **`NOTIF-002` :** L'envoi technique externe réussi ne vaut jamais remise confirmée (`estRemiseConfirmee() === false`).
- **`NOTIF-003` :** L'utilisation d'un canal alternatif nécessite une base légale ou un consentement exprès tracé.
- **`STAT-002` :** Maintien strict du statut de non-confirmation (`remise_non_confirmee`) en l'absence d'accusé valide.
- **`STAT-003` :** La clôture administrative du suivi ne doit jamais entraîner la confirmation de remise.
- **`BUS-001` :** L'obligation de suivi demeure active même après clôture administrative unilatérale.
- **`HIST-002` :** Scellement immuable de l'ensemble des éléments de preuve et horodatages à la clôture.
- **`HIST-003` :** Enregistrement cumulatif sans écrasement de toute preuve de remise survenant a posteriori.
- **`SEC-002` :** Contrôle d'authenticité et journalisation de l'identité de l'agent vérificateur.
- **`HIST-004` :** Conservation intégrale des éléments contradictoires pour instruction juridique ultérieure.
- **`STAT-004` :** Une preuve jugée non concluante ne peut emporter validation de la remise.
- **`HIST-005` :** Traçabilité normalisée des opérations de contrôle sans persistance de PII non nécessaires.

### Refus et Recours (`BUS-xxx`, `RECOURS-xxx`)
- **`BUS-002` :** Le refus de validation d'une preuve de remise doit impérativement être motivé sous peine d'exception.
- **`STAT-005` :** Le refus d'une pièce n'emporte pas qualification juridique automatique d'absence de remise.
- **`RECOURS-001` :** Toute contestation du destinataire doit être formellement enregistrée et horodatée (`en_contestation = true`).
- **`RECOURS-002` :** Orientation vers la commission compétente sans préjuger de la décision de fond.
- **`RECOURS-003` :** La décision infirmative d'un recours valide la remise tout en archivant le refus initial.
- **`BUS-003` :** Rapprochement informatif des dossiers potentiellement affectés sans modification unilatérale.
- **`BUS-004` :** Interdiction absolue de modification automatique des dossiers académiques en cascade (`mutations_automatiques === false`).
- **`BUS-005` :** Émission d'un avertissement officiel rappelant l'obligation d'instruction manuelle individuelle.

### Délais, Échéances et Dérogations (`DELAI-xxx`, `EXC-xxx`, `AUTH-xxx`)
- **`DELAI-002` :** Le dépassement d'échéance est un constat matériel qui préserve la date cible originale.
- **`DELAI-003` :** Le retard calendaire ne produit aucune forclusion ni sanction automatique sans décision expresse.
- **`HIST-007` :** L'échéance initiale originale est une constante historique inaltérable.
- **`DELAI-004` :** Les sanctions ou déchéances applicables dépendent exclusivement du règlement des études en vigueur.
- **`EXC-001` :** Toute demande de dérogation exige un motif explicite et des pièces justificatives répertoriées.
- **`AUTH-007` :** Contrôle strict de la compétence de l'autorité signataire de la dérogation.
- **`AUTH-008` :** Rejet immédiat de toute tentative de statuer par une autorité non habilitée (`DomainException`).
- **`BUS-006` :** Le silence administratif ne produit aucun effet tacite d'acceptation ou de rejet (`inference_silence = 'aucune'`).
- **`BUS-007` :** Information transparente de l'usager sans promesse non vérifiable de délai.
- **`RECOURS-004` :** Transmission formelle de réexamen sans fixation unilatérale de délai non opposable (`delai_garanti === null`).
- **`HIST-008` :** Respect absolu du dispositif textuel sans enrichissement interprétatif a posteriori.
- **`STAT-007` :** Une décision ambiguë ne doit donner lieu à aucune interprétation favorable ou défavorable (`issue_presumee === null`).
- **`STAT-008` :** L'application d'une clarification confirmatrice de refus n'opère aucune altération en cascade.
