import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';

window.Alpine = Alpine;
window.Livewire = Livewire;

/**
 * Formulaire public d'inscription — pré-remplissage automatique par CIN
 * et scan CIN/Passeport + OCR (cf. docs/IDENTITY-SCAN.md).
 *
 * Exigences de sécurité (§2/§3/§5/§12/§19) :
 * - POST + CSRF uniquement : aucun identifiant n'apparaît dans une URL ;
 * - scan : upload UNIQUE (pas de requête par caractère), réponses
 *   whitelist — le mot de passe et son hash ne quittent jamais le
 *   serveur, les champs password ne sont JAMAIS pré-remplis ;
 * - l'OCR n'est pas une preuve : les champs restent tous modifiables
 *   par l'utilisateur avant validation finale (§9) ;
 * - document temporaire : le serveur supprime le fichier après OCR (§13).
 */
Alpine.data('inscriptionForm', (sexeInitial = '', checklistsInitiales = {}, embarque = false, prefixeId = '') => ({
    submitting: false,
    /** Mode embarqué (modal d'accueil) : soumission fetch, pas de brouillon. */
    embarque: !!embarque,
    /** Préfixe des identifiants en collision avec la page hôte (canonique : vide). */
    prefixeId: prefixeId || '',
    /** Résultat de la soumission embarquée (panneaux dédiés). */
    embarqueEnvoi: false,
    embarqueErreurs: {},
    embarqueMessage: '',
    embarqueSucces: null,
    sexe: sexeInitial,
    /** Catégorie de parcours adaptatif (LOOP 02) : premiere | transfert | reinscription */
    categorie: 'premiere',
    /** Checklists documentaires par type (LOOP 04) */
    checklists: checklistsInitiales || {},
    /** ── Wizard multi-étapes (§5, B4) : 1 Identification · 2 Identité · 3 Coordonnées · 4 Formation · 5 Documents · 6 Confirmation. */
    etape: 1,
    totalEtapes: 6,
    confirmation: false,
    /** Récapitulatif construit à l'entrée de l'étape 5 (lecture DOM, aucune donnée sensible). */
    recap: { identite: [], coordonnees: [], formation: [] },
    /** Bilan diagnostic pré-soumission (LOOP 09) : champs manquants vs avertissements. */
    diagnostic: { erreursBloquantes: [], avertissements: [], pretPourEnvoi: true },
    /** Champs actuellement marqués comme pré-remplis (style visuel dédié). */
    preliles: [],
    /** État de la recherche : inactif | attente_date | encours | trouve | absent | limite. */
    cinRecherche: { etat: 'inactif', cin: '', date: '' },

    /** ── Scan document d'identité (§20) ── */
    scan: {
        type: 'cin',        // cin | passeport
        etat: 'inactif',    // inactif | encours | termine | erreur | limite
        message: '',
        nomFichier: '',
        resultat: null,     // payload serveur (data, confidence, seuils, divergences…)
    },

    _minuterie: null,
    _controleur: null,

    /** Whitelist client alignée sur StudentLookupService::donneesPrefill(). */
    champsPrefill: [
        'nom', 'prenom', 'email', 'telephone', 'date_naissance',
        'adresse', 'ville', 'code_postal', 'faculte_id', 'programme_id', 'niveau',
    ],

    /** Message d'orientation académique interactif (LOOP 08) */
    orientationInfo: { visible: false, type: 'info', icone: 'ℹ', titre: '', message: '' },

    /** Résolution d'identifiant avec préfixe d'instance : préfixé d'abord,
     *  héritage ensuite. Canonique inchangé (préfixe vide = appel direct). */
    champ(id) {
        if (this.prefixeId) {
            const prefixe = document.getElementById(this.prefixeId + id);
            if (prefixe) return prefixe;
        }
        return document.getElementById(id);
    },

    /** L'utilisateur retouche un champ : il n'est plus marqué pré-rempli. */
    champModifie(champ) {
        this.preliles = this.preliles.filter((c) => c !== champ);
        this.planifierSauvegardeBrouillon();

        if (champ === 'cin') {
            this.cinRecherche = { etat: 'inactif', cin: '', date: '' };
            clearTimeout(this._minuterie);
            this._controleur?.abort();
        }
    },

    /** Debounce : la recherche ne part jamais à chaque caractère (§3). */
    planifierRechercheCin() {
        clearTimeout(this._minuterie);
        this._minuterie = setTimeout(() => this.rechercherCin(), 600);
    },

    /**
     * Recherche sécurisée du CIN + date de naissance auprès du serveur puis
     * pré-remplissage. Second facteur obligatoire (§P0-4) : le CIN seul
     * (identifiant semi-public) ne suffit jamais à obtenir des données.
     * Aucune écriture n'est effectuée : l'inscription finale reste soumise
     * à la validation stricte côté serveur (unicité CIN, anti-doublon).
     */
    async rechercherCin() {
        const champCin = this.champ('cin');
        const champDate = this.champ('date_naissance');
        const cin = (champCin?.value || '').trim().toUpperCase();
        const dateNaissance = (champDate?.value || '').trim();

        // Format minimal côté client : ne jamais interroger le serveur
        // pour un CIN trop court ou invalide (§8 — aucune donnée personnelle
        // ne s'affiche avant validation suffisante).
        if (!/^[A-Z0-9-]{5,30}$/.test(cin)) {
            this.cinRecherche = { etat: 'inactif', cin: '', date: '' };
            return;
        }

        // Second facteur manquant : pas de requête du tout.
        if (dateNaissance === '') {
            this.cinRecherche = { etat: 'attente_date', cin, date: '' };
            return;
        }

        // Garde anti-redondance : ce couple CIN + date est déjà résolu.
        if (
            this.cinRecherche.cin === cin &&
            this.cinRecherche.date === dateNaissance &&
            (this.cinRecherche.etat === 'trouve' || this.cinRecherche.etat === 'absent')
        ) {
            return;
        }

        this._controleur?.abort();
        this._controleur = new AbortController();
        this.cinRecherche = { etat: 'encours', cin, date: dateNaissance };

        try {
            const reponse = await fetch('/inscription/verifier-cin', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN':
                        document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify({ cin, date_naissance: dateNaissance }),
                signal: this._controleur.signal,
            });

            if (reponse.status === 429) {
                this.cinRecherche = { etat: 'limite', cin, date: dateNaissance };
                return;
            }
            if (!reponse.ok) {
                this.cinRecherche = { etat: 'inactif', cin: '', date: '' };
                return;
            }

            const donnees = await reponse.json();

            // Anti-course : le CIN ou la date a changé pendant la requête.
            if ((champCin?.value || '').trim().toUpperCase() !== cin) return;
            if ((champDate?.value || '').trim() !== dateNaissance) return;

            if (donnees.exists === true && donnees.etudiant) {
                this.appliquerPrefill(donnees.etudiant);
                this.cinRecherche = { etat: 'trouve', cin, date: dateNaissance };
            } else {
                this.viderChampsPreremplis();
                this.cinRecherche = { etat: 'absent', cin, date: dateNaissance };
            }
        } catch (erreur) {
            if (erreur.name !== 'AbortError') {
                this.cinRecherche = { etat: 'inactif', cin: '', date: '' };
            }
        }
    },

    /**
     * Pré-remplit UNIQUEMENT la whitelist de champs du formulaire.
     * Jamais password / password_confirmation / hash (§2/§5).
     */
    appliquerPrefill(etudiant) {
        const marques = [];

        this.champsPrefill.forEach((champ) => {
            const element = this.champ(champ);
            const valeur = etudiant[champ];
            if (element && valeur !== null && valeur !== undefined && valeur !== '') {
                element.value = valeur;
                marques.push(champ);
            }
        });

        if (etudiant.sexe === 'M' || etudiant.sexe === 'F') {
            this.sexe = etudiant.sexe;
            marques.push('sexe');
        }

        // Le CIN (normalisé par le serveur) reste visuellement marqué.
        this.preliles = [...new Set(['cin', ...marques])];
    },

    /**
     * CIN introuvable : on ne vide QUE les champs encore marqués comme
     * pré-remplis (jamais ceux saisis manuellement par l'utilisateur,
     * jamais le CIN qu'il vient de taper).
     */
    viderChampsPreremplis() {
        this.preliles
            .filter((champ) => champ !== 'cin')
            .forEach((champ) => {
                if (champ === 'sexe') {
                    this.sexe = '';
                } else {
                    const element = this.champ(champ);
                    if (element) element.value = '';
                }
            });
        this.preliles = [];
    },

    /* ── Scan CIN / Passeport + OCR (§20) ─────────────────────── */

    /** Choix du type de document (étape 1). */
    choisirTypeDocument(type) {
        if (this.scan.etat === 'encours') return;
        this.scan.type = type;
        this.scan.etat = 'inactif';
        this.scan.resultat = null;
        this.scan.message = '';
    },

    /** Déclenche le sélecteur de fichiers (import ou caméra mobile). */
    declencherChoixFichier(entree) {
        if (this.scan.etat === 'encours') return;
        document.getElementById(entree)?.click();
    },

    /**
     * Téléversement UNIQUE du document (étapes 2-3) : garde-fous client
     * (extension/taille) puis POST FormData. Le serveur re-valide TOUT
     * en profondeur (MIME réel, signature, polyglot) — lui seul fait
     * autorité. Le fichier est supprimé côté serveur après l'OCR (§13).
     */
    async televerserDocumentScan(evenement) {
        const fichier = evenement.target?.files?.[0];
        evenement.target.value = '';

        if (!fichier) return;

        const tailleMax = parseInt(
            evenement.target.dataset?.tailleMax || '8388608', 10,
        );

        // Garde-fous client : uniquement pour éviter un upload inutile
        if (!['image/jpeg', 'image/png', 'application/pdf'].includes(fichier.type)) {
            this.scan = { ...this.scan, etat: 'erreur', message: 'Formats acceptés : JPG, JPEG, PNG, PDF.', resultat: null };
            return;
        }
        if (fichier.size > tailleMax) {
            this.scan = {
                ...this.scan,
                etat: 'erreur',
                message: `Document trop volumineux (max ${Math.round(tailleMax / 1048576)} Mo).`,
                resultat: null,
            };
            return;
        }

        const corps = new FormData();
        corps.append('document_type', this.scan.type);
        corps.append('document', fichier);

        this.scan = {
            ...this.scan,
            etat: 'encours',
            message: '',
            nomFichier: fichier.name,
            resultat: null,
        };

        try {
            const reponse = await fetch('/inscription/identity/scan', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN':
                        document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: corps,
            });

            if (reponse.status === 429) {
                this.scan = {
                    ...this.scan,
                    etat: 'limite',
                    message: 'Trop de scans effectués. Patientez une minute avant de réessayer.',
                };
                return;
            }

            const donnees = await reponse.json();

            if (!reponse.ok) {
                const message = donnees?.errors?.document?.[0]
                    ?? donnees?.errors?.document_type?.[0]
                    ?? 'Le document n\'a pas pu être analysé. Vérifiez sa qualité et réessayez.';
                this.scan = { ...this.scan, etat: 'erreur', message };
                return;
            }

            this.scan = { ...this.scan, etat: 'termine', resultat: donnees, message: '' };
        } catch (erreur) {
            this.scan = {
                ...this.scan,
                etat: 'erreur',
                message: 'L\'analyse a échoué (connexion interrompue). Réessayez.',
            };
        }
    },

    /**
     * Applique le résultat du scan au formulaire (étape 6) :
     * - dossier EXISTANT → les données BD validées font foi (§10/§11) ;
     * - sinon → données OCR normalisées (§9), TOUS les champs restent
     *   modifiables — l'OCR n'est jamais une preuve d'identité.
     * Le mot de passe n'est JAMAIS pré-rempli (§12).
     */
    appliquerScan() {
        const resultat = this.scan.resultat;

        if (!resultat) return;

        if (resultat.existing_student === true && resultat.etudiant) {
            // Le dossier enregistré prime sur l'OCR — mêmes avertissements
            // que la recherche CIN (anti-doublon métier).
            this.appliquerPrefill(resultat.etudiant);
            this.cinRecherche = {
                etat: 'trouve',
                cin: resultat.etudiant.cin || '',
                date: resultat.etudiant.date_naissance || '',
            };
        } else {
            const donnees = resultat.data || {};
            const marques = [];

            [
                ['cin', donnees.cin],
                ['nom', donnees.nom],
                ['prenom', donnees.prenom],
                ['date_naissance', donnees.date_naissance],
            ].forEach(([champ, valeur]) => {
                const element = this.champ(champ);
                if (element && valeur) {
                    element.value = valeur;
                    marques.push(champ);
                }
            });

            if (donnees.sexe === 'M' || donnees.sexe === 'F') {
                this.sexe = donnees.sexe;
                marques.push('sexe');
            }

            this.preliles = [...new Set(marques)];
            this.cinRecherche = { etat: 'inactif', cin: '', date: '' };
        }

        // L'utilisateur doit vérifier le bloc identité : on l'y amène
        // (page canonique ; en mode embarqué la modale reste en place).
        if (!this.embarque) {
            document.getElementById('section-identite')
                ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    },

    /**
     * ── Wizard multi-étapes (§5/§9) ──────────────────────────────
     * Navigation client uniquement : le serveur reste l'autorité
     * (validation InscriptionRequest + unicités BD). Sans JS, toutes
     * les étapes restent visibles et le formulaire se soumet en une fois.
     */
    allerEtape(numero) {
        this.etape = Math.min(Math.max(1, parseInt(numero, 10) || 1), this.totalEtapes);
        if (this.etape === this.totalEtapes) this.preparerRecap();
        this.defilerVersFormulaire();
    },

    etapeSuivante() {
        if (!this.validerEtapeCourante()) return;
        this.allerEtape(this.etape + 1);
    },

    etapePrecedente() {
        this.allerEtape(this.etape - 1);
    },

    /** Adaptation dynamique selon la catégorie (LOOP 02 & 04). */
    async changerCategorie(nouvelle) {
        this.categorie = nouvelle;
        const niveauEl = this.champ('niveau');
        if (niveauEl && nouvelle === 'premiere' && (!niveauEl.value || !niveauEl.value.startsWith('L1'))) {
            niveauEl.value = 'L1';
        }
        if (!this.checklists[nouvelle]) {
            try {
                const res = await fetch(`/inscription/pieces-requises?type=${encodeURIComponent(nouvelle)}`, {
                    headers: { Accept: 'application/json' },
                });
                if (res.ok) {
                    const data = await res.json();
                    if (data.pieces) {
                        this.checklists[nouvelle] = data.pieces;
                    }
                }
            } catch (e) {}
        }
        this.preparerRecap();
        this.planifierSauvegardeBrouillon();
    },

    libelleCategorie() {
        return {
            premiere: 'Première inscription (Licence)',
            transfert: 'Transfert / Passerelle',
            reinscription: 'Réinscription universitaire',
        }[this.categorie] || 'Inscription universitaire';
    },

    titreEtapeCourante() {
        return {
            1: 'Identification initiale (MENS)',
            2: 'Identité & État civil',
            3: 'Coordonnées & Contact',
            4: 'Formation & Parcours',
            5: 'Documents à préparer',
            6: 'Récapitulatif & Confirmation',
        }[this.etape] || `Étape ${this.etape}`;
    },

    prochaineAction() {
        switch (this.etape) {
            case 1:
                return 'Renseignez votre CIN et date de naissance';
            case 2:
                return 'Vérifiez vos données d’identité et état civil';
            case 3:
                return 'Indiquez votre email et vos coordonnées de contact';
            case 4:
                return 'Sélectionnez votre parcours, votre faculté et votre niveau';
            case 5:
                return 'Prenez connaissance des pièces justificatives attendues';
            case 6:
                return 'Configurez votre mot de passe et validez votre dossier';
            default:
                return 'Complétez les informations requises';
        }
    },

    statutEtape(num) {
        if (this.etape > num) return 'complete';
        if (this.etape === num) return 'active';
        return 'a_venir';
    },

    /** Contrôle client minimal (confort uniquement) : les requis de l'étape courante. */
    validerEtapeCourante() {
        const zone = document.querySelector(`[data-etape-contenu="${this.etape}"]`);
        if (!zone) return true;
        const invalide = zone.querySelector('input:invalid, select:invalid, textarea:invalid');
        if (invalide) {
            invalide.reportValidity();
            invalide.focus({ preventScroll: true });
            invalide.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return false;
        }
        return true;
    },

    defilerVersFormulaire() {
        if (this.embarque) return; // modale : position stable, aucun défilement
        document.getElementById('inscription')
            ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    },

    /** Libellé d'option sélectionnée (récapitulatif). */
    libelleOption(id) {
        const el = this.champ(id);
        if (!el) return '—';
        if (el.tagName === 'SELECT') return el.options[el.selectedIndex]?.text?.trim() || '—';
        return (el.value || '').trim() || '—';
    },

    /** Construit le récapitulatif de l'étape 5 (§10). */
    preparerRecap() {
        const val = (id) => (this.champ(id)?.value || '').trim() || '—';
        this.recap = {
            identite: [
                ['CIN', val('cin')],
                ['Nom', val('nom')],
                ['Prénom', val('prenom')],
                ['Date de naissance', val('date_naissance')],
                ['Sexe', this.sexe === 'M' ? 'Masculin' : (this.sexe === 'F' ? 'Féminin' : '—')],
            ],
            coordonnees: [
                ['Email', val('email')],
                ['Téléphone', val('telephone')],
                ['Ville', val('ville')],
                ['Code postal', val('code_postal')],
                ['Adresse', val('adresse')],
            ],
            formation: [
                ['Catégorie de parcours', this.libelleCategorie()],
                ['Année du bac', this.libelleOption('annee_bac')],
                ['Mention', this.libelleOption('mention_bac')],
                ['Faculté', this.libelleOption('faculte_id')],
                ['Parcours (LMD)', this.libelleOption('programme_id')],
                ['Niveau', this.libelleOption('niveau')],
                ['Année universitaire', this.libelleOption('annee_academique')],
            ],
        };
        this.diagnostic = this.diagnostiquerDossier();
    },

    /**
     * Diagnostic d'exhaustivité et de conformité pré-soumission (LOOP 09) :
     * Distingue les exigences bloquantes des avertissements informatifs.
     */
    diagnostiquerDossier() {
        const erreursBloquantes = [];
        const avertissements = [];
        const val = (id) => (document.getElementById(id)?.value || '').trim();

        // 1. Identité (Étape 2)
        if (!val('cin')) {
            erreursBloquantes.push({ etape: 2, champ: 'CIN', message: 'Numéro de CIN obligatoire' });
        }
        if (!val('nom') || !val('prenom')) {
            erreursBloquantes.push({ etape: 2, champ: 'Nom & Prénom', message: 'Identité d’état civil incomplète' });
        }
        if (!val('date_naissance')) {
            erreursBloquantes.push({ etape: 2, champ: 'Date de naissance', message: 'Date de naissance manquante' });
        }
        if (!this.sexe) {
            erreursBloquantes.push({ etape: 2, champ: 'Sexe', message: 'Civilité / sexe non renseigné' });
        }

        // 2. Coordonnées (Étape 3)
        if (!val('email')) {
            erreursBloquantes.push({ etape: 3, champ: 'Email', message: 'Adresse email obligatoire pour le suivi' });
        }
        if (!val('telephone')) {
            erreursBloquantes.push({ etape: 3, champ: 'Téléphone', message: 'Numéro de contact obligatoire' });
        }
        if (!val('ville')) {
            erreursBloquantes.push({ etape: 3, champ: 'Ville', message: 'Ville de résidence obligatoire' });
        }

        // 3. Formation (Étape 4)
        if (!val('faculte_id')) {
            erreursBloquantes.push({ etape: 4, champ: 'Faculté', message: 'Choix de l’établissement universitaire obligatoire' });
        }
        if (!val('annee_academique')) {
            erreursBloquantes.push({ etape: 4, champ: 'Année académique', message: 'Session universitaire non sélectionnée' });
        }

        // 4. Documents (Étape 5 - avertissements non bloquants)
        const pieces = this.checklists[this.categorie] || [];
        const nbObligatoires = pieces.filter((p) => p.obligatoire).length;
        if (nbObligatoires > 0) {
            avertissements.push({
                type: 'info',
                message: `${nbObligatoires} pièce(s) justificative(s) obligatoire(s) devront être présentées pour la validation définitive de votre dossier.`
            });
        }

        return {
            erreursBloquantes,
            avertissements,
            pretPourEnvoi: erreursBloquantes.length === 0,
        };
    },

    /**
     * Ajuste l'affichage de compatibilité du parcours (LOOP 08).
     */
    ajusterOrientationParcours() {
        const programmeSelect = document.getElementById('programme_id');
        const niveauSelect = document.getElementById('niveau');
        if (!programmeSelect) return;

        const option = programmeSelect.selectedOptions[0];
        if (!option || !option.value) {
            this.orientationInfo = { visible: false, type: 'info', icone: 'ℹ', titre: '', message: '' };
            return;
        }

        const niveaux = option.dataset.niveaux ? option.dataset.niveaux.split(',').filter(Boolean) : [];
        const capacite = option.dataset.capacite ? parseInt(option.dataset.capacite, 10) : null;
        const niveauChoisi = niveauSelect?.value || 'L1';

        if (niveaux.length > 0 && !niveaux.includes(niveauChoisi)) {
            this.orientationInfo = {
                visible: true,
                type: 'bloquant',
                icone: '⚠',
                titre: 'Niveau non proposé dans ce parcours',
                message: `Ce parcours dispense les niveaux suivants : ${niveaux.join(', ')}. Veuillez adapter votre niveau d'admission.`,
            };
            return;
        }

        if (this.categorie === 'premiere' && ['L2', 'L3', 'M1', 'M2', 'D1', 'D2', 'D3'].includes(niveauChoisi)) {
            this.orientationInfo = {
                visible: true,
                type: 'avertissement',
                icone: 'ℹ',
                titre: 'Admission préalable ou passerelle requise',
                message: `Une première inscription directe en ${niveauChoisi} est conditionnée à une décision officielle d'admission ou à une procédure de transfert.`,
            };
            return;
        }

        if (capacite) {
            this.orientationInfo = {
                visible: true,
                type: 'info',
                icone: '✓',
                titre: 'Parcours ouvert',
                message: `Capacité d'accueil configurée : ${capacite} places. Les candidatures sont traitées selon l'ordre officiel.`,
            };
            return;
        }

        this.orientationInfo = { visible: false, type: 'info', icone: 'ℹ', titre: '', message: '' };
    },

    /**
     * Badge de confiance d'un champ extrait (§8) : la couleur rend le
     * niveau visible, l'utilisateur garde la main dans tous les cas.
     */
    badgeConfiance(champ) {
        const confiance = this.scan.resultat?.confidence?.[champ];

        if (confiance === undefined || confiance === null) {
            return 'bg-slate-100 text-slate-500 border-slate-200';
        }

        const seuils = this.scan.resultat?.seuils ?? { eleve: 0.9, moyen: 0.7 };

        if (confiance >= seuils.eleve) return 'bg-emerald-50 text-emerald-700 border-emerald-200';
        if (confiance >= seuils.moyen) return 'bg-amber-50 text-amber-700 border-amber-200';

        return 'bg-rose-50 text-rose-700 border-rose-200';
    },

    /** Libellé francisé des champs extraits. */
    libelleChampScan(champ) {
        return {
            cin: 'N° de document',
            nom: 'Nom',
            prenom: 'Prénom',
            date_naissance: 'Date de naissance',
            sexe: 'Sexe',
            lieu_naissance: 'Lieu de naissance',
            nationalite: 'Nationalité',
        }[champ] ?? champ;
    },

    /* ── Brouillon local (LOOP 03) ─────────────────────────────
     * Reprise après rechargement SANS compte : les champs de CONTEXTE
     * uniquement (faculté, niveau, années, mention, catégorie, ville) sont conservés dans
     * le navigateur. Sont EXCLUS par conception : CIN, date de
     * naissance, nom, prénom, email, téléphone, adresse, mots de passe et fichiers (données sensibles — jamais en
     * stockage navigateur, surtout sur appareil partagé).
     * Pas de dossier officiel créé : la soumission explicite reste
     * obligatoire. Expiration 7 jours, effacement manuel ou auto après
     * inscription réussie (page de succès). Dernier onglet gagne.
     */
    CLE_BROUILLON: 'inscription-brouillon-v1',
    DUREE_BROUILLON_MS: 7 * 24 * 3600 * 1000,
    champsBrouillon: ['faculte_id', 'programme_id', 'niveau', 'annee_academique', 'annee_bac', 'mention_bac', 'ville', 'code_postal'],
    brouillon: { etat: 'inactif', existe: false, restaure: false, dateSauvegarde: '' },
    horsLigne: typeof navigator !== 'undefined' ? !navigator.onLine : false,
    _minuterieBrouillon: null,

    init() {
        if (typeof window !== 'undefined') {
            window.addEventListener('online', () => { this.horsLigne = false; });
            window.addEventListener('offline', () => { this.horsLigne = true; });
        }
        this.restaurerBrouillon();
    },

    /** Sauvegarde différée (500 ms) après chaque modification de champ.
     *  Désactivée en mode embarqué (parcours transient, aucun stockage). */
    planifierSauvegardeBrouillon() {
        if (this.embarque) return;
        clearTimeout(this._minuterieBrouillon);
        this._minuterieBrouillon = setTimeout(() => this.sauvegarderBrouillon(), 500);
    },

    sauvegarderBrouillon() {
        const donnees = {};
        this.champsBrouillon.forEach((champ) => {
            const element = document.getElementById(champ);
            if (element && element.value !== '') donnees[champ] = element.value;
        });
        if (this.categorie) {
            donnees['_categorie'] = this.categorie;
        }

        if (Object.keys(donnees).length === 0) return;

        this.brouillon = {
            ...this.brouillon,
            etat: 'enregistrement',
        };

        try {
            const dateNow = Date.now();
            localStorage.setItem(this.CLE_BROUILLON, JSON.stringify({
                v: 1,
                t: dateNow,
                donnees,
            }));
            const heure = new Date(dateNow).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            this.brouillon = {
                etat: 'enregistre',
                existe: true,
                restaure: this.brouillon.restaure,
                dateSauvegarde: heure,
            };
        } catch (e) {
            this.brouillon = {
                ...this.brouillon,
                etat: 'echec',
            };
        }
    },

    /**
     * Restauration : uniquement les champs encore vides (placeholder),
     * jamais par-dessus une valeur serveur (`old()`) ou un défaut
     * choisi. Brouillon expiré ou illisible = ignoré silencieusement.
     * Jamais en mode embarqué (aucune lecture du stockage navigateur).
     */
    restaurerBrouillon() {
        if (this.embarque) return;
        let brut = null;
        try {
            brut = localStorage.getItem(this.CLE_BROUILLON);
        } catch (e) {
            return;
        }
        if (!brut) return;

        let brouillon = null;
        try {
            brouillon = JSON.parse(brut);
        } catch (e) {
            return;
        }
        if (!brouillon || brouillon.v !== 1 || !brouillon.donnees
            || Date.now() - (brouillon.t || 0) > this.DUREE_BROUILLON_MS) {
            try {
                localStorage.removeItem(this.CLE_BROUILLON);
            } catch (e) {}
            return;
        }

        let restaures = 0;
        if (brouillon.donnees['_categorie']) {
            this.changerCategorie(brouillon.donnees['_categorie']);
        }

        this.champsBrouillon.forEach((champ) => {
            const element = document.getElementById(champ);
            const valeur = brouillon.donnees[champ];
            if (element && element.value === '' && valeur !== undefined) {
                if (element.tagName === 'SELECT') {
                    if ([...element.options].some((o) => o.value === String(valeur))) {
                        element.value = valeur;
                        element.dispatchEvent(new Event('change', { bubbles: true }));
                        restaures += 1;
                    }
                } else {
                    element.value = valeur;
                    element.dispatchEvent(new Event('input', { bubbles: true }));
                    restaures += 1;
                }
            }
        });

        const heure = brouillon.t ? new Date(brouillon.t).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
        if (restaures > 0) {
            this.brouillon = {
                etat: 'enregistre',
                existe: true,
                restaure: true,
                dateSauvegarde: heure,
            };
        } else {
            this.brouillon = {
                etat: 'inactif',
                existe: true,
                restaure: false,
                dateSauvegarde: heure,
            };
        }
    },

    effacerBrouillon() {
        try {
            localStorage.removeItem(this.CLE_BROUILLON);
        } catch (e) {}
        this.brouillon = { etat: 'inactif', existe: false, restaure: false, dateSauvegarde: '' };
    },

    /**
     * Soumission embarquée (modal d'accueil, SANS navigation) : même
     * endpoint, mêmes validations et mêmes règles métier — seule
     * l'enveloppe de réponse change (JSON). 422 → erreurs mappées +
     * saut à l'étape fautive ; succès → panneau de confirmation.
     * Double-clic neutralisé par `submitting` (comme le canonique).
     */
    async soumettreEmbarque() {
        if (this.submitting) return;
        const form = this.$el.tagName === 'FORM' ? this.$el : this.$el.querySelector('form');
        if (!form) return;
        this.submitting = true;
        this.embarqueErreurs = {};
        this.embarqueMessage = '';
        try {
            const reponse = await fetch(form.action, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN':
                        document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: new FormData(form),
            });
            if (reponse.status === 429) {
                this.embarqueMessage = 'Trop de tentatives. Patientez une minute avant de réessayer.';
                return;
            }
            const donnees = await reponse.json().catch(() => ({}));
            if (!reponse.ok) {
                this.embarqueErreurs = donnees.errors || {};
                this.embarqueMessage = donnees.message || 'Veuillez corriger les champs indiqués.';
                this.allerEtapeEmbarquee();
                return;
            }
            this.embarqueSucces = donnees;
            const mdp = form.querySelector('[name="password"]');
            const mdp2 = form.querySelector('[name="password_confirmation"]');
            if (mdp) mdp.value = '';
            if (mdp2) mdp2.value = '';
        } catch (e) {
            this.embarqueMessage = 'Erreur réseau. Vérifiez votre connexion et réessayez.';
        } finally {
            this.submitting = false;
        }
    },

    /** Saut à l'étape du premier champ en erreur (mode embarqué). */
    allerEtapeEmbarquee() {
        const carte = {
            cin: 2, nom: 2, prenom: 2, date_naissance: 2, sexe: 2,
            email: 3, telephone: 3, adresse: 3, ville: 3, code_postal: 3,
            faculte_id: 4, programme_id: 4, niveau: 4, annee_academique: 4,
            annee_bac: 4, mention_bac: 4, periode: 4, identification: 2, eligibilite: 4,
            password: 6, password_confirmation: 6,
        };
        const premier = Object.keys(this.embarqueErreurs)[0];
        if (premier && carte[premier]) this.allerEtape(carte[premier]);
        this.$nextTick(() => {
            document.getElementById('embarque-erreurs')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            document.getElementById('embarque-erreurs')?.focus?.({ preventScroll: true });
        });
    },
}));

