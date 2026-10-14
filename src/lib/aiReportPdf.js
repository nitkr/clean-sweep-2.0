/**
 * Styled findings PDF for a finished AI scan.
 * Built in the browser from the report already on screen.
 * No provider name, no model name, and no token section.
 */

import { infectionTimeLabel, logsNote, requestSummary, splitFindings, spreadSummary } from './aiReportView.js';

const PAGE_W = 595.28;
const PAGE_H = 841.89;
const MARGIN = 40;
const BOTTOM = 46;
const CONTENT_W = PAGE_W - MARGIN * 2;

const BLUE = [65 / 255, 105 / 255, 225 / 255];
const HEADER = [21 / 255, 30 / 255, 46 / 255];
const WHITE = [1, 1, 1];
const INK = [24 / 255, 24 / 255, 27 / 255];
const MUTED = [82 / 255, 82 / 255, 91 / 255];
const FAINT = [161 / 255, 161 / 255, 170 / 255];
const LINE = [228 / 255, 228 / 255, 231 / 255];
const PANEL = [244 / 255, 244 / 255, 245 / 255];
const DANGER = [185 / 255, 28 / 255, 28 / 255];
const DANGER_SOFT = [254 / 255, 242 / 255, 242 / 255];
const DANGER_LINE = [252 / 255, 165 / 255, 165 / 255];
const WARN = [180 / 255, 83 / 255, 9 / 255];
const OK = [21 / 255, 128 / 255, 61 / 255];
const OK_SOFT = [240 / 255, 253 / 255, 244 / 255];
const OK_LINE = [134 / 255, 239 / 255, 172 / 255];
const AMBER_SOFT = [255 / 255, 247 / 255, 237 / 255];
const AMBER_LINE = [253 / 255, 186 / 255, 116 / 255];

const HELVETICA = {
  32: 278, 33: 278, 34: 355, 35: 556, 36: 556, 37: 889, 38: 667, 39: 191,
  40: 333, 41: 333, 42: 389, 43: 584, 44: 278, 45: 333, 46: 278, 47: 278,
  48: 556, 49: 556, 50: 556, 51: 556, 52: 556, 53: 556, 54: 556, 55: 556, 56: 556, 57: 556,
  58: 278, 59: 278, 60: 584, 61: 584, 62: 584, 63: 556, 64: 1015,
  65: 667, 66: 667, 67: 722, 68: 722, 69: 667, 70: 611, 71: 778, 72: 722, 73: 278,
  74: 500, 75: 667, 76: 556, 77: 833, 78: 722, 79: 778, 80: 667, 81: 778, 82: 722,
  83: 667, 84: 611, 85: 722, 86: 667, 87: 944, 88: 667, 89: 667, 90: 611,
  91: 278, 92: 278, 93: 278, 94: 469, 95: 556, 96: 333,
  97: 556, 98: 556, 99: 500, 100: 556, 101: 556, 102: 278, 103: 556, 104: 556, 105: 222,
  106: 222, 107: 500, 108: 222, 109: 833, 110: 556, 111: 556, 112: 556, 113: 556, 114: 333,
  115: 500, 116: 278, 117: 556, 118: 500, 119: 722, 120: 500, 121: 500, 122: 500,
  123: 334, 124: 260, 125: 334, 126: 584,
};

const PUNCT = {
  '\u20AC': 0x80, '\u201A': 0x82, '\u0192': 0x83, '\u201E': 0x84, '\u2026': 0x85,
  '\u2020': 0x86, '\u2021': 0x87, '\u02C6': 0x88, '\u2030': 0x89, '\u0160': 0x8A,
  '\u2039': 0x8B, '\u0152': 0x8C, '\u017D': 0x8E, '\u2018': 0x91, '\u2019': 0x92,
  '\u201C': 0x93, '\u201D': 0x94, '\u2022': 0x95, '\u2013': 0x96, '\u2014': 0x97,
  '\u02DC': 0x98, '\u2122': 0x99, '\u0161': 0x9A, '\u203A': 0x9B, '\u0153': 0x9C,
  '\u017E': 0x9E, '\u0178': 0x9F,
};

function encodeWinAnsi(text) {
  const bytes = [];
  for (const ch of String(text ?? '')) {
    if (PUNCT[ch] != null) {
      bytes.push(PUNCT[ch]);
      continue;
    }
    const code = ch.codePointAt(0);
    if (code === 9 || code === 10 || code === 13) {
      bytes.push(0x20);
      continue;
    }
    if ((code >= 32 && code <= 126) || (code >= 160 && code <= 255)) {
      bytes.push(code);
      continue;
    }
    bytes.push(0x3F);
  }
  return bytes;
}

