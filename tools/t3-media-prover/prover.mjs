import { chromium } from '@playwright/test';
import crypto from 'node:crypto';
import dns from 'node:dns/promises';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';

const REQUIRED_RESULT_FIELDS = [
  'scenario',
  'callId',
  'iceConnectionState',
  'iceGatheringState',
  'selectedCandidatePair',
  'localCandidateType',
  'localCandidateAddressClass',
  'remoteCandidateType',
  'remoteCandidateAddressClass',
  'dtlsState',
  'peerConnectionState',
  'outboundRtpPackets',
  'outboundRtpBytes',
  'inboundRtpPackets',
  'inboundRtpBytes',
  'audioEnergy',
  'byeDirection',
  'finalSipResult',
  'cleanupResult',
  'durationMs',
];

const config = {
  scenario: env('UTCP_T3_MEDIA_PROVER_SCENARIO', 'browser-bye'),
  applicationUrl: env('UTCP_T3_MEDIA_PROVER_APP_URL', 'https://app.utcp.local.test'),
  sipWssUrl: env('UTCP_T3_MEDIA_PROVER_SIP_WSS_URL', 'wss://sip.utcp.local.test/ws'),
  sipDomain: env('UTCP_T3_MEDIA_PROVER_SIP_DOMAIN', 'sip.utcp.local.test'),
  extension: env('UTCP_T3_MEDIA_PROVER_EXTENSION', '9900'),
  gatewayService: env('UTCP_T3_MEDIA_PROVER_GATEWAY_SERVICE', 'traefik.traefik-system.svc.cluster.local'),
  gatewayPort: Number(env('UTCP_T3_MEDIA_PROVER_GATEWAY_PORT', '443')),
  loginEmail: requiredEnv('UTCP_T3_MEDIA_PROVER_LOGIN_EMAIL'),
  loginPassword: requiredEnv('UTCP_T3_MEDIA_PROVER_LOGIN_PASSWORD'),
  sipUsername: requiredEnv('UTCP_T3_MEDIA_PROVER_SIP_USERNAME'),
  sipPassword: requiredEnv('UTCP_T3_MEDIA_PROVER_SIP_PASSWORD'),
  callTimeoutMs: Number(env('UTCP_T3_MEDIA_PROVER_CALL_TIMEOUT_MS', '45000')),
  mediaTimeoutMs: Number(env('UTCP_T3_MEDIA_PROVER_MEDIA_TIMEOUT_MS', '45000')),
  runtimeByeWaitMs: Number(env('UTCP_T3_MEDIA_PROVER_RUNTIME_BYE_WAIT_MS', '120000')),
  resultPath: env('UTCP_T3_MEDIA_PROVER_RESULT_PATH', '/proof/results/result.json'),
};

const started = Date.now();
const result = {
  scenario: config.scenario,
  assertions: {},
  errors: [],
};

let browser;
let page;

