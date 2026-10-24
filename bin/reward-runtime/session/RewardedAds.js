"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.SimulatedRewardedAdProvider = exports.UnavailableRewardedAdProvider = exports.RewardedAdService = void 0;
let serviceSerial = 0;
const isOutcome = (value) => value === 'success' || value === 'cancel' || value === 'failure';
class RewardedAdService {
    constructor(provider, onChanged = () => { }, isEnabled = () => true) {
        this.provider = provider;
        this.onChanged = onChanged;
        this.isEnabled = isEnabled;
        this.instanceId = ++serviceSerial;
        this.requestSerial = 0;
        this.pending = null;
        this.destroyed = false;
    }
    get isPresenting() { return this.pending !== null; }
    showRewardedAd(token, placement, complete) {
        if (this.destroyed || this.pending || !token || !this.isEnabled(placement)) {
            complete('failure');
            return;
        }
        const pending = { id: `rewarded:${this.instanceId}:${++this.requestSerial}`, token, placement, complete };
        this.pending = pending;
        this.onChanged();
        if (this.pending !== pending)
            return;
        if (!this.isEnabled(placement)) {
            this.finish(pending, 'cancel');
            return;
        }
        try {
            const cleanup = this.provider.show({ id: pending.id, placement }, outcome => {
                this.finish(pending, isOutcome(outcome) ? outcome : 'failure');
            });
            if (cleanup) {
                if (this.pending === pending)
                    pending.cleanup = cleanup;
                else
                    cleanup();
            }
        }
        catch (_) {
            this.finish(pending, 'failure');
        }
    }
    cancelRewardedAd(token) {
        var _a;
        if (((_a = this.pending) === null || _a === void 0 ? void 0 : _a.token) === token)
            this.release(this.pending);
    }
    cancelAll() { if (this.pending)
        this.finish(this.pending, 'cancel'); }
    destroy() {
        var _a, _b;
        if (this.destroyed)
            return;
        this.destroyed = true;
        try {
            this.cancelAll();
        }
        finally {
            (_b = (_a = this.provider).destroy) === null || _b === void 0 ? void 0 : _b.call(_a);
        }
    }
    finish(pending, outcome) {
        if (this.pending !== pending)
            return;
        try {
            this.release(pending);
        }
        finally {
            pending.complete(this.isEnabled(pending.placement) ? outcome : 'cancel');
        }
    }
    release(pending) {
        var _a;
        this.pending = null;
        try {
            (_a = pending.cleanup) === null || _a === void 0 ? void 0 : _a.call(pending);
        }
        catch (_) { }
        finally {
            this.onChanged();
        }
    }
}
exports.RewardedAdService = RewardedAdService;
class UnavailableRewardedAdProvider {
    show(_request, complete) { complete('failure'); }
}
exports.UnavailableRewardedAdProvider = UnavailableRewardedAdProvider;
class SimulatedRewardedAdProvider {
    constructor(view) {
        this.view = view;
        this.pending = null;
    }
    show(request, complete) {
        if (this.pending)
            throw new Error('A simulated rewarded ad is already open');
        const pending = { id: request.id, complete };
        this.pending = pending;
        try {
            this.view.open(request.id);
        }
        catch (error) {
            this.pending = null;
            this.view.dismiss();
            throw error;
        }
        return () => {
            if (this.pending !== pending)
                return;
            this.pending = null;
            this.view.dismiss();
        };
    }
    resolve(requestId, outcome) {
        const pending = this.pending;
        if (!pending || pending.id !== requestId || !isOutcome(outcome))
            return;
        this.pending = null;
        this.view.dismiss();
        pending.complete(outcome);
    }
    destroy() { this.pending = null; this.view.dismiss(); }
}
exports.SimulatedRewardedAdProvider = SimulatedRewardedAdProvider;
