import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
  scenarios: {
    universal_form_flow: {
      executor: 'ramping-vus',
      stages: [
        { duration: '30s', target: 150 },
        { duration: '2m', target: 150 },
        { duration: '30s', target: 0 },
      ],
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<1000'],
  },
};

const baseUrl = __ENV.BASE_URL || 'http://127.0.0.1:8000';
const publicationKey = __ENV.PUBLICATION_KEY;

function token(html) {
  return html.match(/name="csrf-token" content="([^"]+)"/)?.[1];
}

export default function () {
  if (!publicationKey) throw new Error('PUBLICATION_KEY is required and must identify an active public-link test publication.');
  const landing = http.get(`${baseUrl}/f/${publicationKey}`);
  check(landing, { 'form landing loaded': (r) => r.status === 200 });
  const start = http.post(`${baseUrl}/f/${publicationKey}/start`, {_token: token(landing.body)}, {redirects: 0});
  check(start, { 'attempt started': (r) => r.status === 302 });
  const attemptUrl = start.headers.Location?.startsWith('http') ? start.headers.Location : `${baseUrl}${start.headers.Location}`;
  const form = http.get(attemptUrl);
  check(form, { 'form rendered': (r) => r.status === 200 });
  const autosaveUrl = form.body.match(/data-autosave-url="([^"]+)"/)?.[1]?.replaceAll('&amp;', '&');
  const finalizeUrl = form.body.match(/data-finalize-url="([^"]+)"/)?.[1]?.replaceAll('&amp;', '&');
  const revision = Number(form.body.match(/data-revision="(\d+)"/)?.[1] || 0);
  const componentId = form.body.match(/data-component="(\d+)"/)?.[1];
  const option = form.body.match(/data-answer[^>]+value="([^"]+)"/)?.[1];
  const mutation = `${__VU.toString(16).padStart(8, '0')}-0000-4000-8000-${(__ITER + 1).toString(16).padStart(12, '0')}`;
  const answers = componentId && option ? {[componentId]: option} : {};
  const saved = http.post(autosaveUrl, JSON.stringify({expected_revision: revision, client_mutation_id: mutation, answers}), {headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token(form.body)}});
  check(saved, { 'autosave accepted': (r) => r.status === 200 });
  sleep(0.5);
  const finalized = http.post(finalizeUrl, null, {headers: {Accept: 'application/json', 'X-CSRF-TOKEN': token(form.body)}});
  check(finalized, { 'submission finalized': (r) => r.status === 200 });
}