/**
 * Sélection multiple + barre flottante (registre étudiants) : aucune
 * écriture côté client — la soumission POST est re-validée, autorisée
 * et auditée côté serveur (bulkStatut).
 */
Alpine.data('registreBulk', () => ({
    selection: [],
    statutCible: '',
    confirmeOuvert: false,
    envoi: false,
    suppressionUrl: '',
    suppressionNom: '',
    init() {
        this.$el.querySelectorAll('input[data-bulk-id]').forEach((case_1) => {
            case_1.addEventListener('change', () => this.synchroniser());
        });
    },
    synchroniser() {
        this.selection = [...this.$el.querySelectorAll('input[data-bulk-id]:checked')]
            .map((case_1) => case_1.value);
    },
    basculerTout(actif) {
        this.$el.querySelectorAll('input[data-bulk-id]').forEach((case_1) => {
            case_1.checked = actif;
        });
        this.synchroniser();
    },
    demander(statut) {
        if (this.selection.length === 0) return;
        this.statutCible = statut;
        this.confirmeOuvert = true;
    },
    soumettre() {
        if (this.envoi || this.selection.length === 0) return;
        this.envoi = true;
        const formulaire = document.createElement('form');
        formulaire.method = 'POST';
        formulaire.action = this.$el.dataset.bulkUrl;
        const jeton = document.createElement('input');
        jeton.type = 'hidden';
        jeton.name = '_token';
        jeton.value = this.$el.dataset.csrf;
        formulaire.appendChild(jeton);
        this.selection.forEach((id) => {
            const champ = document.createElement('input');
            champ.type = 'hidden';
            champ.name = 'ids[]';
            champ.value = id;
            formulaire.appendChild(champ);
        });
        const statut = document.createElement('input');
        statut.type = 'hidden';
        statut.name = 'statut';
        statut.value = this.statutCible;
        formulaire.appendChild(statut);
        const confirmation = document.createElement('input');
        confirmation.type = 'hidden';
        confirmation.name = 'confirmation';
        confirmation.value = '1';
        formulaire.appendChild(confirmation);
        document.body.appendChild(formulaire);
        formulaire.submit();
    },
}));

