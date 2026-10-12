import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';
// Compte scolarité créé au préalable (ex. via
// `php artisan integration:token-create --help` pour l'API, ou seed
// ArchiveSeeder en local/testing).
const EMAIL = __ENV.ADMIN_EMAIL || 'scolarite@example.com';
const MOT_DE_PASSE = __ENV.ADMIN_PASSWORD || 'password';

// Parcours scolarité : login (throttle 5/min/IP), tableau de bord
// AGRÉGÉ (1 requête de stats), registre + recherche + filtres.
export const options = {
  stages: [
    { duration: '1m', target: 5 },
    { duration: '2m', target: 20 },
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

  const page = http.get(`${BASE_URL}/admin/connexion`);
  check(page, { 'GET admin/connexion → 200': (r) => r.status === 200 });

  const token = page.status === 200 ? jetonCsrf(page.body) : null;
  if (!token) {
    sleep(1);
    return;
  }

  const login = http.post(
    `${BASE_URL}/admin/connexion`,
    { _token: token, email: EMAIL, password: MOT_DE_PASSE },
    { cookies: jar.cookiesForURL(BASE_URL), tags: { page: 'POST login' } },
  );
  check(login, { 'login → 302 ou 429': (r) => [302, 429].includes(r.status) });

  const cookies = jar.cookiesForURL(BASE_URL);
  const pages = [
    '/admin',
    '/admin/etudiants',
    '/admin/etudiants?search=K6',
    '/admin/etudiants?statut=en_attente&niveau=L1',
    '/admin/archives',
    '/admin/facultes',
  ];

  for (const chemin of pages) {
    const res = http.get(`${BASE_URL}${chemin}`, { cookies, tags: { page: `GET ${chemin}` } });
    check(res, { [`${chemin} → 200/302`]: (r) => [200, 302].includes(r.status) });
  }

  sleep(1);
}
