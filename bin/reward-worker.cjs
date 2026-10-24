#!/usr/bin/env node
'use strict';

// Server-only deterministic calculator. PHP owns authentication, server times,
// revision checks, idempotency and the database transaction. Never invoke this
// file with a browser-supplied snapshot, reward amount or elapsed time.
const { OriginalInfVal } = require('./reward-runtime/core/OriginalInfVal.js');
const { getWeapon } = require('./reward-runtime/core/WeaponCatalog.js');
const { WeaponInventory } = require('./reward-runtime/session/WeaponInventory.js');
const { RebirthSystem } = require('./reward-runtime/session/RebirthSystem.js');
const { getRebirthPercent } = require('./reward-runtime/session/RebirthEconomy.js');
const { PetSystem } = require('./reward-runtime/session/PetSystem.js');
const { SkinSystem } = require('./reward-runtime/session/SkinSystem.js');
const { FishSystem } = require('./reward-runtime/session/FishSystem.js');
const { RelicSystem } = require('./reward-runtime/session/RelicSystem.js');
const { validateStagePosition } = require('./reward-runtime/session/StageProgression.js');
const { isUpgradeLevel } = require('./reward-runtime/session/UpgradeEconomy.js');
const { computeOfflineElapsed, runOfflineProgress } = require('./reward-runtime/session/OfflineProgressSimulator.js');

const MAX_SNAPSHOT_BYTES = 2097152;
const MAX_INPUT_BYTES = MAX_SNAPSHOT_BYTES + 4096;
const integer = value => Number.isSafeInteger(value) && value >= 0;
function restoreSystem(Type, value) {
  const system = new Type();
  system.restore(value);
  return system;
}
function wallet(value) {
  if (!value || typeof value.digits !== 'string' || !/^\d+$/.test(value.digits) || value.digits.length > 100000
    || !Number.isSafeInteger(value.exponent)) throw new Error('Invalid wallet');
  return OriginalInfVal.fromSerialized(value);
}

function settleOffline(request) {
  if (!request || Object.keys(request).sort().join(',') !== 'last_saved_at_ms,now_ms,save'
    || !request.save || typeof request.save !== 'object' || Array.isArray(request.save)
    || !integer(request.last_saved_at_ms) || !integer(request.now_ms)) throw new Error('Invalid worker request');
  const raw = JSON.stringify(request.save);
  if (Buffer.byteLength(raw) > MAX_SNAPSHOT_BYTES) throw new Error('Save exceeds the limit');
  const save = JSON.parse(raw);
  if (!save || save.version !== 3 || !Array.isArray(save.levels) || save.levels.length !== 3
    || !save.levels.every(isUpgradeLevel)) throw new Error('Invalid save');
  const gold = wallet(save.money), position = validateStagePosition(save.progress);
  const inventory = WeaponInventory.validate(save.weapons);
  if (inventory.testTimeOffsetMs !== 0) throw new Error('Test time is not accepted');
  const rebirth = restoreSystem(RebirthSystem, save.rebirth), pets = restoreSystem(PetSystem, save.pets);
  const skins = restoreSystem(SkinSystem, save.skins).totals, fish = restoreSystem(FishSystem, save.fish).totals;
  const relics = restoreSystem(RelicSystem, save.relics).totals;
  // Client offline.lastSavedAtMs is intentionally ignored, including future or
  // forged timestamps. A new server row (last_saved_at_ms = 0) has no retroactive grant.
  const elapsed = computeOfflineElapsed(request.last_saved_at_ms || null, request.now_ms, relics.offlineMinutes);
  const before = { gold: gold.toJSON(), levels: [...save.levels], ...position };
  let result = null;
  if (elapsed) {
    result = runOfflineProgress({ gold, levels: save.levels, ...position, ...elapsed,
      stageValueMax: inventory.stageValueMax, weapons: inventory.equipment.map(item => item ? getWeapon(item.weaponId) : null),
      modifiers: { rebirthDamage: getRebirthPercent('power', rebirth.levels[0]),
        rebirthSpeed: getRebirthPercent('speed', rebirth.levels[1]), rebirthMoney: getRebirthPercent('money', rebirth.levels[2]),
        skinDamage: 100 + skins.damagePercent, skinSpeed: 100 + skins.speedPercent, skinMoney: 100 + skins.moneyPercent,
        petMoney: pets.moneyMultiplier, petSpeed: pets.moveSpeedPercent,
        fishDamage: fish.damagePercent, fishCriticalChance: fish.criticalChancePercent, fishCriticalDamage: fish.criticalDamagePercent,
        relicDamage: relics.damage, relicCriticalDamage: relics.criticalDamage, relicMoney: relics.money } });
    save.money = result.moneyFinal.toJSON(); save.levels = result.levels;
    save.progress = { stageIndex: result.stageIndex, waveIndex: result.waveIndex };
  }
  // Keep unrelated fields untouched. Only settlement-owned
  // values change; receipt/revision ownership stays in the PHP transaction.
  save.offline = { lastSavedAtMs: Math.max(request.last_saved_at_ms, request.now_ms) };
  return { save, reward: { kind: 'offline', result: result ? JSON.parse(JSON.stringify(result)) : null, before } };
}

async function main() {
  const chunks = []; let bytes = 0;
  for await (const chunk of process.stdin) {
    bytes += chunk.length;
    if (bytes > MAX_INPUT_BYTES) throw new Error('Worker input exceeds the limit');
    chunks.push(chunk);
  }
  const result = settleOffline(JSON.parse(Buffer.concat(chunks).toString('utf8')));
  process.stdout.write(`${JSON.stringify(result)}\n`);
}
if (require.main === module) {
  main().catch(() => { process.stderr.write('Reward calculation failed.\n'); process.exitCode = 1; });
}
module.exports = { settleOffline };
