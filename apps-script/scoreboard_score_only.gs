/**
 * Action Game Scoreboard API — Score only
 *
 * Required headers (exact):
 *  - DateTime   (UNIX timestamp GMT, seconds or milliseconds)
 *  - TeamName
 *  - ScoreTotal
 *
 * Query params:
 *  - key=...
 *  - type=top|recent|totals
 *  - limit=10
 *  - window=all|week|month|year
 *  - nocache=1
 */

const API_VERSION = "v1_score_only";
const SORT_MODE = "score_then_date_" + API_VERSION;

const SHEET_NAME = "tuto"; // <-- CHANGE: your tab name
const API_KEY = "CHANGE_ME_LONG_RANDOM_KEY"; // <-- CHANGE: long random

const PLAYERS_COL_INDEX = 3; // column D

const REQUIRE_SCORE_NONEMPTY = true;
const REQUIRE_SCORE_GT_ZERO = false;

const COL = {
  epoch: "DateTime",
  team: "TeamName",
  score: "ScoreTotal",
};

function doGet(e) {
  try {
    const key = (e.parameter.key || "").trim();
    if (!API_KEY || key !== API_KEY) return jsonOut({ ok: false, error: "Unauthorized" }, 401);

    const type = (e.parameter.type || "top").toLowerCase();
    const limit = clampInt(e.parameter.limit, 1, 200, 10);
    const window = (e.parameter.window || e.parameter.period || "all").toLowerCase();
    const nocache = String(e.parameter.nocache || "") === "1";

    const tz = Session.getScriptTimeZone() || "Europe/Paris";

    const cache = CacheService.getScriptCache();
    const cacheKey = `ag_${SORT_MODE}_${type}_${window}_${limit}`;

    if (!nocache) {
      const cached = cache.get(cacheKey);
      if (cached) return ContentService.createTextOutput(cached).setMimeType(ContentService.MimeType.JSON);
    }

    const rows = readRows_(SHEET_NAME, tz);

    let payload = {
      ok: true,
      type,
      meta: { version: API_VERSION, sort: SORT_MODE, tz, count: rows.length, window, nocache }
    };

    if (type === "top") {
      const filtered = filterByWindow_(rows, window, tz);
      payload.meta.filtered_count = filtered.length;
      payload.data = topRows_(filtered, limit);
    } else if (type === "recent") {
      payload.data = rows.slice(0, limit);
    } else if (type === "totals") {
      payload.data = totals_(rows);
    } else {
      return jsonOut({ ok: false, error: "Unknown type" }, 400);
    }

    const out = JSON.stringify(payload);
    if (!nocache) cache.put(cacheKey, out, 30);

    return ContentService.createTextOutput(out).setMimeType(ContentService.MimeType.JSON);
  } catch (err) {
    return jsonOut({ ok: false, error: String(err) }, 500);
  }
}

function readRows_(sheetName, tz) {
  const ss = SpreadsheetApp.getActive();
  const sh = ss.getSheetByName(sheetName);
  if (!sh) throw new Error(`Sheet not found: ${sheetName}`);

  const data = sh.getDataRange().getValues();
  if (data.length < 2) return [];

  const headers = data[0].map(h => String(h).trim());
  const idx = {
    epoch: headers.indexOf(COL.epoch),
    team: headers.indexOf(COL.team),
    score: headers.indexOf(COL.score),
  };

  const missing = Object.entries(idx).filter(([, v]) => v === -1).map(([k]) => k);
  if (missing.length) throw new Error(`Missing required columns: ${missing.join(", ")}`);

  const out = [];

  for (let i = 1; i < data.length; i++) {
    const r = data[i];

    const epochMs = parseEpochMs_(r[idx.epoch]);
    if (!epochMs) continue;

    const rawScore = r[idx.score];
    if (REQUIRE_SCORE_NONEMPTY && !hasValue_(rawScore)) continue;

    let team = String(r[idx.team] ?? "").trim();
    const score = toNumber_(rawScore);
    if (REQUIRE_SCORE_GT_ZERO && score <= 0) continue;

    const players = toInt_(r[PLAYERS_COL_INDEX]);
    if (team && players > 0) team = `${team} (${players}😈)`;

    const dateObj = new Date(epochMs);

    out.push({
      date: Utilities.formatDate(dateObj, tz, "yyyy-MM-dd'T'HH:mm:ss"),
      date_ms: epochMs,
      team,
      score
    });
  }

  out.sort((a, b) => b.date_ms - a.date_ms);
  return out;
}

function parseEpochMs_(v) {
  if (v === null || v === undefined || v === "") return null;

  let n;
  if (typeof v === "number") n = v;
  else {
    const s = String(v).trim().replace(",", ".");
    n = Number(s);
  }
  if (!isFinite(n) || n <= 0) return null;

  return (n > 1e12) ? Math.round(n) : Math.round(n * 1000);
}

function filterByWindow_(rows, window, tz) {
  if (!window || window === "all") return rows;

  const now = new Date();
  const y = Number(Utilities.formatDate(now, tz, "yyyy"));
  const m = Number(Utilities.formatDate(now, tz, "M"));
  const d = Number(Utilities.formatDate(now, tz, "d"));

  let start;
  if (window === "year") start = new Date(y, 0, 1, 0, 0, 0);
  else if (window === "month") start = new Date(y, m - 1, 1, 0, 0, 0);
  else if (window === "week") {
    const today = new Date(y, m - 1, d, 0, 0, 0);
    const dayIndex = (today.getDay() + 6) % 7;
    today.setDate(today.getDate() - dayIndex);
    start = today;
  } else return rows;

  const startMs = start.getTime();
  return rows.filter(r => r.date_ms >= startMs);
}

function topRows_(rows, limit) {
  const sorted = [...rows].sort((a, b) => {
    if (b.score !== a.score) return b.score - a.score;
    return b.date_ms - a.date_ms;
  });
  return sorted.slice(0, limit);
}

function totals_(rows) {
  const total = rows.length;
  let sumScore = 0;
  let best = null;

  for (const r of rows) {
    sumScore += r.score;
    if (!best || r.score > best.score || (r.score === best.score && r.date_ms > best.date_ms)) best = r;
  }

  return { total_games: total, total_score: sumScore, best };
}

function hasValue_(v) { return v !== null && v !== undefined && String(v).trim() !== ""; }

function extractNumber_(v) {
  if (v === null || v === undefined) return 0;
  if (typeof v === "number" && isFinite(v)) return v;

  const s = String(v).replace(/\u00A0/g, " ").trim();
  const m = s.match(/-?\d+(?:[.,]\d+)?/);
  if (!m) return 0;

  const n = Number(m[0].replace(",", "."));
  return isFinite(n) ? n : 0;
}

function toNumber_(v) { const n = extractNumber_(v); return isFinite(n) ? n : 0; }
function toInt_(v) { const n = extractNumber_(v); return isFinite(n) ? Math.trunc(n) : 0; }

function clampInt(v, min, max, def) {
  const n = parseInt(v, 10);
  if (!isFinite(n)) return def;
  return Math.min(max, Math.max(min, n));
}

function jsonOut(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj))
    .setMimeType(ContentService.MimeType.JSON);
}
