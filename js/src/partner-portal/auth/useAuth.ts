/**
 * Partner Auth consumer hook
 *
 * 從 AuthContext re-export，便於 pages/* 以單一路徑 import。
 */

export { useAuth } from './AuthContext'
export type { TAuthContextValue, TAuthStatus } from './AuthContext'
