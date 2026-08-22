export type CatalogOption = { key: string; label: string }

export function catalogOptions(catalog: string[] | undefined): CatalogOption[] {
  return (catalog ?? []).map((value) => ({
    key: value,
    label: value,
  }))
}