/**
 * Édition en ligne (inline editing) : champ → ✓/✕ → fetch PATCH →
 * confirmation ou restauration. Données non critiques uniquement ;
 * le serveur re-valide tout (la valeur affichée ne fait jamais foi).
 */
Alpine.data('editionInline', (url, champ, initiale) => ({
    edition: false,
    valeur: initiale ?? '',
    sauvegardee: initiale ?? '',
    etat: null,
    erreur: '',
    affichage() {
        return this.sauvegardee === '' || this.sauvegardee === null ? '—' : this.sauvegardee;
    },
    annuler() {
        this.valeur = this.sauvegardee;
        this.edition = false;
        this.etat = null;
    },
    async enregistrer() {
        this.etat = null;
        try {
            const reponse = await fetch(url, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ [champ]: this.valeur === '' ? null : this.valeur }),
            });
            const donnees = await reponse.json();
            if (!reponse.ok) {
                const messages = donnees.errors
                    ? Object.values(donnees.errors).flat().join(' ')
                    : (donnees.message || 'enregistrement refusé.');
                throw new Error(messages);
            }
            this.sauvegardee = donnees.salle?.[champ] ?? this.valeur;
            this.valeur = this.sauvegardee;
            this.edition = false;
            this.etat = 'ok';
            setTimeout(() => { if (this.etat === 'ok') this.etat = null; }, 3000);
        } catch (e) {
            this.valeur = this.sauvegardee;
            this.edition = false;
            this.erreur = e.message;
            this.etat = 'ko';
        }
    },
}));

