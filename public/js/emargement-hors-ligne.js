/*
 * LOOP-10 — file d'émargement hors ligne.
 *
 * Politique de données locales (validée) :
 *  - uniquement des identifiants techniques (candidat), le statut pointé,
 *    l'horodatage et une clé d'idempotence ;
 *  - AUCUN champ nominatif ni texte libre ;
 *  - TTL 24 h, purge automatique, effacement après synchronisation ;
 *  - stockage par utilisateur (pas de partage entre agents sur l'appareil).
 *
 * Le serveur reste seul juge : il revalide le statut, l'appartenance du
 * candidat à l'épreuve et le verrouillage. Un rejeu est sans effet grâce
 * à la clé d'idempotence.
 */
(() => {
    'use strict';

    const form = document.getElementById('form-emargement');
    const panneau = document.getElementById('emargement-hors-ligne');
    if (!form || !panneau) {
        return;
    }

    const TTL_MS = 24 * 60 * 60 * 1000;
    const epreuveId = String(form.dataset.epreuveId || '');
    const utilisateurId = String(form.dataset.utilisateurId || 'anonyme');
    const urlSync = String(form.dataset.syncUrl || '');
    const cleStockage = `uniregistre.emargement.v1.${utilisateurId}`;

    const caseActiver = document.getElementById('eh-activer');
    const boutonSync = document.getElementById('eh-synchroniser');
    const zoneStatut = document.getElementById('eh-statut');
    const compteur = document.getElementById('eh-attente');
    const messageTexteLibre = document.getElementById('eh-note-texte-libre');

    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    function lireTout() {
        try {
            const brut = window.localStorage.getItem(cleStockage);
            const donnees = brut ? JSON.parse(brut) : {};
            return donnees && typeof donnees === 'object' ? donnees : {};
        } catch (e) {
            return {};
        }
    }

    function ecrireTout(donnees) {
        try {
            window.localStorage.setItem(cleStockage, JSON.stringify(donnees));
        } catch (e) {
            // Stockage plein ou désactivé : on prévient l'agent, jamais de perte silencieuse.
            afficher('Stockage local indisponible : la saisie hors ligne ne peut pas être conservée.', true);
        }
    }

    function etatEpreuve(donnees) {
        const courant = donnees[epreuveId];
        return {
            actif: Boolean(courant && courant.actif),
            entrees: Array.isArray(courant?.entrees) ? courant.entrees : [],
        };
    }

    function enregistrerEtat(donnees, etat) {
        donnees[epreuveId] = { actif: Boolean(etat.actif), entrees: etat.entrees };
        ecrireTout(donnees);
    }

    function purgerExpirees(etat) {
        const limite = Date.now() - TTL_MS;
        const avant = etat.entrees.length;
        etat.entrees = etat.entrees.filter((entree) => Number(entree.horodatage) >= limite);
        return avant - etat.entrees.length;
    }

    function afficher(texte, alerte = false) {
        if (!zoneStatut) {
            return;
        }
        zoneStatut.textContent = texte;
        zoneStatut.classList.toggle('text-rose-700', alerte);
        zoneStatut.classList.toggle('text-emerald-700', !alerte);
    }

    function rafraichir() {
        const donnees = lireTout();
        const etat = etatEpreuve(donnees);
        const expirees = purgerExpirees(etat);
        if (expirees > 0) {
            enregistrerEtat(donnees, etat);
            afficher(`${expirees} pointage(s) local(aux) expiré(s) après 24 h et supprimé(s).`, true);
        }

        if (caseActiver) {
            caseActiver.checked = etat.actif;
        }
        if (compteur) {
            compteur.textContent = String(etat.entrees.length);
        }
        if (boutonSync) {
            boutonSync.disabled = etat.entrees.length === 0;
        }
        if (messageTexteLibre) {
            messageTexteLibre.hidden = !etat.actif;
        }
        if (etat.entrees.length > 0 && zoneStatut && zoneStatut.textContent === '') {
            afficher(`${etat.entrees.length} pointage(s) en attente de synchronisation.`);
        }
    }

    function nouvelleCle() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        // Repli conforme UUID v4 (navigateurs anciens) : jamais un compteur prévisible.
        const octets = new Uint8Array(16);
        window.crypto.getRandomValues(octets);
        octets[6] = (octets[6] & 0x0f) | 0x40;
        octets[8] = (octets[8] & 0x3f) | 0x80;
        const hex = [...octets].map((o) => o.toString(16).padStart(2, '0')).join('');
        return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
    }

    function collecter() {
        const entrees = [];
        form.querySelectorAll('select[name^="presences"]').forEach((select) => {
            const correspondance = select.name.match(/^presences\[(\d+)\]\[statut\]$/);
            if (!correspondance) {
                return;
            }
            const index = correspondance[1];
            const champ = form.querySelector(`input[name="presences[${index}][candidat_id]"]`);
            const candidatId = champ ? Number(champ.value) : NaN;
            if (!Number.isInteger(candidatId) || candidatId <= 0) {
                return;
            }
            entrees.push({
                cle: nouvelleCle(),
                candidat_id: candidatId,
                statut: String(select.value),
                horodatage: Date.now(),
            });
        });
        return entrees;
    }

    function mettreEnFile() {
        const donnees = lireTout();
        const etat = etatEpreuve(donnees);
        const nouvelles = collecter();
        const parCandidat = new Map(etat.entrees.map((e) => [Number(e.candidat_id), e]));
        nouvelles.forEach((entree) => parCandidat.set(entree.candidat_id, entree));
        etat.entrees = [...parCandidat.values()];
        enregistrerEtat(donnees, etat);
        rafraichir();
        afficher(`${etat.entrees.length} pointage(s) conservé(s) localement, en attente de réseau.`);
    }

    async function synchroniser() {
        const donnees = lireTout();
        const etat = etatEpreuve(donnees);
        purgerExpirees(etat);

        if (etat.entrees.length === 0) {
            enregistrerEtat(donnees, etat);
            rafraichir();
            return;
        }

        if (boutonSync) {
            boutonSync.disabled = true;
        }
        afficher('Synchronisation en cours…');

        try {
            const reponse = await fetch(urlSync, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify({
                    presences: etat.entrees.map((entree) => ({
                        cle_idempotence: entree.cle,
                        candidat_id: entree.candidat_id,
                        statut: entree.statut,
                    })),
                }),
            });

            if (!reponse.ok) {
                const corps = await reponse.json().catch(() => ({}));
                afficher(corps.erreur || `Synchronisation refusée (${reponse.status}). Les pointages restent en local.`, true);
                if (boutonSync) {
                    boutonSync.disabled = false;
                }
                return;
            }

            const resultat = await reponse.json().catch(() => ({}));
            // Le serveur a traité le lot : les clés sont désormais idempotentes,
            // un rejeu serait sans effet. On efface la file locale.
            etat.entrees = [];
            enregistrerEtat(donnees, etat);
            rafraichir();

            const ignores = Number(resultat.ignores || 0);
            afficher(
                `Synchronisé : ${Number(resultat.synchronises || 0)} pointage(s)` +
                (Number(resultat.deja_synchronises || 0) > 0 ? `, ${resultat.deja_synchronises} déjà reçu(s)` : '') +
                (ignores > 0 ? `, ${ignores} ignoré(s) car hors épreuve` : '') + '.'
            );
        } catch (e) {
            afficher('Réseau indisponible : les pointages restent conservés localement.', true);
            if (boutonSync) {
                boutonSync.disabled = false;
            }
        }
    }

    if (caseActiver) {
        caseActiver.addEventListener('change', () => {
            const donnees = lireTout();
            const etat = etatEpreuve(donnees);
            etat.actif = caseActiver.checked;
            enregistrerEtat(donnees, etat);
            rafraichir();
            afficher(etat.actif
                ? 'Saisie hors ligne activée : les pointages seront conservés localement (identifiants et statut uniquement).'
                : 'Saisie hors ligne désactivée.');
        });
    }

    if (boutonSync) {
        boutonSync.addEventListener('click', (evenement) => {
            evenement.preventDefault();
            synchroniser();
        });
    }

    form.addEventListener('submit', (evenement) => {
        const horsLigne = !window.navigator.onLine;
        const actif = caseActiver ? caseActiver.checked : false;

        if (!horsLigne && !actif) {
            return; // Envoi serveur normal.
        }

        evenement.preventDefault();
        mettreEnFile();
    });

    window.addEventListener('online', () => {
        const etat = etatEpreuve(lireTout());
        if (etat.entrees.length > 0) {
            afficher('Réseau rétabli : synchronisation automatique…');
            synchroniser();
        }
    });

    rafraichir();
})();