function hexString(bytes) {
  let hex = '';
  for (const b of bytes) hex += b.toString(16).padStart(2, '0');
  return `<${hex}>`;
}

function textWidth(text, size, font) {
  const bytes = encodeWinAnsi(text);
  let units = 0;
  for (const b of bytes) {
    if (font === 'F3') units += 600;
    else {
      const base = HELVETICA[b] || 500;
      units += font === 'F2' ? Math.round(base * 1.08) : base;
    }
  }
  return (units * size) / 1000;
}

function wrapText(text, maxWidth, size, font) {
  const source = String(text ?? '').replace(/\s+/g, ' ').trim();
  if (!source) return [];
  const lines = [];
  let line = '';
  for (const token of source.split(' ')) {
    const next = line ? `${line} ${token}` : token;
    if (textWidth(next, size, font) <= maxWidth) {
      line = next;
      continue;
    }
    if (line) lines.push(line);
    if (textWidth(token, size, font) <= maxWidth) {
      line = token;
      continue;
    }
    let chunk = '';
    for (const ch of token) {
      if (chunk && textWidth(chunk + ch, size, font) > maxWidth) {
        lines.push(chunk);
        chunk = ch;
      } else {
        chunk += ch;
      }
    }
    line = chunk;
  }
  if (line) lines.push(line);
  return lines;
}

function rgb(color) {
  return color.map((n) => n.toFixed(3)).join(' ');
}

function roundedPath(x, y, w, h, r) {
  const radius = Math.min(r, w / 2, h / 2);
  const c = radius * 0.5522847498;
  const n = (v) => v.toFixed(2);
  return [
    `${n(x + radius)} ${n(y)} m`,
    `${n(x + w - radius)} ${n(y)} l`,
    `${n(x + w - radius + c)} ${n(y)} ${n(x + w)} ${n(y + radius - c)} ${n(x + w)} ${n(y + radius)} c`,
    `${n(x + w)} ${n(y + h - radius)} l`,
    `${n(x + w)} ${n(y + h - radius + c)} ${n(x + w - radius + c)} ${n(y + h)} ${n(x + w - radius)} ${n(y + h)} c`,
    `${n(x + radius)} ${n(y + h)} l`,
    `${n(x + radius - c)} ${n(y + h)} ${n(x)} ${n(y + h - radius + c)} ${n(x)} ${n(y + h - radius)} c`,
    `${n(x)} ${n(y + radius)} l`,
    `${n(x)} ${n(y + radius - c)} ${n(x + radius - c)} ${n(y)} ${n(x + radius)} ${n(y)} c`,
    'h',
  ].join('\n');
}

function countText(value) {
  const n = Number(value);
  return Number.isFinite(n) ? n.toLocaleString('en-US') : '0';
}

function blockLines(parts, width) {
  const lines = [];
  for (const part of parts) {
    const text = String(part.text ?? '').trim();
    if (!text) continue;
    const wrapped = wrapText(text, width, part.size, part.font);
    wrapped.forEach((line, index) => {
      lines.push({
        line,
        font: part.font,
        size: part.size,
        color: part.color,
        leading: part.leading,
        before: index === 0 ? (part.before || 0) : 0,
      });
    });
  }
  return lines;
}

function linesHeight(lines, padTop, padBottom) {
  const body = lines.reduce((sum, line) => sum + line.before + line.leading, 0);
  return padTop + body + padBottom;
}

