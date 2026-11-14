import assert from 'node:assert/strict';
import test from 'node:test';
import { StatusResponseGuard } from './statusResponse.js';

function deferred() {
    let resolve;
    const promise = new Promise((done) => { resolve = done; });
    return { promise, resolve };
}

test('a late status response cannot replace a newer response', async () => {
    const guard = new StatusResponseGuard();
    const oldRequest = deferred();
    const newRequest = deferred();
    const applied = [];
    const oldTicket = guard.begin();
    const newTicket = guard.begin();
    const applyWhenCurrent = async (ticket, response) => {
        const status = await response;
        if (guard.isCurrent(ticket)) applied.push(status);
    };

    const oldCompletion = applyWhenCurrent(oldTicket, oldRequest.promise);
    const newCompletion = applyWhenCurrent(newTicket, newRequest.promise);
    newRequest.resolve({ authenticated: true, account: 'new login' });
    await newCompletion;
    oldRequest.resolve({ authenticated: false });
    await oldCompletion;

    assert.deepEqual(applied, [{ authenticated: true, account: 'new login' }]);
});

test('a status response started before a login mutation is discarded', () => {
    const guard = new StatusResponseGuard();
    const staleTicket = guard.begin();
    guard.invalidate();
    assert.equal(guard.isCurrent(staleTicket), false);
    assert.equal(guard.isCurrent(guard.begin()), true);
});

test('a late settings command cannot replace a newer refresh status', async () => {
    const guard = new StatusResponseGuard();
    const command = deferred();
    const refresh = deferred();
    const applied = [];
    const applyWhenCurrent = async (ticket, response) => {
        const status = await response;
        if (guard.isCurrent(ticket)) applied.push(status);
    };

    const commandCompletion = applyWhenCurrent(guard.begin(), command.promise);
    const refreshCompletion = applyWhenCurrent(guard.begin(), refresh.promise);
    refresh.resolve({ authenticated: true, account: 'refreshed account' });
    await refreshCompletion;
    command.resolve({ authenticated: false, apiBaseUrl: 'stale command snapshot' });
    await commandCompletion;

    assert.deepEqual(applied, [{ authenticated: true, account: 'refreshed account' }]);
});

test('a late settings command cannot replace status after a newer login', async () => {
    const guard = new StatusResponseGuard();
    const command = deferred();
    const loginRefresh = deferred();
    const applied = [];
    const applyWhenCurrent = async (ticket, response) => {
        const status = await response;
        if (guard.isCurrent(ticket)) applied.push(status);
    };

    const commandCompletion = applyWhenCurrent(guard.begin(), command.promise);
    guard.invalidate(); // The login mutation invalidates all older command tickets.
    const loginStatusTicket = guard.begin();
    const loginCompletion = applyWhenCurrent(loginStatusTicket, loginRefresh.promise);
    loginRefresh.resolve({ authenticated: true, account: 'newly logged-in user' });
    await loginCompletion;
    command.resolve({ authenticated: false, account: undefined });
    await commandCompletion;

    assert.deepEqual(applied, [{ authenticated: true, account: 'newly logged-in user' }]);
});
