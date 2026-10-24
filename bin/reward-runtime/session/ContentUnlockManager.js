"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.ContentUnlockManager = void 0;
const ContentConfig_1 = require("./ContentConfig");
const EMPTY_PROGRESS = { stageValueMax: 0, currentStageIndex: 0, powerLevel: 0,
    challengeWins: 0, challengeUnlocked: false, weaponsUnlocked: false, tutorialCompleted: false, huntFirstClear: false };
class ContentUnlockManager {
    constructor(config = (0, ContentConfig_1.resolveContentConfig)(), progress = () => ({})) {
        this.config = config;
        this.progress = progress;
    }
    isAdEnabled(placement) {
        return this.config.adsEnabled && this.config.adPlacements[placement] === true;
    }
    isUnlocked(id, extra = {}) { return this.get(id, extra).unlocked; }
    isUpcoming(id, inChallenge) {
        const state = this.get(id);
        if (state.unlocked)
            return false;
        if (state.visible)
            return true;
        if (!['rebirth', 'skins', 'dailyCheck'].includes(id) || !inChallenge)
            return false;
        const p = { ...EMPTY_PROGRESS, ...this.progress() };
        return (!this.config.tutorialEnabled || p.tutorialCompleted) &&
            p.challengeWins === this.config.thresholds.rebirthSkinChallengeWins - 1;
    }
    get(id, extra = {}) {
        const p = { ...EMPTY_PROGRESS, ...this.progress(), ...extra }, t = this.config.thresholds;
        const placement = {
            dailyAdWeapon: 'weapon-daily', adWeapon: 'weapon-unlock', rebirthAd: 'rebirth', speedrunAd: 'speedrun', raidAd: 'raid-entry', bossAd: 'boss', petDailyAd: 'pet-daily',
            fishDailyAd: 'fish-daily', huntAd: 'hunt', mineEntryAd: 'mine-entry', mineRewardAd: 'mine-reward', adventureAd: 'adventure-reduce',
        };
        const ad = placement[id];
        if (ad && !this.isAdEnabled(ad))
            return { visible: false, unlocked: false, reason: 'adsDisabled', message: '广告功能暂未开放。' };
        if (id === 'skins' || id === 'rebirth' || id === 'dailyCheck') {
            const label = id === 'skins' ? '皮肤' : id === 'dailyCheck' ? '每日签到' : '转生';
            if (this.config.tutorialEnabled && !p.tutorialCompleted)
                return { visible: false, unlocked: false,
                    reason: 'tutorial', message: `完成新手引导后开放${label}。` };
            if (!this.config.bypassProgression && p.challengeWins < t.rebirthSkinChallengeWins)
                return { visible: false, unlocked: false,
                    reason: 'challenge', message: `成功通关${t.rebirthSkinChallengeWins}次挑战后开放${label}。` };
            if (id !== 'rebirth')
                return { visible: true, unlocked: true };
        }
        if (id === 'petDailyAd')
            return p.huntFirstClear && (!this.config.tutorialEnabled || p.tutorialCompleted)
                ? { visible: true, unlocked: true } : { visible: false, unlocked: false, reason: 'stage', message: '完成首次狩猎后开放。' };
        if (id === 'adventure' || id === 'adventureAd') {
            if (this.config.tutorialEnabled && !p.tutorialCompleted)
                return { visible: false, unlocked: false, reason: 'tutorial' };
            const displayed = Math.floor(p.stageValueMax / 10) + 1, unlocked = this.config.bypassProgression || displayed >= t.adventureStage;
            return { visible: this.config.bypassProgression || displayed >= t.adventureVisibleStage, unlocked, requiredStage: t.adventureStage,
                ...(unlocked ? {} : { reason: 'stage', message: `第${t.adventureStage}关起可用。` }) };
        }
        if (id === 'mine' || id === 'mineEntryAd' || id === 'mineRewardAd') {
            if (this.config.tutorialEnabled && !p.tutorialCompleted)
                return { visible: false, unlocked: false, reason: 'tutorial' };
            const displayed = Math.floor(p.stageValueMax / 10) + 1, unlocked = this.config.bypassProgression || displayed >= t.mineStage;
            return { visible: this.config.bypassProgression || displayed >= t.mineVisibleStage, unlocked, requiredStage: t.mineStage,
                ...(unlocked ? {} : { reason: 'stage', message: `第${t.mineStage}关起可用。` }) };
        }
        if (id === 'hunt' || id === 'huntAd') {
            if (this.config.tutorialEnabled && !p.tutorialCompleted)
                return { visible: false, unlocked: false, reason: 'tutorial' };
            const displayed = Math.floor(p.stageValueMax / 10) + 1, unlocked = this.config.bypassProgression || displayed >= t.huntStage;
            return { visible: this.config.bypassProgression || displayed >= t.huntVisibleStage, unlocked, requiredStage: t.huntStage,
                ...(unlocked ? {} : { reason: 'stage', message: `第${t.huntStage}关起可用。` }) };
        }
        if (id === 'fish' || id === 'fishDailyAd') {
            if (this.config.tutorialEnabled && !p.tutorialCompleted)
                return { visible: false, unlocked: false, reason: 'tutorial' };
            const displayed = Math.floor(p.stageValueMax / 10) + 1, unlocked = this.config.bypassProgression || displayed >= t.fishStage;
            return { visible: this.config.bypassProgression || displayed >= t.fishVisibleStage, unlocked, requiredStage: t.fishStage,
                ...(unlocked ? {} : { reason: 'stage', message: `第${t.fishStage}关起可用。` }) };
        }
        if (id === 'pets') {
            if (this.config.tutorialEnabled && !p.tutorialCompleted)
                return { visible: false, unlocked: false, reason: 'tutorial' };
            const displayed = Math.floor(p.stageValueMax / 10) + 1, unlocked = this.config.bypassProgression || displayed >= t.petStage;
            return { visible: this.config.bypassProgression || displayed >= t.petVisibleStage, unlocked, requiredStage: t.petStage,
                ...(unlocked ? {} : { reason: 'stage', message: `第${t.petStage}关起可用。` }) };
        }
        if ((id === 'boss' || id === 'bossAd' || id === 'raid' || id === 'raidAd')
            && this.config.tutorialEnabled && !p.tutorialCompleted) {
            return { visible: false, unlocked: false, reason: 'tutorial', message: '完成新手引导后开放。' };
        }
        if (this.config.bypassProgression)
            return { visible: true, unlocked: true };
        if (id === 'boss' || id === 'bossAd') {
            const displayed = Math.floor(p.stageValueMax / 10) + 1, unlocked = displayed >= t.bossStage;
            return { visible: displayed >= t.bossVisibleStage, unlocked, requiredStage: t.bossStage,
                ...(unlocked ? {} : { reason: 'stage', message: `第${t.bossStage}关起可用。` }) };
        }
        if (id === 'raid' || id === 'raidAd') {
            const displayed = Math.floor(p.stageValueMax / 10) + 1, unlocked = displayed >= t.raidStage;
            return { visible: displayed >= t.raidVisibleStage, unlocked, requiredStage: t.raidStage,
                ...(unlocked ? {} : { reason: 'stage', message: `第${t.raidStage}关起可用。` }) };
        }
        if (id === 'speedrun' || id === 'weapons') {
            const unlocked = p.challengeUnlocked || p.weaponsUnlocked || p.powerLevel >= t.challengePowerLevel;
            return unlocked ? { visible: true, unlocked: true } : { visible: false, unlocked: false, reason: 'tutorial',
                message: `攻击升级达到 Lv.${t.challengePowerLevel} 后开放挑战与武器。` };
        }
        const stage = { autoMerge: t.autoMergeStage, weaponLock: t.weaponLockStage,
            weaponStar: t.weaponStarStage, rebirth: t.rebirthStage, rebirthAction: t.rebirthActionStage,
            dailyAdWeapon: t.dailyAdWeaponStage, rebirthAd: t.rebirthStage };
        const requiredStage = stage[id];
        if (requiredStage !== undefined) {
            const current = id === 'rebirthAction' ? p.currentStageIndex + 1 : Math.floor(p.stageValueMax / 10) + 1;
            const unlocked = current >= requiredStage;
            return { visible: unlocked || id === 'rebirth' || id === 'rebirthAction' || id === 'weaponLock', unlocked,
                requiredStage, ...(unlocked ? {} : { reason: 'stage', message: `第${requiredStage}关起可用。` }) };
        }
        return { visible: true, unlocked: true };
    }
}
exports.ContentUnlockManager = ContentUnlockManager;
