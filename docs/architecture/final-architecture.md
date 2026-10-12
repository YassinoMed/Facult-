# Architecture finale

```text
Blade (Tailwind v4 + Alpine)
    ↓ routes web.php (auth/admin/module/can/throttle) + api.php (Sanctum+scopes)
Controllers fins (Gate + FormRequest → service → redirect/Resource)
    ↓
Services métier (transactions, DomainException, events, audit, notifs)
    ↓
Modèles Eloquent (fillable strict, casts, relations, TRANSITIONS)
    ↓
MySQL (FK, uniques, index) + storage privé (local/private)
```

Transverse :

```text
Events → Listeners (audit sans PII, notifications batch, tâches)
Workflows (matrices TRANSITIONS par entité, pas de moteur générique)
Tasks (cle_unique idempotente) ← qualité, SLA, impayés, sans-groupe
Automation (scheduler : 12 jobs) → Notifications (priorités, purge)
Audit (HistoriqueAcademique immuable + source_systeme)
DataExchange (preview→confirm→transaction, 16 entités)
Observability (ready, X-Request-ID, supervision + cooldown, /admin/system)
```

Modules : Attendance, StudentDossiers, OnlineRequests, Referentiel,
Enseignement, Evaluation, Announcements, Calendar, Tasks, Schedule,
Finances. Cœur : population, archives, API, GED fédérée, BI, qualité,
finances étudiantes, cockpit, admin.

Principes : une source de vérité par donnée, serveur décide, permissions +
périmètre faculté, montants en centimes, documents jamais en public,
aucune suppression académique/financière irréversible sans circuit.