function layoutReport(report) {
  const source = report || {};
  const summary = source.summary || {};
  const findings = splitFindings(source);
  const database = source.database || [];
  const notSent = source.not_sent || [];
  const pages = [];
  let commands = [];
  let y = 0;

  function cmd(line) {
    commands.push(line);
  }

  function fillRound(x, bottom, w, h, r, color) {
    cmd(`${rgb(color)} rg`);
    cmd(roundedPath(x, bottom, w, h, r));
    cmd('f');
  }

  function strokeRound(x, bottom, w, h, r, color, width) {
    cmd(`${width} w`);
    cmd(`${rgb(color)} RG`);
    cmd(roundedPath(x, bottom, w, h, r));
    cmd('S');
  }

  function fillRect(x, bottom, w, h, color) {
    cmd(`${rgb(color)} rg`);
    cmd(`${x.toFixed(2)} ${bottom.toFixed(2)} ${w.toFixed(2)} ${h.toFixed(2)} re f`);
  }

  function paintText(text, x, baseline, style) {
    cmd(`${rgb(style.color)} rg`);
    cmd(`BT /${style.font} ${style.size} Tf 1 0 0 1 ${x.toFixed(2)} ${baseline.toFixed(2)} Tm ${hexString(encodeWinAnsi(text))} Tj ET`);
  }

  function paintCentered(text, center, baseline, style) {
    paintText(text, center - textWidth(text, style.size, style.font) / 2, baseline, style);
  }

  function paintLines(lines, x, top) {
    let cursor = top;
    for (const line of lines) {
      cursor -= line.before;
      paintText(line.line, x, cursor - line.size, line);
      cursor -= line.leading;
    }
    return cursor;
  }

  function openPage(first) {
    commands = [];
    pages.push(commands);
    fillRect(0, PAGE_H - 5, PAGE_W, 5, BLUE);
    if (first) {
      fillRect(0, PAGE_H - 92, PAGE_W, 87, HEADER);
      fillRound(MARGIN, PAGE_H - 70, 28, 28, 7, BLUE);
      cmd('1 w');
      cmd('1 1 1 RG');
      cmd('1 J');
      const ox = MARGIN + 6;
      const oy = PAGE_H - 58;
      cmd(`${(ox + 2).toFixed(2)} ${oy.toFixed(2)} m ${(ox + 7).toFixed(2)} ${(oy - 6).toFixed(2)} l ${(ox + 16).toFixed(2)} ${(oy + 6).toFixed(2)} l S`);
      paintText('Clean Sweep', MARGIN + 40, PAGE_H - 52, { font: 'F2', size: 16, color: WHITE });
      paintText('Scan report', MARGIN + 40, PAGE_H - 68, { font: 'F1', size: 9, color: FAINT });
      const scan = source.scan_id ? `Scan ${source.scan_id}` : '';
      if (scan) {
        paintText(scan, PAGE_W - MARGIN - textWidth(scan, 8, 'F1'), PAGE_H - 54, { font: 'F1', size: 8, color: FAINT });
      }
      y = PAGE_H - 110;
      return;
    }
    fillRect(0, PAGE_H - 36, PAGE_W, 31, HEADER);
    paintText('Clean Sweep', MARGIN, PAGE_H - 24, { font: 'F2', size: 11, color: WHITE });
    paintText('Scan report', PAGE_W - MARGIN - textWidth('Scan report', 9, 'F1'), PAGE_H - 23, { font: 'F1', size: 9, color: FAINT });
    y = PAGE_H - 52;
  }

  function room(height) {
    if (y - height < BOTTOM) openPage(false);
  }

  openPage(true);

  const stats = [
    { label: 'Dangerous', value: summary.malware ?? findings.dangerous.length, color: DANGER },
    { label: 'Not decided', value: summary.unsure ?? findings.undecided.length, color: WARN },
    { label: 'Judged safe', value: summary.judged_safe ?? findings.safe.length, color: OK },
    { label: 'Cleared hits', value: summary.cleared ?? (source.cleared || []).length, color: BLUE },
  ];
  const gap = 8;
  const cardW = (CONTENT_W - gap * 3) / 4;
  const cardH = 64;
  room(cardH);
  const cardBottom = y - cardH;
  stats.forEach((stat, index) => {
    const x = MARGIN + index * (cardW + gap);
    fillRound(x, cardBottom, cardW, cardH, 8, WHITE);
    strokeRound(x, cardBottom, cardW, cardH, 8, LINE, 0.8);
    paintCentered(countText(stat.value), x + cardW / 2, cardBottom + 26, { font: 'F2', size: 18, color: stat.color });
    paintCentered(stat.label, x + cardW / 2, cardBottom + 12, { font: 'F1', size: 8, color: MUTED });
  });
  y = cardBottom - 14;

  const notes = [];
  if (source.checksum_line) notes.push(source.checksum_line);
  for (const line of source.nested_lines || []) notes.push(line);
  for (const line of source.encode_lines || []) notes.push(line);
  notes.push(`Database signature matches: ${summary.database_signature ?? database.length}. Not sent to AI. Not sent: ${summary.not_sent ?? notSent.length}.`);
  const logLine = logsNote(summary);
  if (logLine) notes.push(logLine);
  if (summary.full_pass) notes.push('Full pass. Secret files were included.');
  if (summary.files_sent_after) {
    notes.push(summary.files_sent_after === 1 ? '1 file sent after the report.' : `${summary.files_sent_after} files sent after the report.`);
  }
  if (
    findings.dangerous.length === 0
    && findings.undecided.length === 0
    && findings.safe.length === 0
    && database.length === 0
  ) {
    notes.push('The review finished. Nothing further to report.');
  }
  const noteLines = blockLines(notes.map((text) => ({
    text, font: 'F1', size: 9, color: MUTED, leading: 12, before: 2,
  })), CONTENT_W - 24);
  const noteH = linesHeight(noteLines, 10, 8);
  room(noteH);
  fillRound(MARGIN, y - noteH, CONTENT_W, noteH, 8, PANEL);
  strokeRound(MARGIN, y - noteH, CONTENT_W, noteH, 8, LINE, 0.7);
  paintLines(noteLines, MARGIN + 12, y - 8);
  y -= noteH + 16;

  function heading(text, reserve = 0) {
    room(22 + reserve);
    paintText(text, MARGIN, y - 13, { font: 'F2', size: 13, color: INK });
    y -= 22;
  }

  function drawCard(lines, fill, border, padX = 12) {
    const height = linesHeight(lines, 12, 10);
    const fitsFresh = height < PAGE_H - 70 - BOTTOM;
    if (y - height < BOTTOM && fitsFresh) openPage(false);
    const top = y;
    const bottom = y - height;
    fillRound(MARGIN, bottom, CONTENT_W, height, 8, fill);
    strokeRound(MARGIN, bottom, CONTENT_W, height, 8, border, 1);
    paintLines(lines, MARGIN + padX, top - 10);
    y = bottom - 8;
  }

  if (findings.dangerous.length) {
    heading('Dangerous', 72);
    for (const row of findings.dangerous) {
      const parts = [
        { text: row.path || '', font: 'F3', size: 8.5, color: INK, leading: 11, before: 0 },
        { text: row.summary || '', font: 'F1', size: 10, color: INK, leading: 13, before: 4 },
      ];
      for (const item of source.spread || []) {
        if (item && item.from === row.path && item.text) {
          parts.push({ text: item.text, font: 'F1', size: 9, color: MUTED, leading: 12, before: 3 });
        }
      }
      if (row.pattern) {
        parts.push({
          text: `${row.pattern}${row.match ? ` · ${row.match}` : ''}`,
          font: 'F3', size: 8, color: MUTED, leading: 11, before: 3,
        });
      }
      parts.push({ text: infectionTimeLabel(row), font: 'F1', size: 8.5, color: MUTED, leading: 11, before: 3 });
      if (row.first_request_at) {
        parts.push({ text: `First request ${row.first_request_at}.`, font: 'F1', size: 8.5, color: MUTED, leading: 11, before: 1 });
      }
      drawCard(blockLines(parts, CONTENT_W - 24), DANGER_SOFT, DANGER_LINE);
    }
  }

  const summaryTone = findings.dangerous.length
    ? { fill: DANGER_SOFT, border: DANGER_LINE }
    : findings.undecided.length
      ? { fill: AMBER_SOFT, border: AMBER_LINE }
      : { fill: OK_SOFT, border: OK_LINE };
  const summaryParts = [{ text: 'What this run found', font: 'F2', size: 11, color: INK, leading: 14, before: 0 }];
  if (!findings.dangerous.length) {
    summaryParts.push({
      text: source.narrative || 'No file in this report was judged to be malware.',
      font: 'F1', size: 10, color: INK, leading: 13, before: 4,
    });
  } else {
    const lead = findings.dangerous.length === 1 ? '1 dangerous file.' : `${findings.dangerous.length} dangerous files.`;
    const folderNote = Array.isArray(source.folders) ? ' The other code files in the same folder were reviewed.' : '';
    summaryParts.push({
      text: `${lead} Each sentence says what that code is supposed to do.${folderNote}`,
      font: 'F1', size: 10, color: INK, leading: 13, before: 4,
    });
    for (const row of (source.does?.length ? source.does : findings.dangerous)) {
      summaryParts.push({ text: row.path || '', font: 'F3', size: 8.5, color: INK, leading: 11, before: 6 });
      if (row.summary) summaryParts.push({ text: row.summary, font: 'F1', size: 10, color: INK, leading: 13, before: 2 });
    }
  }
  for (const folder of source.folders || []) {
    if (folder?.text) summaryParts.push({ text: folder.text, font: 'F1', size: 9, color: MUTED, leading: 12, before: 4 });
  }
  for (const line of requestSummary(findings.dangerous)) {
    summaryParts.push({ text: line, font: 'F1', size: 9, color: INK, leading: 12, before: 3 });
  }
  for (const line of spreadSummary(source.spread)) {
    summaryParts.push({ text: line, font: 'F1', size: 9, color: INK, leading: 12, before: 3 });
  }
  heading('Infection summary', 88);
  drawCard(blockLines(summaryParts, CONTENT_W - 24), summaryTone.fill, summaryTone.border);

  return pages.filter((page) => page.length);
}

