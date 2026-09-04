/**
 * Abstract API Adapter Interface
 * Defines the contract for any backend implementation
 */

import type {
  ApiResponse,
  ProgressUpdate,
  PluginAnalysisResponse,
  PluginReinstallResponse,
  ThemeAnalysisResponse,
  CoreReinstallResponse,
  ScanResults,
  CleanupResult
} from '../../shared/types/api.js';

/**
 * Request options for API calls
 */
export interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'DELETE';
  body?: FormData | Record<string, unknown> | string;
  headers?: Record<string, string>;
  timeout?: number;
  signal?: AbortSignal;
}

/**
 * Progress callback type
 */
export type ProgressCallback = (progress: ProgressUpdate) => void;

/**
 * Abstract API Adapter Interface
 * Any backend implementation must conform to this interface
 *
 * This enables swapping backends (PHP, Node.js, Python) without
 * changing the frontend code
 */
export interface IApiAdapter {
  /**
   * Generic request method
   * @param endpoint - API endpoint path
   * @param options - Request options
   * @returns Promise resolving to API response
   */
  request<T>(endpoint: string, options?: RequestOptions): Promise<ApiResponse<T>>;

  /**
   * Progress-aware request for long-running operations
   * @param endpoint - API endpoint path
   * @param options - Request options
   * @param onProgress - Callback for progress updates
   * @returns Promise resolving to API response
   */
  requestWithProgress<T>(
    endpoint: string,
    options: RequestOptions,
    onProgress: ProgressCallback
  ): Promise<ApiResponse<T>>;

  /**
   * File upload with progress tracking
   * @param endpoint - API endpoint path
   * @param file - File to upload
   * @param onProgress - Progress callback (0-100)
   * @returns Promise resolving to upload response
   */
  uploadFile(
    endpoint: string,
    file: File,
    onProgress?: (percent: number) => void
  ): Promise<ApiResponse<unknown>>;

  /**
   * Poll a progress file for updates
   * @param progressFile - Progress file path
   * @param onProgress - Progress callback
   * @param interval - Polling interval in ms
   * @returns Cancel function
   */
  pollProgress(
    progressFile: string,
    onProgress: ProgressCallback,
    interval?: number
  ): () => void;

  /**
   * Health check to verify connectivity
   * @returns Promise resolving to true if healthy
   */
  ping(): Promise<boolean>;
}

/** After Remove Clean Sweep succeeds, skip further API calls (endpoints are gone). */
let toolkitGone = false;

export function markToolkitGone(): void {
  toolkitGone = true;
}

export function isToolkitGone(): boolean {
  return toolkitGone;
}

/**
 * Parse JSON even when PHP shutdown output or flushed HTML trails the object.
 */
function parseJsonAllowingTrailer(text: string): unknown {
  const trimmed = text.trim();
  try {
    return JSON.parse(trimmed);
  } catch {
    const extracted = extractFirstJsonObject(trimmed);
    if (extracted) {
      return JSON.parse(extracted);
    }
    throw new SyntaxError('Unexpected non-JSON response');
  }
}

function extractFirstJsonObject(text: string): string | null {
  const start = text.indexOf('{');
  if (start < 0) {
    return null;
  }
  let depth = 0;
  let inStr = false;
  let escape = false;
  for (let i = start; i < text.length; i++) {
    const c = text[i];
    if (inStr) {
      if (escape) {
        escape = false;
        continue;
      }
      if (c === '\\') {
        escape = true;
        continue;
      }
      if (c === '"') {
        inStr = false;
      }
      continue;
    }
    if (c === '"') {
      inStr = true;
      continue;
    }
    if (c === '{') {
      depth++;
    } else if (c === '}') {
      depth--;
      if (depth === 0) {
        return text.slice(start, i + 1);
      }
    }
  }
  return null;
}