/**
 * Ordre des widgets du dashboard : ↑/↓ clavier + persistance locale
 * (préférence par navigateur). Aucune donnée métier déplacée.
 */
Alpine.data('ordreWidgets', () => ({
    perso: false,
    init() {
        try {
            const ordre = JSON.parse(localStorage.getItem('admin-dashboard-ordre') || '[]');
            if (!Array.isArray(ordre) || ordre.length === 0) return;
            const enfants = [...this.$el.querySelectorAll(':scope > [data-widget]')];
            const parId = Object.fromEntries(enfants.map((el) => [el.dataset.widget, el]));
            ordre.forEach((id) => {
                if (parId[id]) this.$el.appendChild(parId[id]);
            });
        } catch (e) { /* stockage indisponible : ordre par défaut */ }
    },
    sauvegarder() {
        const ordre = [...this.$el.querySelectorAll(':scope > [data-widget]')].map((el) => el.dataset.widget);
        try { localStorage.setItem('admin-dashboard-ordre', JSON.stringify(ordre)); } catch (e) {}
    },
    deplacer(id, delta) {
        const enfants = [...this.$el.querySelectorAll(':scope > [data-widget]')];
        const i = enfants.findIndex((el) => el.dataset.widget === id);
        const j = i + delta;
        if (i < 0 || j < 0 || j >= enfants.length) return;
        this.$el.insertBefore(enfants[i], delta < 0 ? enfants[j] : enfants[j].nextSibling);
        this.sauvegarder();
    },
}));