function footer(index, total) {
  const label = `Page ${index} of ${total}`;
  const labelX = (PAGE_W - MARGIN - textWidth(label, 8, 'F1')).toFixed(2);
  return [
    '0.82 0.82 0.85 RG',
    '0.6 w',
    `${MARGIN} 32 m ${(PAGE_W - MARGIN).toFixed(2)} 32 l S`,
    `${rgb(MUTED)} rg`,
    `BT /F1 8 Tf 1 0 0 1 ${MARGIN} 20 Tm ${hexString(encodeWinAnsi('Clean Sweep'))} Tj ET`,
    `BT /F1 8 Tf 1 0 0 1 ${labelX} 20 Tm ${hexString(encodeWinAnsi(label))} Tj ET`,
  ];
}

function latin1(text) {
  const out = new Uint8Array(text.length);
  for (let i = 0; i < text.length; i++) out[i] = text.charCodeAt(i) & 0xff;
  return out;
}

function concat(parts) {
  const len = parts.reduce((sum, part) => sum + part.length, 0);
  const out = new Uint8Array(len);
  let offset = 0;
  for (const part of parts) {
    out.set(part, offset);
    offset += part.length;
  }
  return out;
}

function buildPdfBytes(pages) {
  const pageCount = pages.length || 1;
  const drawn = pages.length ? pages : [[]];
  const fontsId = 3 + pageCount * 2;
  const objects = new Array(fontsId + 2);
  objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
  const kids = [];
  drawn.forEach((pageCommands, index) => {
    const pageId = 3 + index * 2;
    const contentId = pageId + 1;
    kids.push(`${pageId} 0 R`);
    const stream = [...pageCommands, ...footer(index + 1, pageCount)].join('\n');
    objects[pageId] = `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${PAGE_W} ${PAGE_H}] /Contents ${contentId} 0 R /Resources << /Font << /F1 ${fontsId} 0 R /F2 ${fontsId + 1} 0 R /F3 ${fontsId + 2} 0 R >> >> >>`;
    objects[contentId] = `<< /Length ${stream.length} >>\nstream\n${stream}\nendstream`;
  });
  objects[2] = `<< /Type /Pages /Count ${pageCount} /Kids [${kids.join(' ')}] >>`;
  objects[fontsId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
  objects[fontsId + 1] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
  objects[fontsId + 2] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>';

  const parts = [latin1('%PDF-1.4\n')];
  const offsets = [0];
  let cursor = parts[0].length;
  for (let id = 1; id < objects.length; id++) {
    offsets[id] = cursor;
    const chunk = latin1(`${id} 0 obj\n${objects[id]}\nendobj\n`);
    parts.push(chunk);
    cursor += chunk.length;
  }
  const xrefAt = cursor;
  let xref = `xref\n0 ${objects.length}\n0000000000 65535 f \n`;
  for (let id = 1; id < objects.length; id++) {
    xref += `${String(offsets[id]).padStart(10, '0')} 00000 n \n`;
  }
  xref += `trailer\n<< /Size ${objects.length} /Root 1 0 R >>\nstartxref\n${xrefAt}\n%%EOF\n`;
  parts.push(latin1(xref));
  return concat(parts);
}

export function buildAiReportPdf(report) {
  return buildPdfBytes(layoutReport(report));
}

export function saveAiReportPdf(report) {
  const bytes = buildAiReportPdf(report);
  const blob = new Blob([bytes], { type: 'application/pdf' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `clean-sweep-ai-scan-${report?.scan_id || 'report'}.pdf`;
  a.click();
  URL.revokeObjectURL(url);
}
