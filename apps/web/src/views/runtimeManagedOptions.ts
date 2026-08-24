import type { RuntimeManagementCatalog } from '../api/platform'

export type ManagedRuntimeOption = {
  runtimeFamily: string
  adapterKey: string
  providerLabel: string
  adapterLabel: string
}

/** Derive managed-capable choices from catalog semantics, without a frontend provider list. */
export function managedRuntimeOptions(catalog: RuntimeManagementCatalog | null): ManagedRuntimeOption[] {
  if (!catalog) return []

  return Object.entries(catalog.runtime_families).flatMap(([runtimeFamily, family]) =>
    family.adapters
      .map((adapterKey) => {
        const adapter = catalog.adapter_keys[adapterKey]
        if (!adapter || adapter.runtime_family !== runtimeFamily || !adapter.credentials_required) return null

        return {
          runtimeFamily,
          adapterKey,
          providerLabel: family.display_name || runtimeFamily,
          adapterLabel: adapter.display_name || adapterKey,
        }
      })
      .filter((option): option is ManagedRuntimeOption => option !== null),
  )
}
