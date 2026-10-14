/**
 * Group AI verdicts the way the malware results list groups a package,
 * and render a plain-text report.
 */

function sitePath(path) {
  return String(path || '').replace(/\\/g, '/').replace(/^\/+/, '');
}

export function placeOf(path) {
  const rel = sitePath(path);
  const plugin = rel.match(/(?:^|\/)wp-content\/plugins\/([^/]+)(\/|$)/i);
  if (plugin) {
    if (!plugin[2] && plugin[1].includes('.')) {
      return { key: 'plugin:beside', title: 'Plugins folder' };
    }
    return { key: `plugin:${plugin[1].toLowerCase()}`, title: `Plugin · ${plugin[1]}` };
  }
  const theme = rel.match(/(?:^|\/)wp-content\/themes\/([^/]+)(\/|$)/i);
  if (theme) {
    if (!theme[2] && theme[1].includes('.')) {
      return { key: 'theme:beside', title: 'Themes folder' };
    }
    return { key: `theme:${theme[1].toLowerCase()}`, title: `Theme · ${theme[1]}` };
  }
  const lower = rel.toLowerCase();
  if (lower.includes('wp-content/mu-plugins/')) {
    return { key: 'mu', title: 'Must-use plugins' };
  }
  if (lower.includes('wp-content/uploads/')) {
    return { key: 'uploads', title: 'Uploads' };
  }
  if (lower.includes('wp-includes/') || lower.includes('wp-admin/')) {
    return { key: 'core', title: 'WordPress core' };
  }
  if (/(^|\/)wp-config\.php$/i.test(rel)) {
    return { key: 'config', title: 'Config' };
  }
  const slash = rel.lastIndexOf('/');
  if (slash > 0) {
    const dir = rel.slice(0, slash);
    return { key: `dir:${dir.toLowerCase()}`, title: dir };
  }
  return { key: `file:${lower || 'site'}`, title: rel || 'Site file' };
}

export function groupsOf(rows) {
  const map = new Map();
  for (const row of rows || []) {
    const place = placeOf(row?.path);
    if (!map.has(place.key)) {
      map.set(place.key, { key: place.key, title: place.title, rows: [] });
    }
    map.get(place.key).rows.push(row);
  }
  for (const group of map.values()) {
    group.rows.sort((a, b) => String(a.path || '').localeCompare(String(b.path || '')));
  }
  return [...map.values()].sort((a, b) => b.rows.length - a.rows.length || a.title.localeCompare(b.title));
}

/**
 * Path inside the plugin, theme, or folder placeOf names.
 * A package file keeps the directories under that package so they can nest.
 */
export function pathWithin(path) {
  const rel = sitePath(path);
  const plugin = rel.match(/(?:^|\/)wp-content\/plugins\/([^/]+)(?:\/(.*))?$/i);
  if (plugin) return plugin[2] || plugin[1];
  const theme = rel.match(/(?:^|\/)wp-content\/themes\/([^/]+)(?:\/(.*))?$/i);
  if (theme) return theme[2] || theme[1];
  const mu = rel.match(/(?:^|\/)wp-content\/mu-plugins\/(.*)$/i);
  if (mu) return mu[1];
  const uploads = rel.match(/(?:^|\/)wp-content\/uploads\/(.*)$/i);
  if (uploads) return uploads[1];
  const core = rel.match(/(?:^|\/)((?:wp-admin|wp-includes)\/.*)$/i);
  if (core) return core[1];
  const slash = rel.lastIndexOf('/');
  return slash >= 0 ? rel.slice(slash + 1) : rel;
}

const OUTCOME_ORDER = [
  'Not sent — secret file',
  'Not sent — your skip list',
  'Not sent — file missing',
  'Not sent — could not encode',
  'Not sent — database off',
  'Sent, values masked',
  'Sent',
];

