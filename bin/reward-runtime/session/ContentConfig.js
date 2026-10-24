"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.CONTENT_CONFIG = exports.ADVENTURE_RULES = exports.WEAPON_TUTORIAL_RULES = void 0;
exports.resolveContentConfig = resolveContentConfig;
const EconomyConfig_1 = require("../core/EconomyConfig");
exports.WEAPON_TUTORIAL_RULES = Object.freeze({ drawCount: 2, drawGrade: 0, mergeWeaponId: 3 });
exports.ADVENTURE_RULES = Object.freeze({
    unlockLevels: Object.freeze([0, 1, 3, 6, 10]),
    petSlotUnlockLevels: Object.freeze([0, 2, 7]),
    openDays: Object.freeze([Object.freeze([0, 1, 4]), Object.freeze([0, 2, 5]), Object.freeze([0, 3, 6])]),
    adHighestUnlockedOnly: true,
});
for (const [levels, length] of [[exports.ADVENTURE_RULES.unlockLevels, 5], [exports.ADVENTURE_RULES.petSlotUnlockLevels, 3]]) {
    if (levels.length !== length || levels[0] !== 0 || levels.some((level, index) => !Number.isSafeInteger(level) ||
        level < 0 || level > EconomyConfig_1.ECONOMY_CONFIG.adventure.maxLevel || (index > 0 && level <= levels[index - 1])))
        throw new Error('Invalid adventure unlock rules');
}
if (exports.ADVENTURE_RULES.openDays.length !== 3 || exports.ADVENTURE_RULES.openDays.some(days => !days.length || new Set(days).size !== days.length ||
    days.some(day => !Number.isSafeInteger(day) || day < 0 || day > 6)))
    throw new Error('Invalid adventure opening days');
exports.CONTENT_CONFIG = {
    tutorial: { production: true, test: false },
    ads: {
        enabled: false,
        placements: { 'weapon-daily': true, 'weapon-unlock': true, rebirth: true, speedrun: true, 'raid-entry': true, boss: true, hunt: true, 'pet-daily': true, 'fish-daily': true, 'mine-entry': true, 'mine-reward': true, 'adventure-reduce': true },
    },
    production: {
        adventureStage: 35, adventureVisibleStage: 12,
        mineStage: 4, mineVisibleStage: 3,
        huntStage: 12, huntVisibleStage: 10,
        fishStage: 6, fishVisibleStage: 4,
        petStage: 12, petVisibleStage: 10, challengePowerLevel: 3, autoMergeStage: 20,
        weaponLockStage: 40, weaponStarStage: 40, rebirthStage: 3, rebirthSkinChallengeWins: 1,
        rebirthActionStage: 3, dailyAdWeaponStage: 4, raidStage: 40, raidVisibleStage: 35, bossStage: 10, bossVisibleStage: 6,
    },
    firstDrawDiamonds: EconomyConfig_1.ECONOMY_CONFIG.onboarding.firstDrawDiamondFloor,
    test: {
        bypassProgression: true,
        ...EconomyConfig_1.ECONOMY_CONFIG.testResources,
    },
};
function resolveContentConfig(environment = 'production', overrides = {}) {
    var _a, _b, _c, _d;
    const thresholds = { ...exports.CONTENT_CONFIG.production, ...overrides.thresholds };
    const testResources = { diamonds: exports.CONTENT_CONFIG.test.diamonds, starGems: exports.CONTENT_CONFIG.test.starGems,
        fundGoldUpgrades: exports.CONTENT_CONFIG.test.fundGoldUpgrades, fundRebirthUpgrades: exports.CONTENT_CONFIG.test.fundRebirthUpgrades, rebirthRewardFloorStage: exports.CONTENT_CONFIG.test.rebirthRewardFloorStage,
        adventureTickets: exports.CONTENT_CONFIG.test.adventureTickets, adventureCores: exports.CONTENT_CONFIG.test.adventureCores,
        relicMaxDrawCount: exports.CONTENT_CONFIG.test.relicMaxDrawCount,
        ...overrides.testResources };
    const firstDrawDiamonds = (_a = overrides.firstDrawDiamonds) !== null && _a !== void 0 ? _a : exports.CONTENT_CONFIG.firstDrawDiamonds;
    for (const value of [...Object.values(thresholds), firstDrawDiamonds, testResources.diamonds, testResources.starGems, testResources.rebirthRewardFloorStage,
        testResources.adventureTickets, testResources.adventureCores]) {
        if (!Number.isSafeInteger(value) || value < 0)
            throw new Error('Invalid content configuration');
    }
    if (!Number.isSafeInteger(testResources.relicMaxDrawCount) || testResources.relicMaxDrawCount < 1)
        throw new Error('Invalid content configuration');
    return Object.freeze({ environment, tutorialEnabled: (_b = overrides.tutorialEnabled) !== null && _b !== void 0 ? _b : exports.CONTENT_CONFIG.tutorial[environment],
        adsEnabled: (_c = overrides.adsEnabled) !== null && _c !== void 0 ? _c : exports.CONTENT_CONFIG.ads.enabled,
        adPlacements: Object.freeze({ ...exports.CONTENT_CONFIG.ads.placements, ...overrides.adPlacements }),
        thresholds: Object.freeze(thresholds), firstDrawDiamonds,
        bypassProgression: environment === 'test' && ((_d = overrides.bypassProgression) !== null && _d !== void 0 ? _d : exports.CONTENT_CONFIG.test.bypassProgression),
        testResources: Object.freeze(testResources) });
}
