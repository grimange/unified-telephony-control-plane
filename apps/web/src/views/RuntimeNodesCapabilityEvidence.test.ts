import { describe, expect, it } from 'vitest'
import runtimeNodesViewSource from './RuntimeNodesView.vue?raw'

describe('RuntimeNode capability evidence presentation', () => {
  it('keeps declared and observed capability authority visibly separate', () => {
    expect(runtimeNodesViewSource).toContain('Capability evidence')
    expect(runtimeNodesViewSource).toContain('Declared:')
    expect(runtimeNodesViewSource).toContain('Observed:')
    expect(runtimeNodesViewSource).toContain('declared_not_observed')
    expect(runtimeNodesViewSource).toContain('observed_not_declared')
  })

  it('renders no observation as unknown rather than an empty observed set', () => {
    expect(runtimeNodesViewSource).toContain('Not yet observed')
    expect(runtimeNodesViewSource).toContain('capabilities.observed === null')
    expect(runtimeNodesViewSource).toContain('capabilities.freshness')
  })
})