const OUTCOME_SHORT = {
  'Sent': 'sent',
  'Sent, values masked': 'values masked',
  'Not sent — secret file': 'secret held',
  'Not sent — your skip list': 'on the skip list',
  'Not sent — database off': 'database off',
  'Not sent — file missing': 'missing',
  'Not sent — could not encode': 'not encoded',
};

/** Short outcome tally for a group header. Plain "sent" is left off when it is the only outcome. */
export function outcomeNote(rows) {
  const counts = new Map();
  for (const row of rows || []) {
    const key = String(row?.outcome || '');
    if (!key) continue;
    counts.set(key, (counts.get(key) || 0) + 1);
  }
  if (counts.size === 0 || (counts.size === 1 && counts.has('Sent'))) return '';
  const keys = [...counts.keys()].sort((a, b) => {
    const ia = OUTCOME_ORDER.indexOf(a);
    const ib = OUTCOME_ORDER.indexOf(b);
    return (ia < 0 ? 99 : ia) - (ib < 0 ? 99 : ib);
  });
  return keys.map((key) => {
    const label = OUTCOME_SHORT[key] || key;
    return counts.size === 1 ? label : `${counts.get(key)} ${label}`;
  }).join(' · ');
}

/**
 * One scan-results group, with a folder level when several files share the next directory.
 * A folder of one file stays a row, labeled with that directory.
 */
export function nestLogGroup(group) {
  const buckets = new Map();
  const loose = [];
  for (const row of group?.rows || []) {
    const within = pathWithin(row?.path);
    const slash = within.indexOf('/');
    if (slash <= 0) {
      loose.push({ ...row, label: within || row?.path || '' });
      continue;
    }
    const folder = within.slice(0, slash);
    if (!buckets.has(folder)) buckets.set(folder, []);
    buckets.get(folder).push({ ...row, label: within.slice(slash + 1) });
  }
  const folders = [];
  for (const [title, rows] of buckets) {
    if (rows.length < 2) {
      for (const row of rows) loose.push({ ...row, label: `${title}/${row.label}` });
      continue;
    }
    rows.sort((a, b) => String(a.label).localeCompare(String(b.label)));
    folders.push({
      key: `${group.key}/${title.toLowerCase()}`,
      title,
      rows,
    });
  }
  folders.sort((a, b) => b.rows.length - a.rows.length || a.title.localeCompare(b.title));
  loose.sort((a, b) => String(a.label).localeCompare(String(b.label)));
  return {
    key: group.key,
    title: group.title,
    rows: group.rows,
    folders,
    loose,
  };
}

export function infectionTimeLabel(row) {
  const basis = row?.file_time_basis || '';
  if (!row?.file_time || basis === 'unknown') return 'Infection time not known.';
  if (basis === 'write_agreed') return `Write time ${row.file_time}`;
  if (basis === 'inode_change_mtime_set_back') return `Inode-change time ${row.file_time}. Modified time was set backward.`;
  if (basis === 'watch') return `Watch time ${row.file_time}`;
  return String(row.file_time);
}

function knownTime(row) {
  const label = infectionTimeLabel(row);
  return label === 'Infection time not known.' ? '' : label;
}

export function splitFindings(source) {
  if (!source) return { dangerous: [], undecided: [], safe: [] };
  const hasSplit = Array.isArray(source.dangerous) || Array.isArray(source.undecided) || Array.isArray(source.safe);
  if (hasSplit) {
    return {
      dangerous: source.dangerous || [],
      undecided: source.undecided || [],
      safe: source.safe || [],
    };
  }
  const dangerous = [];
  const undecided = [];
  const safe = [];
  for (const row of source.malware || []) {
    if (row.verdict === 'malware') dangerous.push(row);
    else if (row.verdict === 'likely_safe') safe.push(row);
    else undecided.push(row);
  }
  return { dangerous, undecided, safe };
}

const NAMES_NOTHING = 'The excerpt does not name another local file.';

