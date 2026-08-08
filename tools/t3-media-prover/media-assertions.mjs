export const EXTERNAL_MEDIA_PORT_MIN = 40000;
export const EXTERNAL_MEDIA_PORT_MAX = 40099;

export function assertExternalMediaCandidate({ address, port, configuredAddress, forbiddenAddresses = [], privateRuntimeAddresses = [] }) {
  const failures = [];
  if (address !== configuredAddress) failures.push(`external media address ${address || '<empty>'} does not equal configured ${configuredAddress}`);
  if (!Number.isInteger(port) || port < EXTERNAL_MEDIA_PORT_MIN || port > EXTERNAL_MEDIA_PORT_MAX) failures.push(`external media port ${port || '<empty>'} is outside UDP ${EXTERNAL_MEDIA_PORT_MIN}-${EXTERNAL_MEDIA_PORT_MAX}`);
  if (forbiddenAddresses.includes(address)) failures.push(`external media address ${address} is a Pod or Service address`);
  if (privateRuntimeAddresses.includes(address)) failures.push(`external media address ${address} is a private runtime media address`);
  if (failures.length) throw new Error(failures.join('; '));
  return { address, port, portRange: `${EXTERNAL_MEDIA_PORT_MIN}-${EXTERNAL_MEDIA_PORT_MAX}`, fallback: false };
}

export function parseAddressList(value = '') {
  return value.split(',').map((item) => item.trim()).filter(Boolean);
}
