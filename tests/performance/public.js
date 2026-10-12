import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';

// Trafic public anonyme : portail, récépissé, pages de connexion.
// Lecture seule, sans CSRF : adapté à la montée en charge 10 → 100 VUs.
export const options = {
  stages: [
    { duration: '1m', target: 10 },
    { duration: '2m', target: 50 },
    { duration: '2m', target: 100 },
    { duration: '30s', target: 0 },
  ],
  thresholds: {
    http_req_failed: ['rate<0.05'],
    http_req_duration: ['p(50)<800', 'p(95)<2000'],
  },
};

export default function () {
  const pages = ['/', '/inscription/success', '/etudiant/connexion', '/admin/connexion'];

  for (const page of pages) {
    const res = http.get(`${BASE_URL}${page}`, { tags: { page } });

    check(res, {
      [`GET ${page} → 200`]: (r) => r.status === 200,
    });
  }

  sleep(1);
}