try {
  if (!['browser-bye', 'runtime-bye'].includes(config.scenario)) {
    throw new Error(`unsupported scenario ${config.scenario}`);
  }

  const gatewayAddress = await resolveGatewayAddress(config.gatewayService);
  browser = await chromium.launch({
    headless: true,
    args: [
      '--use-fake-ui-for-media-stream',
      '--use-fake-device-for-media-stream',
      '--autoplay-policy=no-user-gesture-required',
      `--host-resolver-rules=MAP app.utcp.local.test ${gatewayAddress},MAP sip.utcp.local.test ${gatewayAddress}`,
    ],
  });

  const context = await browser.newContext({
    baseURL: config.applicationUrl,
    permissions: ['microphone'],
    ignoreHTTPSErrors: false,
  });
  page = await context.newPage();
  await page.exposeFunction('utcpMd5', (value) => crypto.createHash('md5').update(value).digest('hex'));
  await naturalLogin(page);

  const proof = await page.evaluate(async (cfg) => {
    const requiredResultFields = [
      'scenario',
      'callId',
      'iceConnectionState',
      'iceGatheringState',
      'selectedCandidatePair',
      'localCandidateType',
      'localCandidateAddressClass',
      'remoteCandidateType',
      'remoteCandidateAddressClass',
      'dtlsState',
      'peerConnectionState',
      'outboundRtpPackets',
      'outboundRtpBytes',
      'inboundRtpPackets',
      'inboundRtpBytes',
      'audioEnergy',
      'byeDirection',
      'finalSipResult',
      'cleanupResult',
      'durationMs',
    ];

    const startedAt = Date.now();
    const pc = new RTCPeerConnection({
      iceServers: [],
      bundlePolicy: 'max-bundle',
      rtcpMuxPolicy: 'require',
    });
    const audioContext = new AudioContext();
    const oscillator = audioContext.createOscillator();
    const gain = audioContext.createGain();
    const destination = audioContext.createMediaStreamDestination();
    oscillator.frequency.value = 440;
    gain.gain.value = 0.18;
    oscillator.connect(gain);
    gain.connect(destination);
    oscillator.start();
    for (const track of destination.stream.getAudioTracks()) {
      pc.addTrack(track, destination.stream);
    }

    const remoteAudio = document.createElement('audio');
    remoteAudio.autoplay = true;
    remoteAudio.muted = false;
    document.body.appendChild(remoteAudio);
    pc.ontrack = (event) => {
      remoteAudio.srcObject = event.streams[0];
    };

    const ws = new WebSocket(cfg.sipWssUrl, 'sip');
    const messages = [];
    ws.addEventListener('message', (event) => messages.push(String(event.data)));
    await waitFor(() => ws.readyState === WebSocket.OPEN, cfg.callTimeoutMs, 'SIP WebSocket open');

    const callId = `${Math.random().toString(16).slice(2)}@utcp-t3-media-prover`;
    const fromTag = Math.random().toString(16).slice(2);
    const contact = `<sip:${cfg.sipUsername}@${Math.random().toString(16).slice(2)}.invalid;transport=ws>`;
    const inviteCseq = 1;
    const localOffer = await pc.createOffer({ offerToReceiveAudio: true });
    await pc.setLocalDescription(localOffer);
    await waitFor(() => pc.iceGatheringState === 'complete', cfg.callTimeoutMs, 'ICE gathering complete');

    const target = `sip:${cfg.extension}@${cfg.sipDomain}`;
    const invite = buildRequest({
      method: 'INVITE',
      target,
      callId,
      fromTag,
      cseq: inviteCseq,
      contact,
      extraHeaders: ['Content-Type: application/sdp'],
      body: pc.localDescription.sdp,
      cfg,
    });
    ws.send(invite);

    let response = await waitForMessage(messages, (message) => sipStatus(message) === 401, cfg.callTimeoutMs);
    const auth = await digestAuthorization(response, 'INVITE', target, cfg.sipUsername, cfg.sipPassword);
    ws.send(buildRequest({
      method: 'INVITE',
      target,
      callId,
      fromTag,
      cseq: inviteCseq + 1,
      contact,
      authorization: auth,
      extraHeaders: ['Content-Type: application/sdp'],
      body: pc.localDescription.sdp,
      cfg,
    }));

    response = await waitForMessage(messages, (message) => sipStatus(message) === 200 && /CSeq:\s*2 INVITE/i.test(message), cfg.callTimeoutMs);
    const remoteSdp = bodyOf(response);
    if (!remoteSdp.includes('m=audio') || !remoteSdp.includes('a=rtcp-mux')) {
      throw new Error('SDP answer is not a WebRTC audio answer with rtcp-mux');
    }
    await pc.setRemoteDescription({ type: 'answer', sdp: remoteSdp });
    const toTag = parseTag(header(response, 'To'));
    const routeSet = parseRecordRoutes(response);
    ws.send(buildRequest({
      method: 'ACK',
      target,
      callId,
      fromTag,
      toTag,
      cseq: inviteCseq + 1,
      contact,
      routeSet,
      cfg,
    }));

    await waitFor(() => ['connected', 'completed'].includes(pc.iceConnectionState), cfg.mediaTimeoutMs, 'ICE connected');
    await waitFor(() => ['connected'].includes(pc.connectionState), cfg.mediaTimeoutMs, 'PeerConnection connected');
    const before = await collectStats(pc);
    await sleep(3500);
    const after = await collectStats(pc);
    assertMediaCounters(before, after);

    let finalSipResult;
    let byeDirection;
    if (cfg.scenario === 'browser-bye') {
      byeDirection = 'browser';
      ws.send(buildRequest({
        method: 'BYE',
        target,
        callId,
        fromTag,
        toTag,
        cseq: inviteCseq + 2,
        contact,
        routeSet,
        cfg,
      }));
      const byeOk = await waitForMessage(messages, (message) => sipStatus(message) === 200 && /CSeq:\s*3 BYE/i.test(message), cfg.callTimeoutMs);
      finalSipResult = firstLine(byeOk);
    } else {
      byeDirection = 'runtime';
      const bye = await waitForMessage(messages, (message) => /^BYE\s/i.test(message) && message.includes(callId), cfg.runtimeByeWaitMs);
      finalSipResult = '200 OK';
      ws.send(buildResponse({ status: '200 OK', request: bye, cfg }));
    }

    oscillator.stop();
    for (const sender of pc.getSenders()) {
      if (sender.track) sender.track.stop();
    }
    pc.close();
    ws.close();

    const selectedPair = after.selectedCandidatePair || {};
    const output = {
      scenario: cfg.scenario,
      callId,
      iceConnectionState: pc.iceConnectionState,
      iceGatheringState: pc.iceGatheringState,
      selectedCandidatePair: selectedPair.id || 'selected',
      localCandidateType: selectedPair.localCandidateType || 'unknown',
      localCandidateAddressClass: addressClass(selectedPair.localAddress || ''),
      remoteCandidateType: selectedPair.remoteCandidateType || 'unknown',
      remoteCandidateAddressClass: addressClass(selectedPair.remoteAddress || ''),
      dtlsState: after.dtlsState || pc.connectionState,
      peerConnectionState: pc.connectionState,
      outboundRtpPackets: after.outboundPackets,
      outboundRtpBytes: after.outboundBytes,
      inboundRtpPackets: after.inboundPackets,
      inboundRtpBytes: after.inboundBytes,
      audioEnergy: after.audioEnergy,
      jitter: after.jitter,
      packetsLost: after.packetsLost,
      byeDirection,
      finalSipResult,
      cleanupResult: 'signaling-closed',
      durationMs: Date.now() - startedAt,
    };
    for (const field of requiredResultFields) {
      if (output[field] === undefined || output[field] === null || output[field] === '') {
        throw new Error(`missing structured result field ${field}`);
      }
    }
    return output;

    function buildRequest({ method, target, callId, fromTag, toTag, cseq, contact, routeSet = [], authorization, extraHeaders = [], body = '', cfg }) {
      const branch = `z9hG4bK-${Math.random().toString(16).slice(2)}`;
      const lines = [
        `${method} ${target} SIP/2.0`,
        `Via: SIP/2.0/WS ${location.host};branch=${branch}`,
        `Max-Forwards: 70`,
        `To: <${target}>${toTag ? `;tag=${toTag}` : ''}`,
        `From: <sip:${cfg.sipUsername}@${cfg.sipDomain}>;tag=${fromTag}`,
        `Call-ID: ${callId}`,
        `CSeq: ${cseq} ${method}`,
        `Contact: ${contact}`,
      ];
      for (const route of routeSet) lines.push(`Route: ${route}`);
      if (authorization) lines.push(`Authorization: ${authorization}`);
      lines.push(...extraHeaders);
      lines.push(`Content-Length: ${new TextEncoder().encode(body).length}`);
      return `${lines.join('\r\n')}\r\n\r\n${body}`;
    }

    function buildResponse({ status, request }) {
      const vias = request.match(/^Via:.*$/gmi) || [];
      return [
        `SIP/2.0 ${status}`,
        ...vias,
        headerLine(request, 'To'),
        headerLine(request, 'From'),
        headerLine(request, 'Call-ID'),
        headerLine(request, 'CSeq'),
        'Content-Length: 0',
        '',
        '',
      ].join('\r\n');
    }

    async function digestAuthorization(challenge, method, uri, username, password) {
      const params = Object.fromEntries([...header(challenge, 'WWW-Authenticate').matchAll(/(\w+)="?([^",]+)"?/g)].map((match) => [match[1], match[2]]));
      const nc = '00000001';
      const cnonce = Math.random().toString(16).slice(2);
      const ha1 = await window.utcpMd5(`${username}:${params.realm}:${password}`);
      const ha2 = await window.utcpMd5(`${method}:${uri}`);
      const response = await window.utcpMd5(`${ha1}:${params.nonce}:${nc}:${cnonce}:${params.qop || 'auth'}:${ha2}`);
      return `Digest username="${username}", realm="${params.realm}", nonce="${params.nonce}", uri="${uri}", response="${response}", algorithm=MD5, qop=${params.qop || 'auth'}, nc=${nc}, cnonce="${cnonce}"`;
    }

    async function collectStats(pc) {
      const stats = await pc.getStats();
      const aggregate = {
        outboundPackets: 0,
        outboundBytes: 0,
        inboundPackets: 0,
        inboundBytes: 0,
        audioEnergy: 0,
        jitter: 0,
        packetsLost: 0,
        dtlsState: '',
        selectedCandidatePair: null,
      };
      const candidatePairs = new Map();
      for (const report of stats.values()) {
        if (report.type === 'outbound-rtp' && report.kind === 'audio') {
          aggregate.outboundPackets += report.packetsSent || 0;
          aggregate.outboundBytes += report.bytesSent || 0;
        }
        if (report.type === 'inbound-rtp' && report.kind === 'audio') {
          aggregate.inboundPackets += report.packetsReceived || 0;
          aggregate.inboundBytes += report.bytesReceived || 0;
          aggregate.jitter += report.jitter || 0;
          aggregate.packetsLost += report.packetsLost || 0;
        }
        if (report.type === 'track' && report.kind === 'audio') {
          aggregate.audioEnergy += report.totalAudioEnergy || report.audioLevel || 0;
        }
        if (report.type === 'transport') {
          aggregate.dtlsState = report.dtlsState || aggregate.dtlsState;
          if (report.selectedCandidatePairId) {
            candidatePairs.set('selected', report.selectedCandidatePairId);
          }
        }
      }
      const pairId = candidatePairs.get('selected');
      if (pairId && stats.get(pairId)) {
        const pair = stats.get(pairId);
        const local = stats.get(pair.localCandidateId) || {};
        const remote = stats.get(pair.remoteCandidateId) || {};
        aggregate.selectedCandidatePair = {
          id: pair.id,
          localCandidateType: local.candidateType,
          localAddress: local.address || local.ip || '',
          remoteCandidateType: remote.candidateType,
          remoteAddress: remote.address || remote.ip || '',
        };
      }
      return aggregate;
    }

    function assertMediaCounters(before, after) {
      const failures = [];
      if (after.outboundPackets <= before.outboundPackets) failures.push('outbound RTP packet count did not increase');
      if (after.outboundBytes <= before.outboundBytes) failures.push('outbound RTP byte count did not increase');
      if (after.inboundPackets <= before.inboundPackets) failures.push('inbound RTP packet count did not increase');
      if (after.inboundBytes <= before.inboundBytes) failures.push('inbound RTP byte count did not increase');
      if (after.audioEnergy <= before.audioEnergy) failures.push('received audio energy did not increase');
      if (!after.selectedCandidatePair) failures.push('no selected ICE candidate pair');
      if (failures.length > 0) throw new Error(failures.join('; '));
    }

    function waitFor(predicate, timeoutMs, label) {
      const deadline = Date.now() + timeoutMs;
      return new Promise((resolve, reject) => {
        const poll = () => {
          if (predicate()) return resolve();
          if (Date.now() >= deadline) return reject(new Error(`timed out waiting for ${label}`));
          setTimeout(poll, 100);
        };
        poll();
      });
    }

    async function waitForMessage(messages, predicate, timeoutMs) {
      let cursor = 0;
      await waitFor(() => {
        for (; cursor < messages.length; cursor += 1) {
          if (predicate(messages[cursor])) return true;
        }
        return false;
      }, timeoutMs, 'SIP message');
      return messages[Math.max(0, cursor - 1)];
    }

    function header(message, name) {
      const match = message.match(new RegExp(`^${name}:\\s*(.*)$`, 'im'));
      return match ? match[1].trim() : '';
    }
    function headerLine(message, name) {
      const match = message.match(new RegExp(`^${name}:.*$`, 'im'));
      return match ? match[0] : `${name}:`;
    }
    function bodyOf(message) {
      return message.split(/\r?\n\r?\n/).slice(1).join('\r\n\r\n');
    }
    function sipStatus(message) {
      const match = message.match(/^SIP\/2\.0\s+(\d+)/);
      return match ? Number(match[1]) : 0;
    }
    function firstLine(message) {
      return message.split(/\r?\n/, 1)[0];
    }
    function parseTag(value) {
      const match = value.match(/;tag=([^;\s]+)/i);
      return match ? match[1] : '';
    }
    function parseRecordRoutes(message) {
      return (message.match(/^Record-Route:\s*(.*)$/gmi) || []).map((line) => line.replace(/^Record-Route:\s*/i, '').trim()).reverse();
    }
    function addressClass(address) {
      if (/^10\.|^172\.(1[6-9]|2\d|3[01])\.|^192\.168\./.test(address)) return 'private';
      if (/^127\.|^::1$/.test(address)) return 'loopback';
      if (!address) return 'unknown';
      return 'public-or-other';
    }
    function sleep(ms) {
      return new Promise((resolve) => setTimeout(resolve, ms));
    }
  }, redactConfigForBrowser(config));

  Object.assign(result, proof, { durationMs: Date.now() - started });
  assertStructuredResult(result);
  await writeResult(config.resultPath, result);
  console.log(`T3_MEDIA_PROVER_RESULT_JSON=${JSON.stringify(result)}`);
  process.exitCode = 0;
} catch (error) {
  result.errors.push(error instanceof Error ? error.message : String(error));
  result.cleanupResult = result.cleanupResult || 'failed-before-cleanup';
  result.durationMs = Date.now() - started;
  await writeResult(config.resultPath, result);
  console.log(`T3_MEDIA_PROVER_RESULT_JSON=${JSON.stringify(result)}`);
  process.exitCode = 1;
} finally {
  if (page) await page.close().catch(() => {});
  if (browser) await browser.close().catch(() => {});
}

function env(name, fallback) {
  return process.env[name] || fallback;
}

function requiredEnv(name) {
  const value = process.env[name];
  if (!value) {
    throw new Error(`${name} is required at execution time`);
  }
  return value;
}

async function resolveGatewayAddress(serviceName) {
  const lookup = await dns.lookup(serviceName, { family: 4 });
  return lookup.address;
}

async function naturalLogin(page) {
  await page.goto(`${config.applicationUrl}/login`, { waitUntil: 'domcontentloaded', timeout: config.callTimeoutMs });
  await fillFirst(page, ['input[name="email"]', 'input[type="email"]'], config.loginEmail);
  await fillFirst(page, ['input[name="password"]', 'input[type="password"]'], config.loginPassword);
  await Promise.all([
    page.waitForLoadState('networkidle', { timeout: config.callTimeoutMs }).catch(() => {}),
    clickFirst(page, ['button[type="submit"]', 'text=Sign in', 'text=Log in']),
  ]);
}

async function fillFirst(page, selectors, value) {
  for (const selector of selectors) {
    const locator = page.locator(selector).first();
    if (await locator.count()) {
      await locator.fill(value);
      return;
    }
  }
  throw new Error(`none of the expected login selectors exists: ${selectors.join(', ')}`);
}

async function clickFirst(page, selectors) {
  for (const selector of selectors) {
    const locator = page.locator(selector).first();
    if (await locator.count()) {
      await locator.click();
      return;
    }
  }
  throw new Error(`none of the expected login submit selectors exists: ${selectors.join(', ')}`);
}

function redactConfigForBrowser(raw) {
  return {
    scenario: raw.scenario,
    applicationUrl: raw.applicationUrl,
    sipWssUrl: raw.sipWssUrl,
    sipDomain: raw.sipDomain,
    extension: raw.extension,
    sipUsername: raw.sipUsername,
    sipPassword: raw.sipPassword,
    callTimeoutMs: raw.callTimeoutMs,
    mediaTimeoutMs: raw.mediaTimeoutMs,
    runtimeByeWaitMs: raw.runtimeByeWaitMs,
  };
}

function assertStructuredResult(output) {
  for (const field of REQUIRED_RESULT_FIELDS) {
    if (output[field] === undefined || output[field] === null || output[field] === '') {
      throw new Error(`structured result missing required field ${field}`);
    }
  }
  if (output.outboundRtpPackets <= 0 || output.outboundRtpBytes <= 0) {
    throw new Error('SDP-only success rejected: outbound RTP counters did not increase');
  }
  if (output.inboundRtpPackets <= 0 || output.inboundRtpBytes <= 0 || output.audioEnergy <= 0) {
    throw new Error('SDP-only success rejected: inbound RTP and audio energy were not proven');
  }
}

async function writeResult(resultPath, output) {
  await fs.mkdir(path.dirname(resultPath), { recursive: true });
  await fs.writeFile(resultPath, `${JSON.stringify(sanitize(output), null, 2)}\n`, { encoding: 'utf-8', mode: 0o600 });
}

function sanitize(value) {
  const text = JSON.stringify(value);
  return JSON.parse(text.replaceAll(config.loginPassword, '<redacted>').replaceAll(config.sipPassword, '<redacted>'));
}
