import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';

// Scénario mixte réaliste (répartition du trafic observée) :
// 60 % visiteurs · 20 % étudiants · 15 % inscriptions · 5 % scolarité.
// Montée progressive 10 → 50 → 100 → 250 VUs. Les POST d'inscription
// et de login sont VOLONTAIREMENT throttlés côté serveur (429) : le
// premier goulot mesuré sera donc le rate limiting, puis PHP/MySQL.
export const options = {
  stages: [
    { duration: '2m', target: 10 },
    { duration: '3m', target: 50 },
    { duration: '3m', target: 100 },
    { duration: '3m', target: 250 },
    { duration: '1m', target: 0 },
  ],
  thresholds: {
    http_req_failed: ['rate<0.10'],
    http_req_duration: ['p(95)<3000'],
  },
};

function jetonCsrf(corps) {
  const trouve = corps.match(/name="_token"\s+value="([^"]+)"/);
  return trouve ? trouve[1] : null;
}

function parcoursVisiteur() {
  const res = http.get(`${BASE_URL}/`, { tags: { parcours: 'visiteur' } });
  check(res, { 'visiteur → 200': (r) => r.status === 200 });
}

function parcoursInscription() {
  const jar = http.cookieJar();
  const page = http.get(`${BASE_URL}/`, { tags: { parcours: 'inscription' } });
  if (page.status !== 200) return;

  const token = jetonCsrf(page.body);
  const faculte = (page.body.match(/<option value="(\d+)"/) || [])[1];
  if (!token || !faculte) return;

  const unique = `${__VU}-${__ITER}-${Date.now()}`;
  const depot = http.post(
    `${BASE_URL}/inscription`,
    {
      _token: token,
      cin: `K6${unique}`.slice(0, 20).toUpperCase().replace(/[^A-Z0-9]/g, 'X'),
      nom: 'Charge',
      prenom: `K6 ${unique}`.slice(0, 40),
      email: `k6.${unique}@example.com`,
      sexe: 'M',
      faculte_id: faculte,
      niveau: 'L1',
      annee_academique: '2026-2027',
      adresse: 'Rue des tests',
      ville: 'Casablanca',
      code_postal: '20000',
      annee_bac: '2024',
      password: 'K6ChargeTest12',
      password_confirmation: 'K6ChargeTest12',
    },
    { cookies: jar.cookiesForURL(BASE_URL), tags: { parcours: 'inscription' } },
  );
  check(depot, { 'inscription → 302/422/429': (r) => [302, 422, 429].includes(r.status) });
}

function parcoursEtudiant() {
  const res = http.get(`${BASE_URL}/etudiant/tableau-de-bord`, { tags: { parcours: 'etudiant' } });
  // Non authentifié → 302 vers la connexion : le poids mesuré reste celui
  // du middleware + de la redirection (le parcours authentifié complet
  // est couvert par student.js avec compte seedé).
  check(res, { 'dashboard → 302': (r) => r.status === 302 });
}

function parcoursScolarite() {
  const res = http.get(`${BASE_URL}/admin/connexion`, { tags: { parcours: 'scolarite' } });
  check(res, { 'connexion scolarité → 200': (r) => r.status === 200 });
}

export default function () {
  const tirage = Math.random();

  if (tirage < 0.6) parcoursVisiteur();
  else if (tirage < 0.8) parcoursEtudiant();
  else if (tirage < 0.95) parcoursInscription();
  else parcoursScolarite();

  sleep(1);
}
