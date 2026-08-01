export function parseHeader(message, name) {
  const match = message.match(new RegExp(`^${name}:\\s*(.*)$`, 'im'));
  return match ? match[1].trim() : '';
}

export function parseContactUri(message) {
  const value = parseHeader(message, 'Contact');
  const match = value.match(/<((?:sips?):[^>\s]+)>/i) || value.match(/((?:sips?):[^;\s]+)/i);
  if (!match) throw new Error('successful SIP response is missing a valid Contact URI');
  return match[1];
}

export function parseRecordRouteSet(message) {
  return (message.match(/^Record-Route:\s*(.*)$/gmi) || [])
    .map((line) => line.replace(/^Record-Route:\s*/i, '').trim())
    .reverse();
}

export function parseTag(value) {
  const match = value.match(/;tag=([^;\s]+)/i);
  return match ? match[1] : '';
}

export function createDialog({ response, initialRequestUri, callId, localTag, inviteCseq }) {
  const to = parseHeader(response, 'To');
  const remoteUri = (to.match(/<([^>]+)>/) || [])[1];
  const remoteTag = parseTag(to);
  if (!remoteUri || !remoteTag) throw new Error('successful SIP response is missing a valid To dialog identity');
  return {
    callId,
    localTag,
    remoteTag,
    remoteUri,
    remoteTarget: parseContactUri(response),
    routeSet: parseRecordRouteSet(response),
    inviteCseq,
    initialRequestUri,
  };
}

export function inDialogRequest(dialog, method) {
  if (!dialog.remoteTarget) throw new Error('dialog remote target is required');
  return {
    method,
    requestUri: dialog.remoteTarget,
    toUri: dialog.remoteUri,
    routeSet: [...dialog.routeSet],
    cseq: method === 'ACK' ? dialog.inviteCseq : dialog.inviteCseq + 1,
    callId: dialog.callId,
    localTag: dialog.localTag,
    remoteTag: dialog.remoteTag,
  };
}
