"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.TutorialManager = void 0;
const ContentConfig_1 = require("./ContentConfig");
const STEPS = ['upgrade', 'gunOpen', 'gunBuy', 'gunMerge', 'gunEquip', 'done'];
class TutorialManager {
    constructor(enabled) {
        this.enabled = enabled;
        this.drawCountValue = 0;
        this.stepValue = enabled ? 'upgrade' : 'done';
    }
    snapshot() {
        return { active: this.enabled && this.stepValue !== 'done', step: this.stepValue, drawCount: this.drawCountValue };
    }
    sync(progress) {
        if (!this.enabled)
            return false;
        const previous = this.stepValue;
        if (this.stepValue === 'upgrade' && progress.weaponsUnlocked === true)
            this.stepValue = 'gunOpen';
        return this.stepValue !== previous;
    }
    onWeaponAction(action) {
        if (!this.enabled)
            return false;
        if (this.stepValue === 'gunBuy' && action.type === 'draw' && this.drawCountValue < ContentConfig_1.WEAPON_TUTORIAL_RULES.drawCount) {
            this.drawCountValue++;
            if (this.drawCountValue === ContentConfig_1.WEAPON_TUTORIAL_RULES.drawCount)
                this.stepValue = 'gunMerge';
            return true;
        }
        if (action.weaponId !== ContentConfig_1.WEAPON_TUTORIAL_RULES.mergeWeaponId)
            return false;
        if (this.stepValue === 'gunMerge' && action.type === 'merge') {
            this.stepValue = 'gunEquip';
            return true;
        }
        if (this.stepValue === 'gunEquip' && action.type === 'equip') {
            this.stepValue = 'done';
            return true;
        }
        return false;
    }
    onWeaponPanelOpened() {
        if (!this.enabled || this.stepValue !== 'gunOpen')
            return false;
        this.stepValue = 'gunBuy';
        return true;
    }
    complete() { this.stepValue = 'done'; }
    serialize() { return { step: this.stepValue, drawCount: this.drawCountValue }; }
    restore(value) {
        const saved = value;
        if (!saved || typeof saved !== 'object' || Array.isArray(saved) ||
            typeof saved.step !== 'string' || !STEPS.includes(saved.step)) {
            throw new Error('Invalid tutorial save');
        }
        const drawCount = saved.drawCount;
        if (typeof drawCount !== 'number' || !Number.isSafeInteger(drawCount) || drawCount < 0 || drawCount > ContentConfig_1.WEAPON_TUTORIAL_RULES.drawCount) {
            throw new Error('Invalid tutorial save');
        }
        this.stepValue = this.enabled ? saved.step : 'done';
        this.drawCountValue = this.enabled ? drawCount : 0;
    }
}
exports.TutorialManager = TutorialManager;