/**
 * The small hosting-log line under the counts.
 * An empty stored note means the infection summary names the requests.
 * An older report without the field keeps the short line.
 */
export function logsNote(summary) {
  if (summary && Object.prototype.hasOwnProperty.call(summary, 'logs_note')) {
    return String(summary.logs_note || '').trim();
  }
  return summary?.logs === 'checked' ? 'Request logs were checked.' : 'The timeline was not checked.';
}

/**
 * The sentence under the dangerous-file count.
 * An empty folder list says nothing. Unreviewed code in those folders is named.
 */
export function folderReviewSentence(folders) {
  if (!Array.isArray(folders) || folders.length === 0) return '';
  let waiting = 0;
  for (const folder of folders) waiting += Number(folder?.unreviewed) || 0;
  if (waiting > 0) return 'Some code files in those folders were not reviewed.';
  return 'The other code files in the same folder were reviewed.';
}

/**
 * One line per dangerous file that the request logs recorded.
 * The time is the earliest kept request.
 */
export function requestSummary(rows) {
  const lines = [];
  for (const row of rows || []) {
    const path = String(row?.path || '').trim();
    const when = String(row?.first_request_at || '').trim();
    if (!path || !when) continue;
    lines.push(`${path} was requested at ${when}.`);
  }
  return lines;
}

/**
 * Infection-summary lines for follow-up targets.
 * A card still uses each row's own text. This view does not.
 * When every dangerous file names nothing else, that is one line.
 * A file that names a path or host is named in front of that result.
 */
export function spreadSummary(items) {
  const rows = (items || []).filter((item) => item && item.text);
  if (!rows.length) return [];
  const named = rows.filter((item) => item.text !== NAMES_NOTHING);
  const noneCount = rows.length - named.length;
  const lines = named.map((item) => {
    const from = String(item.from || '').trim();
    const text = String(item.text);
    if (!from) return text;
    if (text.startsWith('Names ') || text.startsWith('Contacts ')) {
      return `${from} ${text.charAt(0).toLowerCase()}${text.slice(1)}`;
    }
    return `${from}: ${text}`;
  });
  if (noneCount > 0 && named.length === 0) {
    return ['None of these files name another local file.'];
  }
  if (noneCount > 0) {
    lines.push('The other dangerous files do not name another local file.');
  }
  return lines;
}

function fileBlock(row, spread, includeTime = false) {
  const lines = [`  ${row.path || ''}`];
  if (row.summary) lines.push(`    ${row.summary}`);
  if (row.pattern) lines.push(`    ${row.pattern}${row.match ? ` · ${row.match}` : ''}`);
  if (includeTime) {
    const time = knownTime(row);
    if (time) lines.push(`    ${time}`);
    if (row.first_request_at) lines.push(`    First request ${row.first_request_at}`);
  }
  for (const item of spread || []) {
    if (item.from === row.path && item.text) lines.push(`    ${item.text}`);
  }
  return lines;
}

function groupedSection(title, rows) {
  const groups = groupsOf(rows);
  const lines = ['', `${title} (${rows.length})`, ''];
  if (groups.length === 0) {
    lines.push('  None.');
    return lines;
  }
  for (const group of groups) {
    lines.push(`${group.title} (${group.rows.length})`);
    for (const row of group.rows) {
      lines.push(...fileBlock(row, []));
    }
    lines.push('');
  }
  return lines;
}

function formatCount(value) {
  return Number(value).toLocaleString('en-US');
}

function money(amount) {
  const n = Number(amount);
  if (!Number.isFinite(n)) return '';
  const digits = Math.abs(n) >= 0.01 || n === 0 ? 2 : 4;
  return `$${n.toFixed(digits)}`;
}

function windowShare(largest, window) {
  const share = (Number(largest) / window) * 100;
  if (!Number.isFinite(share) || share < 0) return '';
  if (share < 0.5) return 'under 1% of the context window';
  const rounded = share >= 10 ? Math.round(share) : Math.round(share * 10) / 10;
  const text = Number.isInteger(rounded) ? String(rounded) : rounded.toFixed(1);
  return `${text}% of the context window`;
}

