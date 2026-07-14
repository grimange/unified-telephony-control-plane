export type FrontendBuildInfo = {
  version: string
  commit: string
  builtAt: string
}

export const frontendBuildInfo: FrontendBuildInfo = {
  version: import.meta.env.VITE_UTCP_WEB_VERSION ?? '0.1.0-dev',
  commit: import.meta.env.VITE_UTCP_WEB_COMMIT ?? 'unknown',
  builtAt: import.meta.env.VITE_UTCP_WEB_BUILT_AT ?? 'unknown',
}