function isPhpDiagnosticHtml(trimmed: string): boolean {
  return (
    /^<br\s*\/?>/i.test(trimmed) ||
    /^<b>(Warning|Notice|Deprecated|Fatal error|Parse error)/i.test(trimmed) ||
    /^(Warning|Notice|Deprecated|Fatal error|Parse error)\s*:/i.test(trimmed)
  );
}

function phpDiagnosticSummary(text: string): string {
  const plain = text.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
  if (plain === '') {
    return 'PHP warning in server response';
  }
  return plain.length > 240 ? plain.slice(0, 240) + '…' : plain;
}

/** PHP warning HTML vs WAF/login document. Real firewall pages stay HTML_RESPONSE. */
function nonJsonBodyError(text: string, contentType?: string | null): ApiResponse<never> {
  const trimmed = text.trim();
  const details = { responseText: text.substring(0, 1000) };
  const timestamp = Date.now();
  if (isPhpDiagnosticHtml(trimmed)) {
    return {
      success: false,
      error: phpDiagnosticSummary(text),
      code: 'PHP_WARNING',
      details,
      timestamp
    };
  }
  const extra = contentType ? ` (Content-Type: ${contentType})` : '';
  return {
    success: false,
    error: `Server returned HTML instead of JSON${extra}. This may indicate a security plugin or firewall blocking the request.`,
    code: 'HTML_RESPONSE',
    details,
    timestamp
  };
}

/**
 * Default adapter implementation using fetch
 */
export class HttpApiAdapter implements IApiAdapter {
  private baseUrl: string;
  private defaultHeaders: Record<string, string>;

  constructor(baseUrl: string = '') {
    this.baseUrl = baseUrl;
    this.defaultHeaders = {
      'X-Requested-With': 'XMLHttpRequest'
    };
  }

  /**
   * Build full URL from endpoint
   */
  private buildUrl(endpoint: string): string {
    const fullUrl = endpoint.startsWith('http') ? endpoint : this.baseUrl + endpoint;
    console.log('[ADAPTER] buildUrl called with endpoint:', endpoint, '-> fullUrl:', fullUrl);
    return fullUrl;
  }

  /**
   * Build request body from various input types
   */
  private buildBody(
    body?: FormData | Record<string, unknown> | string
  ): FormData | string | undefined {
    if (!body) return undefined;

    if (body instanceof FormData) {
      return body;
    }

    if (typeof body === 'string') {
      return body;
    }

    // Convert object to FormData
    const formData = new FormData();
    for (const [key, value] of Object.entries(body)) {
      if (value !== undefined && value !== null) {
        if (value instanceof File) {
          formData.append(key, value);
        } else if (typeof value === 'object') {
          formData.append(key, JSON.stringify(value));
        } else {
          formData.append(key, String(value));
        }
      }
    }
    return formData;
  }