function gapOf(usage) {
  if (usage.apart != null && usage.apart !== '' && Number.isFinite(Number(usage.apart))) {
    return Math.max(0, Number(usage.apart));
  }
  if (usage.input == null || usage.output == null || usage.total == null) return 0;
  if (Number(usage.input_missing) > 0 || Number(usage.output_missing) > 0 || Number(usage.total_missing) > 0) return 0;
  return Math.max(0, Number(usage.total) - Number(usage.input) - Number(usage.output));
}

function countNoun(count, singular, plural) {
  const n = Number(count) || 0;
  return `${formatCount(n)} ${n === 1 ? singular : plural}`;
}

function phaseValue(requests, input, output) {
  const bits = [countNoun(requests, 'request', 'requests')];
  if (input != null) bits.push(`${formatCount(input)} input`);
  if (output != null) bits.push(`${formatCount(output)} output`);
  return bits.join(', ');
}

function estimateFact(usage, price, apart) {
  const input = Number(usage.input);
  const output = Number(usage.output);
  const inputRate = Number(price.input);
  const outputRate = Number(price.output);
  if (!Number.isFinite(input) || !Number.isFinite(output) || !Number.isFinite(inputRate) || !Number.isFinite(outputRate)) {
    return null;
  }
  let amount = 0;
  const notes = [];
  const cached = usage.cached == null ? null : Number(usage.cached);
  const cachedRate = price.cached == null || price.cached === '' ? null : Number(price.cached);
  if (cached != null && cached <= input && cachedRate != null && Number.isFinite(cachedRate)) {
    amount += (input - cached) * inputRate + cached * cachedRate;
  } else {
    amount += input * inputRate;
    if (cached != null && cached > 0 && cachedRate == null) {
      notes.push('The list has no cached rate, so cached input uses the input rate.');
    } else if (cached == null) {
      notes.push('Cached input was not itemized.');
    }
  }
  amount += output * outputRate;
  const requests = Number(usage.requests) || 0;
  const requestFee = price.request == null || price.request === '' ? null : Number(price.request);
  if (requests > 0 && requestFee != null && Number.isFinite(requestFee)) {
    amount += requests * requestFee;
  }
  const reasoning = usage.reasoning == null ? null : Number(usage.reasoning);
  if (apart > 0 && reasoning != null && reasoning >= apart) {
    notes.push('Reasoning tokens are outside the output count and are not included in this estimate.');
  } else if (apart > 0) {
    notes.push('Unitemized tokens are not included in this estimate.');
  }
  const extra = notes.length ? ` ${notes.join(' ')}` : '';
  return { label: 'Estimated cost', value: `${money(amount)}. List-price estimate, not your invoice.${extra}` };
}

function priceFact(usage, apart) {
  if (usage.cost != null && usage.cost !== '' && Number.isFinite(Number(usage.cost))) {
    return {
      label: 'Reported cost',
      value: `${money(usage.cost)}. This is the amount included in the provider replies.`,
    };
  }
  const price = usage.price;
  if (price && price.input != null && price.output != null && usage.input != null && usage.output != null) {
    return estimateFact(usage, price, apart);
  }
  if (Object.prototype.hasOwnProperty.call(usage, 'cost') && usage.cost == null && Number(usage.cost_requests) === 0) {
    return { label: '', value: 'The provider did not report a price.' };
  }
  return null;
}

/**
 * Token lines for the finished report.
 * An older report has no usage block. A provider that omits usage is not given zeros.
 * A price is shown only when the provider reported usage.cost or a model-list rate.
 * SpaceXAI, OpenAI, and OpenRouter use the same lines.
 */
