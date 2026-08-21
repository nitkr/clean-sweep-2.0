/**
 * Malware scan session pointer (client side of A + C-lite).
 *
 * Server holds real scan data under logs/ (checkpoint_*.json + scan_work/).
 * Browser only stores a small pointer so refresh can rehydrate via status/threats.
 */

const STORAGE_KEY = 'clean_sweep_malware_scan_session';

/** Completed results stay "hot" for UI restore (48 hours) */
export const COMPLETED_TTL_SECONDS = 48 * 3600;

/**
 * @typedef {object} ScanSessionPointer
 * @property {string} scan_id
 * @property {string} [profile_id]
 * @property {number} [started_at]  Unix seconds
 * @property {string} [last_status]
 * @property {number} [updated_at]  Unix seconds (client clock)
 * @property {number} [finished_at]
 */

/**
 * @returns {ScanSessionPointer|null}
 */
export function loadScanPointer() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return null;
    const data = JSON.parse(raw);
    if (!data || typeof data.scan_id !== 'string' || !data.scan_id) return null;
    return data;
  } catch (e) {
    console.warn('[SCAN_SESSION] load failed', e);
    return null;
  }
}

/**
 * @param {ScanSessionPointer} pointer
 */
export function saveScanPointer(pointer) {
  if (!pointer?.scan_id) return;
  try {
    const payload = {
      scan_id: pointer.scan_id,
      profile_id: pointer.profile_id || 'standard',
      started_at: pointer.started_at || Math.floor(Date.now() / 1000),
      last_status: pointer.last_status || 'running',
      finished_at: pointer.finished_at || null,
      updated_at: Math.floor(Date.now() / 1000),
    };
    localStorage.setItem(STORAGE_KEY, JSON.stringify(payload));
  } catch (e) {
    console.warn('[SCAN_SESSION] save failed', e);
  }
}

export function clearScanPointer() {
  try {
    localStorage.removeItem(STORAGE_KEY);
  } catch (e) {
    console.warn('[SCAN_SESSION] clear failed', e);
  }
}

/**
 * Whether a completed scan is still within the hot TTL.
 * @param {number|null|undefined} finishedAt Unix seconds
 * @param {number} [ttlSeconds]
 */
export function isCompletedWithinTtl(finishedAt, ttlSeconds = COMPLETED_TTL_SECONDS) {
  if (!finishedAt || finishedAt <= 0) return false;
  const age = Math.floor(Date.now() / 1000) - finishedAt;
  return age >= 0 && age <= ttlSeconds;
}

/**
 * Human label for result age.
 * @param {number|null|undefined} finishedAt
 */
export function formatScanAge(finishedAt) {
  if (!finishedAt) return null;
  const sec = Math.floor(Date.now() / 1000) - finishedAt;
  if (sec < 60) return 'just now';
  if (sec < 3600) return `${Math.floor(sec / 60)}m ago`;
  if (sec < 86400) return `${Math.floor(sec / 3600)}h ago`;
  return `${Math.floor(sec / 86400)}d ago`;
}
