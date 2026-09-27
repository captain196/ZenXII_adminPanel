'use strict';
/**
 * Append-only memory of everything the sentinel has ever observed about
 * firestore.rules.
 *
 * This is the difference between a linter and an agent that knows the codebase.
 * A linter re-derives the world from scratch every run and can only answer
 * "is this file valid". With a ledger the agent can answer the questions that
 * actually come up in this repo:
 *
 *   "has production changed since I last looked?"        → ruleset id vs last seen
 *   "who tends to own the exam blocks?"                  → drift attribution history
 *   "which blocks churn the most?"                       → change frequency
 *   "did my last deploy actually land?"                  → deploy observations
 *
 * JSONL, appended, never rewritten — cheap, corruption-tolerant (a torn last
 * line is skipped, not fatal), and diffable.
 */
const fs = require('fs');
const path = require('path');
const { stateDir } = require('./lock');

function ledgerPath(cfg) { return path.join(stateDir(cfg), 'rules-ledger.jsonl'); }

/** Append one event. Never throws — telemetry must not break the tool. */
function record(cfg, type, payload = {}) {
  const entry = { at: new Date().toISOString(), type, ...payload };
  try { fs.appendFileSync(ledgerPath(cfg), JSON.stringify(entry) + '\n'); }
  catch (e) { /* ledger is best-effort */ }
  return entry;
}

/** Read all events; skips malformed lines rather than dying on a torn write. */
function readAll(cfg) {
  let raw;
  try { raw = fs.readFileSync(ledgerPath(cfg), 'utf8'); } catch (e) { return []; }
  const out = [];
  for (const line of raw.split('\n')) {
    if (!line.trim()) continue;
    try { out.push(JSON.parse(line)); } catch (e) { /* torn line */ }
  }
  return out;
}

/** The most recent event of a type, or null. */
function last(cfg, type, events = null) {
  const all = events || readAll(cfg);
  for (let i = all.length - 1; i >= 0; i--) if (all[i].type === type) return all[i];
  return null;
}

/**
 * Did production change since this machine last looked?
 * Returns null when there is no prior observation (first run).
 */
function deployedSinceLastSeen(cfg, currentRulesetId, events = null) {
  const prev = last(cfg, 'snapshot', events);
  if (!prev || !prev.rulesetId) return null;
  if (prev.rulesetId === currentRulesetId) return { changed: false, lastSeenAt: prev.at, rulesetId: prev.rulesetId };
  return { changed: true, lastSeenAt: prev.at, previousRulesetId: prev.rulesetId, currentRulesetId };
}

/**
 * Derived knowledge — the "adaptive" half. Everything here is computed from
 * observation, so it improves the longer the tool runs and needs no upkeep.
 */
function insights(cfg) {
  const events = readAll(cfg);
  const churn = new Map();     // block id → times observed changed
  const modChurn = new Map();  // module   → times observed changed
  const deploys = [];
  const conflicts = [];

  for (const e of events) {
    if (e.type === 'drift' || e.type === 'change') {
      for (const b of e.blocks || []) {
        churn.set(b.id, (churn.get(b.id) || 0) + 1);
        for (const m of b.modules || []) modChurn.set(m, (modChurn.get(m) || 0) + 1);
      }
    }
    if (e.type === 'snapshot' && e.rulesetId) deploys.push({ at: e.at, rulesetId: e.rulesetId, createTime: e.createTime });
    if (e.type === 'conflict') conflicts.push(e);
  }

  const distinctDeploys = [];
  const seen = new Set();
  for (const d of deploys) { if (!seen.has(d.rulesetId)) { seen.add(d.rulesetId); distinctDeploys.push(d); } }

  const top = (m, n) => [...m.entries()].sort((a, b) => b[1] - a[1]).slice(0, n).map(([k, v]) => ({ key: k, count: v }));

  return {
    events: events.length,
    firstSeen: events.length ? events[0].at : null,
    hottestBlocks: top(churn, 10),
    hottestModules: top(modChurn, 10),
    distinctRulesetsObserved: distinctDeploys.length,
    latestRuleset: distinctDeploys.length ? distinctDeploys[distinctDeploys.length - 1] : null,
    conflictsSeen: conflicts.length,
  };
}

/** Persist a full copy of a live ruleset so drift can be diffed after the fact. */
function archiveSnapshot(cfg, rulesetId, source) {
  const dir = path.join(stateDir(cfg), 'snapshots');
  fs.mkdirSync(dir, { recursive: true });
  const p = path.join(dir, `${rulesetId}.rules`);
  if (!fs.existsSync(p)) fs.writeFileSync(p, source);
  return p;
}

module.exports = { record, readAll, last, insights, deployedSinceLastSeen, archiveSnapshot, ledgerPath };