/**
 * Changement autonome d'adresse email (parcours public sans navigation) :
 * soumission en fetch JSON (même neutralité que le POST classique, repli
 * intact), ouverture de la popup de validation sur place, confirmation
 * sur place. Le mot de passe est effacé du DOM après chaque envoi et
 * n'est jamais réaffiché ni journalisé.
 */
Alpine.data('autonomeEmail', (config = {}) => ({
    confirme: false,
    codeOuvert: !!config.codeOuvert,
    envoi: false,
    message: '',
    erreurs: {},
    succes: '',
    succesAdresse: '',

    jetonCsrf() {
        return document.querySelector('meta[name="csrf-token"]')?.content || '';
    },

    async envoyerDemande() {
        const form = this.$refs.autonomeForm;
        if (!form || !form.reportValidity()) return;
        this.erreurs = {};
        this.message = '';
        this.envoi = true;
        try {
            const reponse = await fetch(form.action, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': this.jetonCsrf(), Accept: 'application/json' },
                body: new FormData(form),
            });
            if (reponse.status === 422) {
                const donnees = await reponse.json().catch(() => ({}));
                this.erreurs = donnees.errors || {};
                this.message = 'Veuillez corriger les champs indiqués.';
                return;
            }
            if (reponse.status === 429) {
                this.message = 'Trop de tentatives. Patientez une minute avant de réessayer.';
                return;
            }
            const donnees = await reponse.json();
            this.message = donnees.message || '';
            if (donnees.etape === 'code') {
                this.confirme = false;
                this.codeOuvert = true;
                this.$nextTick(() => document.getElementById('code_autonome')?.focus());
            }
        } catch (e) {
            this.message = 'Erreur réseau. Vérifiez votre connexion et réessayez.';
        } finally {
            const mdp = document.getElementById('autonome_password');
            if (mdp) mdp.value = '';
            this.envoi = false;
        }
    },

    async confirmerCode() {
        const form = this.$refs.autonomeCodeForm;
        if (!form || !form.reportValidity()) return;
        this.erreurs = {};
        this.message = '';
        this.envoi = true;
        try {
            const reponse = await fetch(form.action, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': this.jetonCsrf(), Accept: 'application/json' },
                body: new FormData(form),
            });
            if (reponse.status === 422) {
                const donnees = await reponse.json().catch(() => ({}));
                this.erreurs = donnees.errors || {};
                this.message = 'Veuillez corriger le code indiqué.';
                return;
            }
            if (reponse.status === 429) {
                this.message = 'Trop de tentatives. Patientez une minute avant de réessayer.';
                return;
            }
            const donnees = await reponse.json();
            if (donnees.ok) {
                this.codeOuvert = false;
                this.succes = donnees.message || '';
                this.succesAdresse = donnees.succes || '';
            } else {
                this.message = donnees.message || '';
            }
        } catch (e) {
            this.message = 'Erreur réseau. Vérifiez votre connexion et réessayez.';
        } finally {
            const champ = document.getElementById('code_autonome');
            if (champ) champ.value = '';
            this.envoi = false;
        }
    },
}));