  /**
   * Generic request method
   */
  async request<T>(endpoint: string, options: RequestOptions = {}): Promise<ApiResponse<T>> {
    if (toolkitGone) {
      return {
        success: false,
        error: 'Clean Sweep has been removed from this server.',
        code: 'TOOLKIT_GONE',
        timestamp: Date.now()
      };
    }
    const url = this.buildUrl(endpoint);
    const headers = { ...this.defaultHeaders, ...options.headers };
    try {
      const vk = sessionStorage.getItem('cs_visit_key');
      if (vk) {
        headers['X-CS-Visit-Key'] = vk;
      }
    } catch (_) { /* ignore */ }

    // Don't set Content-Type for FormData - browser does it automatically
    const requestBody = this.buildBody(options.body);
    
    // Debug: Log what's being sent
    if (requestBody instanceof FormData) {
      console.log('[ADAPTER] FormData entries:');
      for (const [key, value] of requestBody.entries()) {
        if (key === 'repo_plugins') {
          console.log('[ADAPTER] repo_plugins:', value);
        } else if (key === 'snapshot' && typeof value === 'string' && value.length > 200) {
          console.log('[ADAPTER]', key, ':', value.slice(0, 120) + '… (' + value.length + ' chars)');
        } else if (value instanceof File) {
          console.log('[ADAPTER]', key, ': [File]', value.name, value.size, 'bytes');
        } else {
          console.log('[ADAPTER]', key, ':', value);
        }
      }
    }
    
    if (requestBody instanceof FormData) {
      delete headers['Content-Type'];
    }

    console.log('[ADAPTER] About to fetch URL:', url);
    console.log('[ADAPTER] Request options:', JSON.stringify({
      method: options.method || 'POST',
      hasBody: !!requestBody,
      headers: Object.keys(headers)
    }));
    
    try {
      const response = await fetch(url, {
        method: options.method || 'POST',
        body: requestBody,
        headers,
        signal: options.signal
      });

      console.log('[ADAPTER] fetch completed, status:', response.status, 'statusText:', response.statusText);
      const contentType = response.headers.get('content-type');
      console.log('[ADAPTER] Content-Type header:', contentType);

      if (!response.ok) {
        const errorText = await response.text();
        console.error('[ADAPTER] HTTP error response text:', errorText.substring(0, 500));
        // API always uses HTTP 400 for structured JSON errors (ApiResponse::sendError).
        // Preserve code/message so callers can handle SNAPSHOT_REQUIRED, etc.
        try {
          const trimmed = errorText.trim();
          if (trimmed.startsWith('{') || trimmed.startsWith('[')) {
            const parsed = JSON.parse(trimmed);
            if (parsed && typeof parsed === 'object') {
              if (parsed.toolkit_integrity) {
                import('../stores/toolkit.js')
                  .then(({ toolkit }) => toolkit.apply(parsed.toolkit_integrity))
                  .catch(() => {});
              }
              return {
                success: false,
                error: parsed.error || parsed.message || `HTTP ${response.status}`,
                code: parsed.code || 'HTTP_ERROR',
                details: parsed.details ?? { status: response.status },
                data: parsed.data,
                timestamp: parsed.timestamp || Date.now()
              } as ApiResponse<T>;
            }
          }
        } catch (_) {
          // fall through to generic HTTP error
        }
        return {
          success: false,
          error: `HTTP ${response.status}: ${response.statusText || errorText.substring(0, 120)}`,
          code: 'HTTP_ERROR',
          details: { errorText, status: response.status },
          timestamp: Date.now()
        };
      }
      
      // If not JSON, check if it's actually HTML (not just PHP code in JSON)
      if (!contentType?.includes('application/json')) {
        const text = await response.text();
        console.error('[ADAPTER] Non-JSON response detected!');
        console.error('[ADAPTER] Content-Type header:', contentType);
        console.error('[ADAPTER] Response status:', response.status);
        console.error('[ADAPTER] Full response text:', text);
        
        // Check if it's actually HTML (not JSON that happens to contain <)
        const trimmed = text.trim();
        const isHtml = trimmed.startsWith('<!DOCTYPE') || 
                       trimmed.startsWith('<html') ||
                       (trimmed.startsWith('<') && !trimmed.startsWith('{"'));
        
        if (isHtml) {
          return nonJsonBodyError(text, contentType);
        }
        
        return {
          success: true,
          data: text as unknown as T,
          timestamp: Date.now()
        };
      }

      // JSON content-type handling
      if (contentType?.includes('application/json')) {
        const text = await response.text();
        console.log('[ADAPTER] Raw response text:', text.substring(0, 1000));
        
        // Check for actual HTML markers (not just < in PHP code)
        const trimmed = text.trim();
        const hasPhpWarning = /^<br\s*\/?>/i.test(trimmed) || trimmed.startsWith('<b>Warning') || trimmed.startsWith('Warning:');
        const hasHtmlBefore = trimmed.startsWith('<!DOCTYPE') || 
                            trimmed.startsWith('<html') ||
                            hasPhpWarning ||
                            (trimmed.startsWith('<') && !trimmed.startsWith('{"') && !trimmed.startsWith('{\n'));
        
        // If there's HTML before JSON, try to extract JSON
        let jsonText = text;
        if (hasHtmlBefore || (!trimmed.startsWith('{') && !trimmed.startsWith('['))) {
          const firstBrace = text.indexOf('{');
          const lastBrace = text.lastIndexOf('}');
          if (firstBrace >= 0 && lastBrace > firstBrace) {
            jsonText = text.substring(firstBrace, lastBrace + 1);
            console.log('[ADAPTER] Extracted JSON from hybrid response:', jsonText.substring(0, 200));
          }
        }
        
        const isJsonLike = jsonText.trim().startsWith('{') || jsonText.trim().startsWith('[');
        
        if (!isJsonLike) {
          console.error('[ADAPTER] HTML response detected!');
          console.error('[ADAPTER] Content-Type header:', contentType);
          console.error('[ADAPTER] Response status:', response.status);
          console.error('[ADAPTER] Full response text:', text.substring(0, 500));
          return nonJsonBodyError(text, contentType);
        }
        
        const json = parseJsonAllowingTrailer(jsonText) as ApiResponse<T> & { toolkit_integrity?: unknown; visit_key?: string; data?: { visit_key?: string } };
        if (json && json.toolkit_integrity) {
          import('../stores/toolkit.js').then(({ toolkit }) => {
            toolkit.apply(json.toolkit_integrity);
          }).catch(() => {});
        }
        const vk = json?.data?.visit_key || json?.visit_key;
        if (vk && typeof vk === 'string') {
          try { sessionStorage.setItem('cs_visit_key', vk); } catch (_) { /* ignore */ }
        }
        console.log('[ADAPTER] Parsed JSON response:', JSON.stringify(json).substring(0, 500));
        
        // Debug: Log the full response structure
        console.log('[ADAPTER] response.success:', json.success);
        console.log('[ADAPTER] response.error:', json.error);
        console.log('[ADAPTER] response.code:', json.code);
        if (json.data) {
          console.log('[ADAPTER] response.data:', JSON.stringify(json.data).substring(0, 500));
        }
        
        return json as ApiResponse<T>;
      }
    } catch (e) {
      return {
        success: false,
        error: e instanceof Error ? e.message : 'Unknown error',
        code: 'NETWORK_ERROR',
        timestamp: Date.now()
      };
    }
  }

