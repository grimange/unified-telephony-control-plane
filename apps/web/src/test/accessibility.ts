import { run, type Result, type RunOptions } from 'axe-core'
import { nextTick } from 'vue'

const axeOptions: RunOptions = {
  resultTypes: ['violations'],
  rules: {
    // jsdom does not compute layout and paint contrast reliably; natural UI-E browser proof owns this.
    'color-contrast': { enabled: false },
  },
}

function formatViolation(violation: Result): string {
  const selectors = violation.nodes
    .flatMap((node) => node.target)
    .join(', ')

  return `${violation.id} [${violation.impact ?? 'unknown'}]: ${selectors || 'selector unavailable'}`
}

export async function assertNoSeriousAxeViolations(container: Element | Document): Promise<void> {
  await nextTick()
  await Promise.resolve()

  const axeContainer = container instanceof Document || container.isConnected
    ? container
    : container.cloneNode(true) as Element
  const detachedHost = axeContainer === container ? null : document.createElement('div')
  if (detachedHost) {
    detachedHost.append(axeContainer)
    document.body.append(detachedHost)
  }

  const results = await run(axeContainer, axeOptions).finally(() => {
    detachedHost?.remove()
  })
  const blockingViolations = results.violations.filter((violation) => (
    violation.impact === 'serious' || violation.impact === 'critical'
  ))

  if (blockingViolations.length > 0) {
    throw new Error([
      'Expected zero serious or critical accessibility violations.',
      ...blockingViolations.map(formatViolation),
    ].join('\n'))
  }
}
