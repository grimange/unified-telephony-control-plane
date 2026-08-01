import assert from 'node:assert/strict';
import { createDialog, inDialogRequest, parseContactUri } from './sip-dialog.mjs';

const response = [
  'SIP/2.0 200 OK',
  'To: <sip:runtime@example.test>;tag=remote-42',
  'From: <sip:browser@example.test>;tag=local-7',
  'Call-ID: call-1',
  'CSeq: 2 INVITE',
  'Record-Route: <sip:proxy-a.example.test;lr>',
  'Record-Route: <sip:proxy-b.example.test;lr>',
  'Contact: <sip:10.42.1.254:5060;transport=udp>',
  '',
].join('\r\n');

const dialog = createDialog({
  response,
  initialRequestUri: 'sip:9900@sip.example.test',
  callId: 'call-1',
  localTag: 'local-7',
  inviteCseq: 2,
});

assert.equal(parseContactUri(response), 'sip:10.42.1.254:5060;transport=udp');
assert.equal(dialog.remoteTarget, 'sip:10.42.1.254:5060;transport=udp');
assert.deepEqual(dialog.routeSet, [
  '<sip:proxy-b.example.test;lr>',
  '<sip:proxy-a.example.test;lr>',
]);

const ack = inDialogRequest(dialog, 'ACK');
assert.equal(ack.requestUri, dialog.remoteTarget);
assert.equal(ack.cseq, 2);
assert.deepEqual(ack.routeSet, dialog.routeSet);
assert.equal(ack.callId, 'call-1');
assert.equal(ack.remoteTag, 'remote-42');

const bye = inDialogRequest(dialog, 'BYE');
assert.equal(bye.requestUri, dialog.remoteTarget);
assert.equal(bye.cseq, 3);
assert.notEqual(bye.requestUri, dialog.initialRequestUri);

assert.throws(() => parseContactUri(response.replace(/Contact:.*/i, '')), /missing a valid Contact/);
assert.throws(() => parseContactUri(response.replace(/Contact:.*/i, 'Contact: asterisk')), /missing a valid Contact/);
assert.throws(() => createDialog({ ...dialog, response: response.replace(/To:.*/i, 'To: <sip:runtime@example.test>') }), /missing a valid To/);

console.log('T3 media prover SIP dialog unit tests passed');