  /**
   * Request with progress polling
   */
  async requestWithProgress<T>(
    endpoint: string,
    options: RequestOptions,
    onProgress: ProgressCallback
  ): Promise<ApiResponse<T>> {
    // First make the initial request
    const response = await this.request<T>(endpoint, options);

    if (!response.success || !response.data) {
      return response;
    }

    // Get progress file from response if available
    // @ts-ignore - dynamic response structure
    const progressFile = response.progress_file || response.data?.progress_file;

    if (progressFile) {
      // Start polling
      this.pollProgress(progressFile, onProgress);
    }

    return response;
  }

  /**
   * File upload with progress
   */
  async uploadFile(
    endpoint: string,
    file: File,
    onProgress?: (percent: number) => void
  ): Promise<ApiResponse<unknown>> {
    const formData = new FormData();
    formData.append('action', 'upload_zip');
    formData.append('file', file);

    // If no progress callback, use simple request
    if (!onProgress) {
      return this.request(endpoint, {
        method: 'POST',
        body: formData
      });
    }

    // XMLHttpRequest for progress tracking
    return new Promise((resolve) => {
      const xhr = new XMLHttpRequest();

      xhr.upload.addEventListener('progress', (e) => {
        if (e.lengthComputable) {
          const percent = Math.round((e.loaded / e.total) * 100);
          onProgress(percent);
        }
      });

      xhr.addEventListener('load', () => {
        // Check for HTML response (security plugin blocking, error page, etc.)
        const responseText = xhr.responseText;
        const isHtml = responseText.trim().startsWith('<') || 
                       responseText.includes('<!DOCTYPE') || 
                       responseText.includes('<html');
        
        if (isHtml && xhr.status >= 200 && xhr.status < 300) {
          console.warn('[UPLOAD] Received HTML instead of JSON');
          resolve(nonJsonBodyError(responseText));
          return;
        }
        
        try {
          const json = JSON.parse(responseText);
          if (json && json.toolkit_integrity) {
            import('../stores/toolkit.js').then(({ toolkit }) => {
              toolkit.apply(json.toolkit_integrity);
            }).catch(() => {});
          }
          const vk = json?.data?.visit_key || json?.visit_key;
          if (vk && typeof vk === 'string') {
            try { sessionStorage.setItem('cs_visit_key', vk); } catch (_) { /* ignore */ }
          }
          resolve(json as ApiResponse<unknown>);
        } catch {
          resolve({
            success: xhr.status >= 200 && xhr.status < 300,
            data: xhr.responseText,
            timestamp: Date.now()
          });
        }
      });

      xhr.addEventListener('error', () => {
        resolve({
          success: false,
          error: 'Upload failed',
          code: 'UPLOAD_ERROR',
          timestamp: Date.now()
        });
      });

      xhr.open('POST', this.buildUrl(endpoint));
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      try {
        const vk = sessionStorage.getItem('cs_visit_key');
        if (vk) {
          xhr.setRequestHeader('X-CS-Visit-Key', vk);
        }
      } catch (_) { /* ignore */ }
      xhr.send(formData);
    });
  }