/**
 * Parcours d'inscription 100 % sur la page d'accueil (modal, SANS
 * navigation) : choix → existant (recherche → code → dossier → email) ou
 * nouvelle (identification → wizard embarqué). Tous les endpoints sont les
 * parcours canoniques (mêmes validations, services, transactions) ; seules
 * les enveloppes de réponse changent (JSON). Aucune donnée avant preuve,
 * aucun secret conservé, mots de passe effacés après envoi.
 */
Alpine.data('parcoursAccueil', () => ({
    ouvert: false,
    vue: 'choix',
    retour: [],
    envoi: false,
    message: '',
    erreurs: {},
    /** Dernier déclencheur (retour focus à la fermeture, a11y). */
    declencheurPrecedent: null,
    // Existant.
    dossier: null,
    dossierFlags: {},
    coordsSucces: null,
    emailEtape: 'saisie',
    emailMessage: '',
    emailErreurs: {},
    emailSucces: '',
    // Suivi embarqué (consultation, endpoint canonique JSON, sans navigation).
    suivi: null,
    suiviMessage: '',
    suiviErreurs: {},
    // Nouvelle.
    identMessage: '',
    identErreurs: {},
    nouvellePrete: false,
    // Wizard embarqué.
    inscriptionOk: null,
    // Période (miroir du scope `periodeInscription` de la page canonique :
    // le wizard partagé lit `periode.interactif`). Défaut fermé, puis état
    // réel via l'endpoint canonique à l'entrée de l'état nouvelle.
    periode: { interactif: false, statut: '', titre: '', message: 'Vérification de la période en cours…', debut: null, fin: null },
    periodeChargee: false,

    /** État réel des périodes (même endpoint que la page canonique). */
    async actualiserPeriode() {
        try {
            const panneau = document.getElementById('panneau-nouvelle');
            const params = new URLSearchParams({
                faculte_id: panneau?.querySelector('[name="faculte_id"]')?.value ?? '',
                annee: panneau?.querySelector('[name="annee_academique"]')?.value ?? '',
                cin: panneau?.querySelector('[name="cin"]')?.value ?? '',
            });
            const reponse = await fetch(`/inscription/periode-etat?${params.toString()}`, {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            });
            if (!reponse.ok) return;
            const json = await reponse.json();
            if (json && typeof json.interactif === 'boolean') {
                this.periode = json;
                window.dispatchEvent(new CustomEvent('periode-actualisee', { detail: { interactif: json.interactif } }));
            }
        } catch (e) { /* fail-closed : l'état restrictif est conservé */ }
    },

    jetonCsrf() {
        return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    },

    async postJSON(url, donnees) {
        const reponse = await fetch(url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': this.jetonCsrf(),
            },
            body: donnees,
        });
        let corps = {};
        try { corps = await reponse.json(); } catch (e) { corps = {}; }
        return { statut: reponse.status, corps };
    },

    async getJSON(url) {
        const reponse = await fetch(url, { headers: { Accept: 'application/json' } });
        let corps = {};
        try { corps = await reponse.json(); } catch (e) { corps = {}; }
        return { statut: reponse.status, corps };
    },

    /* États d'interface (LOOP-POPUP-HOME-ONLY-04, sans navigation) :
     * `choix` (= choix), `existant` (= dossier_existant : recherche),
     * `existant-code` (= verification : code à usage unique),
     * `dossier` (= dossier_verifie : lecture + mises à jour autorisées),
     * `nouvelle` (= nouvelle_inscription : identification + wizard officiel
     * embarqué), `suivi` (= suivi_dossier : consultation publique, endpoint
     * canonique JSON). Le changement d'état ne provoque AUCUNE navigation
     * complète (ni changement d'URL applicative, ni rechargement). */
    ciblesFocus() {
        return {
            choix: 'titre-parcours-accueil',
            existant: 'm-ex-cin',
            'existant-code': 'm-ex-code',
            dossier: 'titre-dossier',
            nouvelle: 'm-id-cin',
            suivi: 'm-sv-reference',
        };
    },

    donnerFocus(vue) {
        const cible = (this.ciblesFocus()[vue] || 'titre-parcours-accueil');
        this.$nextTick(() => document.getElementById(cible)?.focus?.({ preventScroll: true }));
    },

    ouvrir(vueInitiale = 'choix') {
        this.declencheurPrecedent = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        this.retour = [];
        this.vue = 'choix';
        this.message = '';
        this.erreurs = {};
        this.suivi = null;
        this.suiviMessage = '';
        this.suiviErreurs = {};
        if (vueInitiale !== 'choix') {
            this.retour.push('choix');
            this.vue = vueInitiale;
            if (vueInitiale === 'nouvelle' && !this.periodeChargee) {
                this.periodeChargee = true;
                this.actualiserPeriode();
            }
        }
        this.ouvert = true;
        this.$nextTick(() => document.getElementById('titre-parcours-accueil')?.focus());
        document.body.style.overflow = 'hidden';
    },

    fermer() {
        this.ouvert = false;
        this.vue = 'choix';
        this.retour = [];
        this.message = '';
        document.body.style.overflow = '';
        this.$nextTick(() => {
            const cible = this.declencheurPrecedent && document.contains(this.declencheurPrecedent)
                ? this.declencheurPrecedent
                : document.getElementById('btn-commencer-accueil');
            cible?.focus?.();
        });
    },

    aller(vue) {
        if (this.vue && this.vue !== vue) this.retour.push(this.vue);
        this.vue = vue;
        this.message = '';
        this.erreurs = {};
        if (vue === 'nouvelle' && !this.periodeChargee) {
            this.periodeChargee = true;
            this.actualiserPeriode();
        }
        this.donnerFocus(vue);
    },

    precedant() {
        const avant = this.retour.pop();
        this.vue = avant || 'choix';
        this.message = '';
        this.erreurs = {};
        this.donnerFocus(this.vue);
    },

    /* ── Branche existante (endpoints canoniques, JSON) ── */

    async rechercherDossier(form) {
        if (!form.reportValidity()) return;
        this.erreurs = {};
        this.message = '';
        this.envoi = true;
        try {
            const { statut, corps } = await this.postJSON(form.action, new FormData(form));
            if (statut === 429) {
                this.message = 'Trop de tentatives. Patientez une minute avant de réessayer.';
                return;
            }
            this.message = corps.message || '';
            if (corps.etape === 'code') this.aller('existant-code');
        } catch (e) {
            this.message = 'Erreur réseau. Vérifiez votre connexion et réessayez.';
        } finally {
            this.envoi = false;
        }
    },

    async verifierCode(form) {
        if (!form.reportValidity()) return;
        this.erreurs = {};
        this.message = '';
        this.envoi = true;
        try {
            const { statut, corps } = await this.postJSON(form.action, new FormData(form));
            if (statut === 422) {
                this.erreurs = corps.errors || {};
                this.message = 'Veuillez corriger le code indiqué.';
                return;
            }
            if (statut === 429) {
                this.message = 'Trop de tentatives. Patientez une minute avant de réessayer.';
                return;
            }
            if (!corps.ok) {
                this.message = corps.message || '';
                return;
            }
            await this.chargerDossier();
        } catch (e) {
            this.message = 'Erreur réseau. Vérifiez votre connexion et réessayez.';
        } finally {
            const champ = document.getElementById('m-ex-code');
            if (champ) champ.value = '';
            this.envoi = false;
        }
    },

    async chargerDossier() {
        const { statut, corps } = await this.getJSON('/inscription/existant/donnees');
        if (!corps.ok) {
            this.message = corps.message || '';
            return;
        }
        this.dossier = corps.dossier || null;
        this.dossierFlags = {
            modifiable: !!corps.dossierModifiable,
            email: !!corps.emailModifiable,
            message: corps.messageStatut || null,
            modifiables: corps.modifiables || [],
        };
        this.coordsSucces = null;
        this.emailEtape = 'saisie';
        this.emailSucces = '';
        this.aller('dossier');
    },

    async enregistrerCoords(form) {
        if (!form.reportValidity()) return;
        this.erreurs = {};
        this.message = '';
        this.envoi = true;
        try {
            const { statut, corps } = await this.postJSON(form.action, new FormData(form));
            if (statut === 422) {
                this.erreurs = corps.errors || {};
                this.message = corps.message || 'Veuillez corriger les champs indiqués.';
                return;
            }
            if (statut === 429) {
                this.message = 'Trop de tentatives. Patientez une minute avant de réessayer.';
                return;
            }
            if (!corps.ok) {
                this.message = corps.message || '';
                return;
            }
            this.coordsSucces = corps.confirmation || {};
            await this.chargerDossierSilencieux();
        } catch (e) {
            this.message = 'Erreur réseau. Vérifiez votre connexion et réessayez.';
        } finally {
            this.envoi = false;
        }
    },

    async chargerDossierSilencieux() {
        const { corps } = await this.getJSON('/inscription/existant/donnees');
        if (corps.ok) {
            this.dossier = corps.dossier || null;
            this.dossierFlags = {
                modifiable: !!corps.dossierModifiable,
                email: !!corps.emailModifiable,
                message: corps.messageStatut || null,
                modifiables: corps.modifiables || [],
            };
        }
    },

    /* Email triple preuve (endpoints canoniques JSON, jeton de session). */

    async demanderEmail(form) {
        if (!form.reportValidity()) return;
        this.emailErreurs = {};
        this.emailMessage = '';
        this.envoi = true;
        try {
            const { statut, corps } = await this.postJSON(form.action, new FormData(form));
            if (statut === 422) {
                this.emailErreurs = corps.errors || {};
                this.emailMessage = corps.message || 'Veuillez corriger les champs indiqués.';
                return;
            }
            if (statut === 429) {
                this.emailMessage = 'Trop de tentatives. Patientez une minute avant de réessayer.';
                return;
            }
            this.emailMessage = corps.message || '';
            if (corps.etape === 'code') {
                this.emailEtape = 'code';
                this.$nextTick(() => document.getElementById('m-email-code')?.focus());
            }
        } catch (e) {
            this.emailMessage = 'Erreur réseau. Vérifiez votre connexion et réessayez.';
        } finally {
            this.envoi = false;
        }
    },

    async confirmerEmail(form, champCode, etapeSuivante) {
        if (!form.reportValidity()) return;
        this.emailErreurs = {};
        this.emailMessage = '';
        this.envoi = true;
        try {
            const { statut, corps } = await this.postJSON(form.action, new FormData(form));
            if (statut === 422) {
                this.emailErreurs = corps.errors || {};
                this.emailMessage = corps.message || 'Veuillez corriger le code indiqué.';
                return;
            }
            if (statut === 429) {
                this.emailMessage = 'Trop de tentatives. Patientez une minute avant de réessayer.';
                return;
            }
            if (!corps.ok) {
                this.emailMessage = corps.message || '';
                if (corps.etape) this.emailEtape = (corps.etape === 'code-nouvelle') ? 'code-nouvelle' : 'code';
                return;
            }
            if (corps.succes) {
                this.emailEtape = 'succes';
                this.emailSucces = corps.succes;
                this.emailMessage = corps.message || '';
                await this.chargerDossierSilencieux();
            } else if (corps.etape) {
                this.emailEtape = (corps.etape === 'code-nouvelle') ? 'code-nouvelle' : 'code';
                this.emailMessage = corps.message || '';
                this.$nextTick(() => document.getElementById('m-email-code2')?.focus());
            }
        } catch (e) {
            this.emailMessage = 'Erreur réseau. Vérifiez votre connexion et réessayez.';
        } finally {
            const champ = document.getElementById(champCode);
            if (champ) champ.value = '';
            this.envoi = false;
        }
    },

    /* ── Branche nouvelle (identification JSON + wizard embarqué) ── */

    async identifierNouveau(form) {
        if (!form.reportValidity()) return;
        this.identErreurs = {};
        this.identMessage = '';
        this.envoi = true;
        try {
            const { statut, corps } = await this.postJSON(form.action, new FormData(form));
            if (statut === 422) {
                this.identErreurs = corps.errors || {};
                this.identMessage = 'Veuillez corriger les champs indiqués.';
                return;
            }
            if (statut === 429) {
                this.identMessage = 'Trop de tentatives. Patientez une minute avant de réessayer.';
                return;
            }
            const statutDossier = corps.status || corps.statut || '';
            if (statutDossier === 'EXISTING_STUDENT') {
                // Dossier existant détecté : bascule vers la branche
                // existante SANS navigation (CIN/DOB repris).
                const cin = form.querySelector('[name="cin"]')?.value || '';
                const dob = form.querySelector('[name="date_naissance"]')?.value || '';
                const cibleCin = document.getElementById('m-ex-cin');
                const cibleDob = document.getElementById('m-ex-dob');
                if (cibleCin) cibleCin.value = cin;
                if (cibleDob) cibleDob.value = dob;
                this.identMessage = corps.message || '';
                this.aller('existant');
                return;
            }
            if (statutDossier === 'MENS_FOUND' && corps.prefill) {
                this.appliquerPrefillModal(corps.prefill);
                if (corps.prefill.sexe) {
                    window.dispatchEvent(new CustomEvent('sexe-identifie', { detail: { sexe: corps.prefill.sexe } }));
                }
            }
            if (statutDossier === 'MENS_UNAVAILABLE' || statutDossier === 'AMBIGUOUS') {
                this.identMessage = corps.message || '';
            }
            this.nouvellePrete = true;
            window.dispatchEvent(new CustomEvent('identification-verifiee'));
            this.aller('nouvelle');
        } catch (e) {
            this.identMessage = 'Erreur réseau. Vérifiez votre connexion et réessayez.';
        } finally {
            this.envoi = false;
        }
    },

    /* ── Branche suivi (endpoint canonique JSON, sans navigation) ── */

    async rechercherSuivi(form) {
        if (!form.reportValidity()) return;
        this.suiviErreurs = {};
        this.suiviMessage = '';
        this.envoi = true;
        try {
            const { statut, corps } = await this.postJSON(form.action, new FormData(form));
            if (statut === 422) {
                this.suiviErreurs = corps.errors || {};
                this.suiviMessage = 'Veuillez corriger les champs indiqués.';
                return;
            }
            if (statut === 429) {
                this.suiviMessage = 'Trop de tentatives. Patientez une minute avant de réessayer.';
                return;
            }
            if (!corps.ok) {
                this.suivi = null;
                this.suiviMessage = corps.message || '';
                return;
            }
            this.suivi = corps.suivi || null;
            this.suiviMessage = '';
        } catch (e) {
            this.suiviMessage = 'Erreur réseau. Vérifiez votre connexion et réessayez.';
        } finally {
            this.envoi = false;
        }
    },

    nouvelleRechercheSuivi() {
        this.suivi = null;
        this.suiviMessage = '';
        this.suiviErreurs = {};
        this.donnerFocus('suivi');
    },

    /** Pré-remplissage MENS limité au panneau modal (par nom, jamais global). */
    appliquerPrefillModal(prefill) {
        const panneau = document.getElementById('panneau-nouvelle');
        if (!panneau || !prefill) return;
        ['cin', 'nom', 'prenom', 'date_naissance', 'nationalite'].forEach((champ) => {
            const el = panneau.querySelector(`[name="${champ}"]`);
            const valeur = prefill[champ];
            if (el && valeur !== null && valeur !== undefined && valeur !== '') el.value = valeur;
        });
    },
}));

Livewire.start();
