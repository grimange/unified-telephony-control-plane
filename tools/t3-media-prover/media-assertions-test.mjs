import assert from 'node:assert/strict';
import { assertExternalMediaCandidate } from './media-assertions.mjs';

assert.equal(assertExternalMediaCandidate({ address: '127.0.0.1', port: 40042, configuredAddress: '127.0.0.1' }).fallback, false);
for (const candidate of [
  { address: '10.42.0.8', port: 40042, forbiddenAddresses: ['10.42.0.8'] },
  { address: '127.0.0.1', port: 39999 },
  { address: '192.168.10.10', port: 40042, privateRuntimeAddresses: ['192.168.10.10'] },
]) {
  assert.throws(() => assertExternalMediaCandidate({ ...candidate, configuredAddress: '127.0.0.1' }));
}
console.log('t3_media_candidate_assertions=ok');
