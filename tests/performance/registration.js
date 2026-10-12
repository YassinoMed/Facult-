import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';

// Parcours d'inscription complet : GET / (jeton CSRF + session),
// puis POST /inscription avec CIN/email uniques par itération.
//
// Goulots connus et VOLONTAIRES côté serveur :
// - throttle:10,1 sur POST /inscription (10 req/min/IP) → des 429 sont
//   attendus au-delà de 10 VUs et DOIVENT être comptés comme protection,
//   pas comme erreur applicative ;
// - Password::defaults() avec `uncompromised` hors environnement de test
//   (appel réseau haveibeenpwned par inscription).
export const options = {
  stages: [
    { duration: '1m', target: 10 },
    { duration: '2m', target: 50 },
    { duration: '1m', target: 0 },
  ],
  thresholds: {
    http_req_duration: ['p(95)<3000'],
  },
};

function jetonCsrf(corps) {
  const trouve = corps.match(/name="_token"\s+value="([^"]+)"/);
  return trouve ? trouve[1] : null;
}

function premiereFaculte(corps) {
  const trouve = corps.match(/<option value="(\d+)"/);
  return trouve ? trouve[1] : null;
}

export default function () {
  const jar = http.cookieJar();

  const page = http.get(`${BASE_URL}/`);
  check(page, { 'GET / → 200': (r) => r.status === 200 });
  if (page.status !== 200) {
    sleep(1);
    return;
  }

  const token = jetonCsrf(page.body);
  const faculteId = premiereFaculte(page.body);
  check(page, { 'CSRF + faculté présents': () => token !== null && faculteId !== null });
  if (!token || !faculteId) {
    sleep(1);
    return;
  }

  const unique = `${__VU}-${__ITER}-${Date.now()}`;
  const payload = {
    _token: token,
    cin: `K6${unique}`.slice(0, 20).toUpperCase().replace(/[^A-Z0-9]/g, 'X'),
    nom: 'Charge',
    prenom: `K6 ${unique}`.slice(0, 40),
    email: `k6.${unique}@example.com`,
    telephone: '+212600000000',
    date_naissance: '2000-01-01',
    sexe: 'M',
    faculte_id: faculteId,
    niveau: 'L1',
    annee_academique: '2026-2027',
    adresse: 'Rue des tests de charge',
    ville: 'Casablanca',
    code_postal: '20000',
    annee_bac: '2024',
    password: 'K6ChargeTest12',
    password_confirmation: 'K6ChargeTest12',
  };

  const depot = http.post(`${BASE_URL}/inscription`, payload, {
    cookies: jar.cookiesForURL(BASE_URL),
    tags: { page: 'POST /inscription' },
  });

  check(depot, {
    'POST /inscription → 302, 422 ou 429': (r) => [302, 422, 429].includes(r.status),
  });

  sleep(1);
}
