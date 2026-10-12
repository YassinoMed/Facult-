import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';
// Compte étudiant seedé au préalable (seed local/testing : mot de
// passe `etudiant123`, ou valeurs injectées via variables d'env).
const IDENTIFIANT = __ENV.STUDENT_ID || 'mehdi.alami@example.com';
const MOT_DE_PASSE = __ENV.STUDENT_PASSWORD || 'etudiant123';

// Parcours étudiant authentifié : login (throttle 5/min/IP côté
// serveur), tableau de bord LÉGER, dossier, notes, documents,
// historique, déconnexion.
export const options = {
  stages: [
    { duration: '1m', target: 10 },
    { duration: '2m', target: 50 },
    { duration: '1m', target: 0 },
  ],
  thresholds: {
    http_req_duration: ['p(95)<2500'],
  },
};

function jetonCsrf(corps) {
  const trouve = corps.match(/name="_token"\s+value="([^"]+)"/);
  return trouve ? trouve[1] : null;
}

export default function () {
  const jar = http.cookieJar();

  const page = http.get(`${BASE_URL}/etudiant/connexion`);
  check(page, { 'GET connexion → 200': (r) => r.status === 200 });

  const token = page.status === 200 ? jetonCsrf(page.body) : null;
  if (!token) {
    sleep(1);
    return;
  }

  const login = http.post(
    `${BASE_URL}/etudiant/connexion`,
    { _token: token, identifiant: IDENTIFIANT, password: MOT_DE_PASSE },
    { cookies: jar.cookiesForURL(BASE_URL), tags: { page: 'POST login' } },
  );
  check(login, { 'login → 302 ou 429': (r) => [302, 429].includes(r.status) });

  const pages = [
    '/etudiant/tableau-de-bord',
    '/etudiant/profil',
    '/etudiant/archives',
    '/etudiant/notes',
    '/etudiant/documents',
    '/etudiant/historique',
  ];

  for (const chemin of pages) {
    const res = http.get(`${BASE_URL}${chemin}`, {
      cookies: jar.cookiesForURL(BASE_URL),
      tags: { page: `GET ${chemin}` },
    });
    check(res, { [`${chemin} → 200/302/404`]: (r) => [200, 302, 404].includes(r.status) });
  }

  const sortie = http.post(
    `${BASE_URL}/etudiant/deconnexion`,
    { _token: token },
    { cookies: jar.cookiesForURL(BASE_URL), tags: { page: 'POST logout' } },
  );
  check(sortie, { 'logout → 302': (r) => r.status === 302 });

  sleep(1);
}