export function tokenFacts(report) {
  const usage = report && report.usage;
  if (!usage || usage.recorded !== true) {
    return [{ label: '', value: 'Token use was not recorded for this scan.' }];
  }
  const facts = [];
  const provider = report.summary && report.summary.provider;
  if (provider) facts.push({ label: 'Provider', value: String(provider) });
  if (usage.model) facts.push({ label: 'Model', value: String(usage.model) });
  const window = Number(usage.context_window) || 0;
  facts.push({
    label: 'Context window',
    value: window > 0 ? `${formatCount(window)} tokens` : 'The provider did not report a context window.',
  });
  const requests = Number(usage.requests) || 0;
  const reported = Number(usage.reported) || 0;
  if (requests === 0) {
    facts.push({ label: '', value: 'No new provider requests. Cached answers do not add tokens.' });
    return facts;
  }
  facts.push({ label: 'Requests', value: formatCount(requests) });
  if (Number(usage.spread_requests) > 0) {
    facts.push({
      label: 'File review',
      value: phaseValue(usage.review_requests, usage.review_input, usage.review_output),
    });
    facts.push({
      label: 'Follow-up',
      value: phaseValue(usage.spread_requests, usage.spread_input, usage.spread_output),
    });
  }
  if (reported === 0) {
    facts.push({ label: '', value: 'The provider did not report token use.' });
    const price = priceFact(usage, 0);
    if (price) facts.push(price);
    return facts;
  }
  if (usage.input != null) facts.push({ label: 'Input tokens', value: formatCount(usage.input) });
  if (usage.cached != null) {
    facts.push({ label: 'Cached input', value: `${formatCount(usage.cached)}. Included in the input count.` });
  }
  if (Number(usage.cache_write) > 0) {
    facts.push({ label: 'Cache writes', value: formatCount(usage.cache_write) });
  }
  if (usage.output != null) facts.push({ label: 'Output tokens', value: formatCount(usage.output) });
  const apart = gapOf(usage);
  const reasoning = usage.reasoning == null ? null : Number(usage.reasoning);
  if (reasoning != null) {
    let note = '';
    if (apart > 0 && reasoning >= apart) note = ' These are in the provider total and not in the output count.';
    else if (usage.output != null && reasoning <= Number(usage.output)) note = ' Included in the output count.';
    facts.push({ label: 'Reasoning tokens', value: `${formatCount(reasoning)}.${note}` });
  }
  if (usage.total != null) facts.push({ label: 'Total tokens', value: formatCount(usage.total) });
  const reasoningExplains = reasoning != null && apart > 0 && reasoning >= apart;
  if (apart > 0 && !reasoningExplains) {
    facts.push({
      label: '',
      value: `The provider total is ${formatCount(apart)} tokens higher than input plus output. Those tokens were not itemized.`,
    });
  }
  if (usage.largest_input != null) {
    let value = `${formatCount(usage.largest_input)} input tokens`;
    if (window > 0) value += `, ${windowShare(usage.largest_input, window)}`;
    facts.push({ label: 'Largest request', value });
  }
  if (usage.input != null && requests > 0) {
    facts.push({ label: 'Average input', value: `${formatCount(Math.round(Number(usage.input) / requests))} tokens` });
  }
  const unreported = Number(usage.unreported) || 0;
  if (unreported > 0) {
    facts.push({
      label: '',
      value: `The provider did not report token use for ${formatCount(unreported)} of ${formatCount(requests)} requests.`,
    });
  }
  const missing = [];
  if (Number(usage.input_missing) > 0) missing.push('input');
  if (Number(usage.output_missing) > 0) missing.push('output');
  if (Number(usage.total_missing) > 0) missing.push('total');
  if (missing.length) {
    facts.push({ label: '', value: `Some requests did not include ${missing.join(', ')} tokens.` });
  }
  const price = priceFact(usage, apart);
  if (price) facts.push(price);
  facts.push({
    label: '',
    value: `These totals add ${countNoun(requests, 'separate request', 'separate requests')}. They were not one prompt.`,
  });
  return facts;
}

