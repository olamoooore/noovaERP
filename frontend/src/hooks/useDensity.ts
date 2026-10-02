import { useCallback, useState } from 'react';

export type Density = 'comfortable' | 'compact';

const STORAGE_KEY = 'noovaerp.document.density';

/**
 * 8.3 (Oct-2026 invoice review): two densities — Comfortable and Compact —
 * switchable per user and remembered in the browser. The document screen
 * applies the value via a `data-density` attribute; index.css owns the
 * compact rules so every screen can adopt the same tokens.
 */
export function useDensity(): [Density, (density: Density) => void] {
  const [density, setDensity] = useState<Density>(() => {
    try {
      return localStorage.getItem(STORAGE_KEY) === 'compact' ? 'compact' : 'comfortable';
    } catch {
      return 'comfortable';
    }
  });

  const update = useCallback((next: Density) => {
    setDensity(next);
    try {
      localStorage.setItem(STORAGE_KEY, next);
    } catch {
      // Storage unavailable (private mode) — the session still toggles.
    }
  }, []);

  return [density, update];
}