  /**
   * Poll for progress updates via API endpoint
   * Switched from static file polling to avoid 404 errors that trigger firewalls
   */
  pollProgress(
    progressFile: string,
    onProgress: ProgressCallback,
    interval: number = 3000
  ): () => void {
    console.log('🔍 [ADAPTER] pollProgress() called, progressFile:', progressFile, 'interval:', interval);
    let cancelled = false;

    console.log('[POLL] Starting poll for:', progressFile, 'interval:', interval);

    const poll = async () => {
      if (cancelled) {
        console.log('[POLL] Cancelled, returning early');
        return;
      }

      try {
        // Use API endpoint instead of direct file access to avoid 404 errors
        // The endpoint will read the progress file server-side and return JSON
        const endpoint = 'api/bootstrap.php';
        const params = new URLSearchParams({
          action: 'get_progress',
          progress_file: progressFile
        });
        
        const url = `${endpoint}?${params}`;
        console.log('[POLL] Fetching:', url);
        
        const response = await fetch(url, {
          method: 'GET',
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          }
        });

        console.log('[POLL] Response status:', response.status);

        if (response.status === 404 || response.status === 500) {
          // Server error or endpoint not found - retry polling
          console.log('[POLL] Error response, retrying...');
          if (!cancelled) {
            setTimeout(poll, interval);
          }
          return;
        }

        // Check content-type before parsing JSON
        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
          // Not JSON response - probably HTML redirect
          console.warn('[POLL] Non-JSON response, content-type:', contentType);
          // Stop polling - we've likely been redirected to a login page or error page
          return;
        }
        
        // Additional validation: ensure response actually looks like JSON
        const responseText = await response.text();
        const isJsonLike = responseText.trim().startsWith('{') || responseText.trim().startsWith('[');
        if (!isJsonLike) {
          console.warn('[POLL] Content-type says JSON but response looks like HTML:', responseText.substring(0, 100));
          return;
        }
        
        const data = JSON.parse(responseText);
        console.log('[POLL] Raw response:', data);
        
        // Handle API response format - progress data is in 'data' field
        const progressData = data.data || data;
        console.log('[POLL] Parsed progressData:', progressData);
        
        // Check if we got a valid progress response
        const isValid = progressData && (progressData.status || progressData.progress !== undefined);
        console.log('[POLL] Is valid progress:', isValid, 'status:', progressData?.status, 'progress:', progressData?.progress);
        
        if (isValid) {
          onProgress(progressData as ProgressUpdate);

          if (
            progressData.status === 'complete' ||
            progressData.status === 'error' ||
            progressData.status === 'cancelled' ||
            cancelled
          ) {
            console.log('[POLL] Progress complete/error/cancelled, stopping');
            return; // Stop polling
          }
        }

        if (!cancelled) {
          console.log('[POLL] Scheduling next poll');
          setTimeout(poll, interval);
        }
      } catch (e) {
        console.error('[POLL] Exception:', e);
        if (!cancelled) {
          setTimeout(poll, interval);
        }
      }
    };

    // Start polling
    poll();

    // Return cancel function
    return () => {
      console.log('[POLL] Cancel function called');
      cancelled = true;
    };
  }

  /**
   * Poll for progress updates using a direct URL.
   * Used for background scans where the URL includes scan_id.
   */
  pollProgressByUrl(
    pollUrl: string,
    onProgress: ProgressCallback,
    interval: number = 3000
  ): () => void {
    console.log('🔍 [ADAPTER] pollProgressByUrl() called, URL:', pollUrl, 'interval:', interval);
    let cancelled = false;
    let consecutiveErrors = 0;
    const baseInterval = interval;

    const nextDelay = () => {
      // Back off on gateway errors so we don't stampede a recovering host
      if (consecutiveErrors <= 0) return baseInterval;
      const factor = Math.min(6, consecutiveErrors);
      return Math.min(30000, baseInterval * Math.pow(2, factor - 1));
    };

    const poll = async () => {
      if (cancelled) return;

      try {
        const response = await fetch(pollUrl, {
          method: 'GET',
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        const contentType = response.headers.get('content-type') || '';

        // Gateway timeout / HTML error: keep polling with backoff.
        // Do NOT treat as scan death — checkpoint may still be alive.
        if (response.status >= 500 || !contentType.includes('application/json')) {
          consecutiveErrors++;
          console.warn(
            '[POLL] Host error (will retry, scan not cancelled):',
            'status:', response.status,
            'content-type:', contentType,
            'backoff_ms:', nextDelay()
          );
          // Soft signal so the UI can show "host busy" without ending the scan.
          // Keep progress undefined omitted so UI HWM is not wiped; use a flag only.
          onProgress({
            status: 'paused',
            progress: -1, // sentinel: store ignores for HWM / display
            phase: 'host_timeout',
            message: response.status === 504
              ? 'Host gateway timeout. Retrying (scan checkpoint kept).'
              : `Host error ${response.status}. Retrying.`,
            pause_reason: 'gateway_timeout',
            _host_error: true,
            _http_status: response.status,
          } as ProgressUpdate);
          if (!cancelled) setTimeout(poll, nextDelay());
          return;
        }

        consecutiveErrors = 0;
        const data = await response.json();
        const progressData = data.data || data;

        if (progressData && (progressData.status || progressData.progress !== undefined)) {
          onProgress(progressData as ProgressUpdate);

          // Do NOT stop on not_found — mid-scan checkpoint races can return it
          // transiently. The scanning store soft-handles and cancels the poller
          // only after sustained not_found while still "scanning".
          if (
            progressData.status === 'complete' ||
            progressData.status === 'completed' ||
            progressData.status === 'error' ||
            progressData.status === 'failed' ||
            progressData.status === 'cancelled'
          ) {
            return;
          }
        }

        if (!cancelled) setTimeout(poll, baseInterval);
      } catch (e) {
        consecutiveErrors++;
        console.error('[POLL] Exception (will retry):', e);
        onProgress({
          status: 'paused',
          phase: 'host_timeout',
          message: 'Network error. Retrying (scan not cancelled).',
          pause_reason: 'network_error',
          _host_error: true,
        } as ProgressUpdate);
        if (!cancelled) setTimeout(poll, nextDelay());
      }
    };

    poll();

    return () => {
      cancelled = true;
    };
  }

  /**
   * Health check
   */
  async ping(): Promise<boolean> {
    try {
      // Try to fetch a simple endpoint
      const response = await fetch(this.buildUrl('api/info'), {
        method: 'GET'
      });
      return response.ok;
    } catch {
      return false;
    }
  }
}

// Default adapter instance
export const defaultAdapter = new HttpApiAdapter();
