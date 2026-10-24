"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.StageProgression = exports.MAIN_ROUND_SECONDS = void 0;
exports.stageValue = stageValue;
exports.validateStagePosition = validateStagePosition;
exports.nextStagePosition = nextStagePosition;
exports.MAIN_ROUND_SECONDS = 180;
function stageValue(position) { return position.stageIndex * 10 + position.waveIndex; }
function validateStagePosition(value) {
    const candidate = value;
    if (!candidate || !Number.isSafeInteger(candidate.stageIndex) || candidate.stageIndex < 0 ||
        !Number.isInteger(candidate.waveIndex) || candidate.waveIndex < 0 || candidate.waveIndex > 4 ||
        !Number.isSafeInteger(candidate.stageIndex * 10 + candidate.waveIndex))
        throw new Error('Invalid stage position');
    return { stageIndex: candidate.stageIndex, waveIndex: candidate.waveIndex };
}
function nextStagePosition(position) {
    return validateStagePosition(position.waveIndex === 4
        ? { stageIndex: position.stageIndex + 1, waveIndex: 0 }
        : { stageIndex: position.stageIndex, waveIndex: position.waveIndex + 1 });
}
class StageProgression {
    constructor(position = { stageIndex: 0, waveIndex: 0 }, options = {}) {
        var _a, _b;
        this.activeRound = { stageIndex: 0, waveIndex: 0, roundId: 0, movementMultiplier: 1 };
        this.phaseValue = 'playing';
        this.transitionSerial = 0;
        this.skipSerial = 0;
        this.resultWait = 2;
        this.loadingWait = 1.5;
        this.revealElapsed = 0;
        this.pendingSkip = false;
        this.settledThisFrame = false;
        this.remaining = exports.MAIN_ROUND_SECONDS;
        this.elapsedValue = 0;
        this.target = validateStagePosition(position);
        this.options = { skipPercent: (_a = options.skipPercent) !== null && _a !== void 0 ? _a : 0, rng: (_b = options.rng) !== null && _b !== void 0 ? _b : Math.random };
        this.configure(options);
    }
    get round() { return { ...this.activeRound }; }
    get phase() { return this.phaseValue; }
    get canFight() { return this.phaseValue === 'playing' || this.phaseValue === 'reveal'; }
    get durationSeconds() { return exports.MAIN_ROUND_SECONDS; }
    get timerBarSeconds() { return exports.MAIN_ROUND_SECONDS; }
    get remainingSeconds() { return Math.max(0, this.remaining); }
    get elapsed() { return this.elapsedValue; }
    get transition() { return this.transitionValue ? { ...this.transitionValue } : undefined; }
    get skip() { return this.skipValue ? { ...this.skipValue } : undefined; }
    serialize() { return { ...this.target }; }
    configure(options) {
        if (options.skipPercent !== undefined && (!Number.isFinite(options.skipPercent) || options.skipPercent < 0 || options.skipPercent > 100))
            throw new Error('Invalid skip chance');
        if (options.skipPercent !== undefined)
            this.options.skipPercent = options.skipPercent;
        if (options.rng !== undefined)
            this.options.rng = options.rng;
    }
    setPosition(position) { this.target = validateStagePosition(position); }
    cancelTransition() {
        this.transitionValue = undefined;
        this.skipValue = undefined;
        this.pendingSkip = false;
        this.settledThisFrame = false;
    }
    resetForRebirth(history) {
        this.target = { stageIndex: 0, waveIndex: 0 };
        this.pendingSkip = false;
        this.transitionValue = undefined;
        this.skipValue = undefined;
        this.resultWait = 2;
        this.loadingWait = 1.5;
        return this.startRound(history);
    }
    startRound(history, retainTransition = false) {
        this.activeRound = { ...this.target, roundId: this.activeRound.roundId + 1,
            movementMultiplier: stageValue(this.target) < history ? 2 : 1 };
        this.remaining = this.durationSeconds;
        this.elapsedValue = 0;
        this.settledThisFrame = false;
        this.phaseValue = retainTransition ? 'reveal' : 'playing';
        this.revealElapsed = 0;
        if (!retainTransition) {
            this.transitionValue = undefined;
            this.skipValue = undefined;
        }
        if (this.pendingSkip) {
            this.skipValue = { id: ++this.skipSerial, elapsed: 0 };
            this.pendingSkip = false;
        }
        return this.round;
    }
    win(history) {
        if (!this.canFight)
            return null;
        let next = nextStagePosition(this.target);
        this.pendingSkip = false;
        if (this.options.skipPercent > 0) {
            const random = this.options.rng();
            if (!Number.isFinite(random) || random < 0 || random >= 1)
                throw new Error('Stage RNG must return [0,1)');
            if (random * 100 < this.options.skipPercent) {
                const skipped = nextStagePosition(next);
                if (stageValue(skipped) < history) {
                    next = skipped;
                    this.pendingSkip = true;
                }
            }
        }
        this.target = next;
        const maximum = Math.max(history, stageValue(next));
        this.settle('win', maximum);
        return maximum;
    }
    settle(kind, history) {
        this.phaseValue = kind === 'win' ? 'won' : 'failed';
        this.transitionValue = { id: ++this.transitionSerial, kind, elapsed: 0, loadingElapsed: null };
        const replay = stageValue(this.target) < history;
        this.resultWait = replay ? 1 : 2;
        this.loadingWait = replay ? .75 : 1.5;
        this.settledThisFrame = true;
    }
    advanceFrame(dt, history, paused = false, freezeTimer = false) {
        if (paused || !Number.isFinite(dt) || dt <= 0)
            return null;
        if (this.settledThisFrame) {
            this.settledThisFrame = false;
            return null;
        }
        if (this.skipValue) {
            this.skipValue.elapsed += dt;
            if (this.skipValue.elapsed >= 1.8)
                this.skipValue = undefined;
        }
        if (this.canFight) {
            if (this.remaining <= 0) {
                this.settle('fail', history);
                this.settledThisFrame = false;
                return null;
            }
            if (!freezeTimer) {
                this.remaining -= dt;
                this.elapsedValue += dt;
            }
        }
        const transition = this.transitionValue;
        if (!transition)
            return null;
        transition.elapsed += dt;
        if (this.phaseValue === 'won' || this.phaseValue === 'failed') {
            if (transition.elapsed + 1e-10 >= this.resultWait) {
                this.phaseValue = 'loading';
                transition.loadingElapsed = 0;
            }
        }
        else if (this.phaseValue === 'loading') {
            transition.loadingElapsed += dt;
            if (transition.loadingElapsed + 1e-10 >= this.loadingWait)
                return this.startRound(history, true);
        }
        else if (this.phaseValue === 'reveal') {
            transition.loadingElapsed += dt;
            this.revealElapsed += dt;
            if (this.revealElapsed + 1e-10 >= .2) {
                this.transitionValue = undefined;
                this.phaseValue = 'playing';
            }
        }
        return null;
    }
}
exports.StageProgression = StageProgression;
