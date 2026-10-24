'use strict';
const { WeaponInventory } = require('../../bin/reward-runtime/session/WeaponInventory.js');
const initial = name => new (require('../../bin/reward-runtime/session/' + name + '.js')[name])().serialize();
const weapons = WeaponInventory.defaults();
weapons.stageValueMax = 20;
process.stdout.write(JSON.stringify({ version: 3, money: { digits: '0', exponent: 0, precision: 1 },
  levels: [0, 0, 0], weapons, progress: { stageIndex: 0, waveIndex: 0 },
  dailyCheck: { lastClaimDay: '', checkNum: 0, totalCheckNum: 0 }, dailyShare: { lastClaimDay: '' }, redeemedCodes: [],
  rebirth: initial('RebirthSystem'), pets: initial('PetSystem'), skins: initial('SkinSystem'),
  fish: initial('FishSystem'), relics: initial('RelicSystem'),
  offline: { lastSavedAtMs: 9999999999999 }, future: {} }));