function handledOutcome(row) {
  const verdict = row.verdict ? ` · ${row.verdict}` : '';
  return `${row.outcome || ''}${verdict}`;
}

function handledLines(rows) {
  const lines = ['', `Files the AI review handled (${rows.length})`, ''];
  for (const group of groupsOf(rows).map((item) => nestLogGroup(item))) {
    lines.push(`${group.title} (${group.rows.length})`);
    for (const row of group.loose) {
      lines.push(`  ${row.path || ''} — ${handledOutcome(row)}`);
    }
    for (const folder of group.folders) {
      lines.push(`  ${folder.title} (${folder.rows.length})`);
      for (const row of folder.rows) {
        lines.push(`    ${row.path || ''} — ${handledOutcome(row)}`);
      }
    }
    lines.push('');
  }
  return lines;
}

export function reportText(report) {
  const source = report || {};
  const summary = source.summary || {};
  const dangerous = source.dangerous || source.malware || [];
  const undecided = source.undecided || [];
  const safe = source.safe || [];
  const database = source.database || [];
  const notSent = source.not_sent || [];
  const lines = [
    'Clean Sweep AI scan',
    `Scan: ${source.scan_id || ''}`,
  ];
  if (summary.provider) lines.push(`Provider: ${summary.provider}`);
  lines.push('', 'Scan results', '');
  lines.push(`Dangerous: ${summary.malware ?? dangerous.length}`);
  lines.push(`Not decided: ${summary.unsure ?? undecided.length}`);
  lines.push(`Judged safe: ${summary.judged_safe ?? safe.length}`);
  lines.push(`Cleared hits: ${summary.cleared ?? (source.cleared || []).length}`);
  if (source.checksum_line) lines.push(source.checksum_line);
  for (const line of source.nested_lines || []) lines.push(line);
  for (const line of source.encode_lines || []) lines.push(line);
  lines.push(`Database signature matches: ${summary.database_signature ?? database.length}. Not sent to AI.`);
  lines.push(`Not sent: ${summary.not_sent ?? notSent.length}.`);
  const logLine = logsNote(summary);
  if (logLine) lines.push(logLine);
  if (summary.full_pass) lines.push(`Full pass. Secrets were sent to ${summary.provider || 'the provider'}.`);
  if (summary.files_sent_after) {
    lines.push(summary.files_sent_after === 1 ? '1 file sent after the report.' : `${summary.files_sent_after} files sent after the report.`);
  }
  lines.push('', `Dangerous (${dangerous.length})`, '');
  if (dangerous.length === 0) {
    lines.push('  None.');
  } else {
    for (const row of dangerous) {
      lines.push(...fileBlock(row, source.spread || [], true));
      lines.push('');
    }
  }
  lines.push(...groupedSection('Not decided', undecided));
  lines.push(...groupedSection('Judged safe', safe));
  lines.push(...groupedSection('Cleared hits', source.cleared || []));
  lines.push('', `Database (${database.length})`, '');
  if (database.length === 0) {
    lines.push('  None. Database rows are signature matches and are not sent to AI.');
  } else {
    for (const row of database) {
      lines.push(`  ${row.table || ''} ${row.column || ''}`.trim());
      lines.push('    Signature match. Not sent to AI.');
    }
  }
  if (notSent.length) {
    lines.push('', `Not sent to AI (${notSent.length})`, '');
    for (const row of notSent) {
      lines.push(`  ${row.path || ''}`);
      if (row.outcome) lines.push(`    ${row.outcome}`);
    }
  }
  lines.push('', 'Infection summary', '');
  const lead = dangerous.length === 0
    ? (source.narrative || 'No file in this report was judged to be malware.')
    : `${dangerous.length === 1 ? '1 dangerous file.' : `${dangerous.length} dangerous files.`} Each sentence says what that code is supposed to do.`;
  lines.push(lead);
  for (const row of source.does || []) {
    if (row?.path) lines.push(`${row.path}: ${row.summary || ''}`);
  }
  for (const folder of source.folders || []) {
    if (folder?.text) lines.push(folder.text);
  }
  for (const line of requestSummary(dangerous)) {
    lines.push(line);
  }
  for (const line of spreadSummary(source.spread)) {
    lines.push(line);
  }
  lines.push('', 'Tokens', '');
  for (const fact of tokenFacts(source)) {
    lines.push(fact.label ? `${fact.label}: ${fact.value}` : fact.value);
  }
  const handled = source.log || [];
  if (handled.length) {
    lines.push(...handledLines(handled));
  }
  lines.push('');
  return lines.join('\n');
}

/** Site-relative path for a scan progress line. Absolute paths keep the wp-content tail. */
export function relativeScanPath(file) {
  if (!file) return '';
  let path = String(file).replace(/\\/g, '/');
  const markers = ['/wp-content/', '/wp-includes/', '/wp-admin/', 'wp-content/', 'wp-includes/', 'wp-admin/'];
  for (const marker of markers) {
    const at = path.indexOf(marker);
    if (at >= 0) {
      return path.slice(marker.startsWith('/') ? at + 1 : at);
    }
  }
  if (path.startsWith('/') && path.length > 56) {
    const parts = path.split('/').filter(Boolean);
    return parts.length > 3 ? parts.slice(-3).join('/') : path;
  }
  return path.split('/').pop() || path;
}

function waitPhrase(idleMs) {
  const seconds = Math.max(1, Math.floor(Number(idleMs) / 1000));
  if (seconds < 60) {
    return seconds === 1 ? '1 second' : `${seconds} seconds`;
  }
  const minutes = Math.floor(seconds / 60);
  return minutes === 1 ? '1 minute' : `${minutes} minutes`;
}

/**
 * One progress sentence for the signature pass.
 * readingFile is a file the engine has entered. lastFile is the last path it saved.
 */
export function signatureProgressLine(sig, idleMs) {
  const info = sig || {};
  const files = Number(info.files || 0).toLocaleString('en-US');
  const head = `Signature scan, ${files} files checked`;
  const phase = String(info.phase || '').toLowerCase();
  const reading = relativeScanPath(info.readingFile);
  const last = relativeScanPath(info.lastFile);
  const table = String(info.lastDbTable || '').trim();
  const db = table
    ? `Database · ${table}${info.lastDbId ? ` #${info.lastDbId}` : ''}${info.lastDbKey ? ` ${info.lastDbKey}` : ''}`
    : '';
  let where = '';
  let kind = '';
  if (reading) {
    where = reading;
    kind = 'reading';
  } else if (phase === 'database' || phase === 'db') {
    where = db || 'the database';
    kind = 'db';
  } else if (phase === 'integrity') {
    where = String(info.packageNote || '').trim() || 'plugin and theme checksums';
    kind = 'checksum';
  } else if (last) {
    where = last;
    kind = 'file';
  }
  const idle = Number(idleMs) || 0;
  const still = where ? `Still on ${where}` : '';
  if (idle >= 90000 && where) {
    return `No activity for ${waitPhrase(idle)}. ${still}.`;
  }
  if (idle >= 45000 && where) {
    if (kind === 'reading') return `Still reading ${where} for ${waitPhrase(idle)}.`;
    if (kind === 'checksum') return `Still checking checksums for ${waitPhrase(idle)}. ${where}.`;
    return `${still} for ${waitPhrase(idle)}.`;
  }
  if (info.resuming && where) return `Continuing. ${still}.`;
  if (info.resuming) return 'Continuing the signature scan.';
  if (kind === 'reading') return `${head}. Reading ${where}.`;
  if (kind === 'db' || kind === 'checksum') return `${head}. ${where}.`;
  if (where) return `${head}. Last file reported: ${where}.`;
  return `${head}.`;
}
