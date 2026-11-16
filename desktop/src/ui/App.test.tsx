// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from './App';

const { invokeMock, listenMock } = vi.hoisted(() => ({
    invokeMock: vi.fn(),
    listenMock: vi.fn(),
}));

vi.mock('@tauri-apps/api/core', () => ({ invoke: invokeMock }));
vi.mock('@tauri-apps/api/event', () => ({ listen: listenMock }));
vi.mock('@tauri-apps/plugin-dialog', () => ({ open: vi.fn() }));

function deferred<T>() {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((done) => { resolve = done; });
    return { promise, resolve };
}

type TestStatus = {
    authenticated: boolean;
    account?: { name: string; email: string };
    apiBaseUrl: string;
    wowRoot: string;
    autoSync: boolean;
    minimizeToTray: boolean;
    launchAtLogin: boolean;
    detectedInstallations: string[];
    files: Array<{ path: string; flavor: string; account: string; sizeBytes: number; enabled: boolean }>;
    activity: Array<{ id: string; file: string; status: string; message: string; at: string; partial: boolean; results: unknown[] }>;
    submissionAttempts: Array<{ file: string; state: 'ambiguous' | 'failed'; message: string }>;
    lastUploadAt?: string;
    durabilityWarning?: string;
    credentialCleanupWarning?: string;
};

function status(apiBaseUrl: string, accountName = 'Test account'): TestStatus {
    return {
        authenticated: true,
        account: { name: accountName, email: 'test@example.test' },
        apiBaseUrl,
        wowRoot: '/Applications/World of Warcraft',
        autoSync: false,
        minimizeToTray: true,
        launchAtLogin: false,
        detectedInstallations: [],
        files: [],
        activity: [],
        submissionAttempts: [],
    };
}

afterEach(cleanup);

beforeEach(() => {
    invokeMock.mockReset();
    listenMock.mockReset().mockResolvedValue(() => {});
});

describe('App status ordering', () => {
    it('shows WoW Forever as a distinct discovered flavor label', async () => {
        const persisted = status('https://api.example.test');
        persisted.files = [{ path: '/wow/_forever_/WTF/Account/Camelot/SavedVariables/Flynn.lua', flavor: 'forever', account: 'Camelot', sizeBytes: 120, enabled: true }];
        invokeMock.mockImplementation((command: string) => {
            if (command === 'get_status') return Promise.resolve(persisted);
            throw new Error(`Unexpected Tauri command: ${command}`);
        });
        render(<App />);

        expect(await screen.findByText('WoW Forever')).toBeTruthy();
    });

    it('shows persisted settings durability and credential cleanup warnings', async () => {
        const persisted = {
            ...status('https://api.example.test'),
            durabilityWarning: 'Settings were saved, but the containing directory sync failed.',
            credentialCleanupWarning: 'Signed out locally; remove the remaining OS credential manually.',
        };
        invokeMock.mockImplementation((command: string) => {
            if (command === 'get_status') return Promise.resolve(persisted);
            throw new Error(`Unexpected Tauri command: ${command}`);
        });
        render(<App />);

        expect(await screen.findByText(persisted.durabilityWarning)).toBeTruthy();
        expect(screen.getByText(persisted.credentialCleanupWarning)).toBeTruthy();
    });

    it('refreshes after a refresh request that occurs during a settings mutation', async () => {
        const upload = deferred<unknown>();
        const settings = deferred<ReturnType<typeof status>>();
        const latest = deferred<ReturnType<typeof status>>();
        const initial = status('https://old.example.test');
        invokeMock.mockImplementation((command: string) => {
            if (command === 'get_status') {
                return invokeMock.mock.calls.filter(([name]) => name === 'get_status').length === 1
                    ? Promise.resolve(initial)
                    : latest.promise;
            }
            if (command === 'upload_now') return upload.promise;
            if (command === 'update_settings') return settings.promise;
            throw new Error(`Unexpected Tauri command: ${command}`);
        });
        render(<App />);
        await screen.findByText('Keep your crafters up to date.');

        fireEvent.click(screen.getByRole('button', { name: 'Upload Now' }));
        fireEvent.click(screen.getByRole('button', { name: 'Settings' }));
        const apiField = screen.getByLabelText('Flynn API base URL') as HTMLInputElement;
        fireEvent.change(apiField, { target: { value: 'https://new.example.test' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save URL' }));

        await act(async () => { upload.resolve([]); });
        await waitFor(() => expect(invokeMock.mock.calls.filter(([name]) => name === 'get_status')).toHaveLength(1));

        await act(async () => { settings.resolve(status('https://new.example.test', 'Updated account')); });
        await waitFor(() => expect(invokeMock.mock.calls.filter(([name]) => name === 'get_status')).toHaveLength(2));
        await act(async () => { latest.resolve(status('https://new.example.test', 'Updated account')); });

        await waitFor(() => expect(apiField.value).toBe('https://new.example.test'));
        expect(invokeMock).toHaveBeenCalledWith('update_settings', {
            settings: { apiBaseUrl: 'https://new.example.test' },
        });
    });

    it('does not let an earlier settings response overwrite newer state', async () => {
        const older = deferred<ReturnType<typeof status>>();
        const newer = deferred<ReturnType<typeof status>>();
        const latest = deferred<ReturnType<typeof status>>();
        let settingsCall = 0;
        invokeMock.mockImplementation((command: string) => {
            if (command === 'get_status') {
                return invokeMock.mock.calls.filter(([name]) => name === 'get_status').length === 1
                    ? Promise.resolve(status('https://old.example.test'))
                    : latest.promise;
            }
            if (command === 'update_settings') return ++settingsCall === 1 ? older.promise : newer.promise;
            throw new Error(`Unexpected Tauri command: ${command}`);
        });
        render(<App />);
        await screen.findByText('Keep your crafters up to date.');
        fireEvent.click(screen.getByRole('button', { name: 'Settings' }));

        const apiField = screen.getByLabelText('Flynn API base URL') as HTMLInputElement;
        fireEvent.change(apiField, { target: { value: 'https://older.example.test' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save URL' }));
        fireEvent.change(apiField, { target: { value: 'https://newer.example.test' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save URL' }));

        await act(async () => { newer.resolve(status('https://newer.example.test', 'Newer account')); });
        await waitFor(() => expect(apiField.value).toBe('https://newer.example.test'));
        await act(async () => { older.resolve(status('https://older.example.test', 'Older account')); });
        await waitFor(() => expect(invokeMock.mock.calls.filter(([name]) => name === 'get_status')).toHaveLength(2));
        await act(async () => { latest.resolve(status('https://newer.example.test', 'Final account')); });

        await waitFor(() => expect(apiField.value).toBe('https://newer.example.test'));
        expect(apiField.value).not.toBe('https://older.example.test');
    });

    it('shows a persisted crash-window attempt independently of activity and makes explicit retry actionable', async () => {
        const persisted = status('https://api.example.test');
        persisted.activity = [];
        persisted.files = [{ path: '/wow/SavedVariables/Flynn.lua', flavor: 'retail', account: 'Account', sizeBytes: 120, enabled: true }];
        persisted.submissionAttempts = [{ file: '/wow/SavedVariables/Flynn.lua', state: 'ambiguous', message: 'Flynn may have accepted this upload before the app stopped.' }];
        invokeMock.mockImplementation((command: string) => {
            if (command === 'get_status') return Promise.resolve(persisted);
            if (command === 'retry_ambiguous_upload') return Promise.resolve({ path: persisted.submissionAttempts[0].file, status: 'queued', message: 'Explicit retry queued.', partial: false });
            throw new Error(`Unexpected Tauri command: ${command}`);
        });
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
        render(<App />);

        const retry = await screen.findByRole('button', { name: 'Retry anyway (may duplicate)' });
        expect(screen.getByText('Needs your decision')).toBeTruthy();
        expect(screen.getByText('No sync activity yet. Connect and upload a dump to get started.')).toBeTruthy();
        expect(invokeMock).not.toHaveBeenCalledWith('retry_ambiguous_upload', expect.anything());

        fireEvent.click(retry);
        await waitFor(() => expect(invokeMock).toHaveBeenCalledWith('retry_ambiguous_upload', {
            file: '/wow/SavedVariables/Flynn.lua',
            acceptDuplicateRisk: true,
        }));
        expect(confirm).toHaveBeenCalledWith(expect.stringContaining('may create a duplicate'));
        confirm.mockRestore();
    });

    it('does not expose a retry action from stale ambiguous activity history', async () => {
        const persisted = status('https://api.example.test');
        persisted.files = [{ path: '/wow/SavedVariables/Flynn.lua', flavor: 'retail', account: 'Account', sizeBytes: 120, enabled: true }];
        persisted.activity = [{
            id: 'old-dump',
            file: '/wow/SavedVariables/Flynn.lua',
            status: 'ambiguous',
            message: 'An earlier upload outcome was unknown.',
            at: '2026-01-01T00:00:00Z',
            partial: false,
            results: [],
        }];
        persisted.submissionAttempts = [];
        invokeMock.mockImplementation((command: string) => {
            if (command === 'get_status') return Promise.resolve(persisted);
            throw new Error(`Unexpected Tauri command: ${command}`);
        });
        render(<App />);

        await screen.findByText('An earlier upload outcome was unknown.');
        expect(screen.queryByRole('button', { name: 'Retry anyway (may duplicate)' })).toBeNull();
        expect(invokeMock).not.toHaveBeenCalledWith('retry_ambiguous_upload', expect.anything());
    });

    it('does not let a pre-login status response overwrite the login result', async () => {
        const initial = deferred<ReturnType<typeof status>>();
        const staleRescan = deferred<ReturnType<typeof status>>();
        const latest = deferred<ReturnType<typeof status>>();
        const connect = deferred<{ name: string; email: string }>();
        let statusCall = 0;
        invokeMock.mockImplementation((command: string) => {
            if (command === 'get_status') {
                statusCall += 1;
                return statusCall === 1 ? initial.promise : latest.promise;
            }
            if (command === 'rescan') return staleRescan.promise;
            if (command === 'connect') return connect.promise;
            throw new Error(`Unexpected Tauri command: ${command}`);
        });
        render(<App />);
        await act(async () => initial.resolve({ ...status('https://old.example.test'), authenticated: false, account: undefined }));
        fireEvent.click(screen.getByRole('button', { name: /Connect to Flynn/ }));
        await waitFor(() => expect(invokeMock).toHaveBeenCalledWith('connect', { apiUrl: 'https://old.example.test' }));
        // The toolbar rescan can finish after the browser login has completed.
        fireEvent.click(screen.getByTitle('Rescan WoW files'));
        await act(async () => connect.resolve({ name: 'New account', email: 'new@example.test' }));
        await act(async () => staleRescan.resolve({ ...status('https://old.example.test'), authenticated: false, account: undefined }));
        await waitFor(() => expect(statusCall).toBe(2));
        await act(async () => latest.resolve(status('https://new.example.test', 'New account')));
        await waitFor(() => expect(screen.getByText('New account')).toBeTruthy());
        expect(screen.queryByText('Not connected')).toBeNull();
    });

    it('does not let a pre-logout status response restore the signed-in UI', async () => {
        const staleRescan = deferred<ReturnType<typeof status>>();
        const latest = deferred<ReturnType<typeof status>>();
        let statusCall = 0;
        let finishLogout!: () => void;
        const logout = new Promise<void>((resolve) => { finishLogout = resolve; });
        invokeMock.mockImplementation((command: string) => {
            if (command === 'get_status') {
                statusCall += 1;
                return statusCall === 1 ? Promise.resolve(status('https://api.example.test')) : latest.promise;
            }
            if (command === 'rescan') return staleRescan.promise;
            if (command === 'logout') return logout;
            throw new Error(`Unexpected Tauri command: ${command}`);
        });
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        render(<App />);
        await screen.findByText('Test account');
        fireEvent.click(screen.getByTitle('Rescan WoW files'));
        fireEvent.click(screen.getByRole('button', { name: 'Sign out' }));
        await waitFor(() => expect(invokeMock).toHaveBeenCalledWith('logout'));
        await act(async () => finishLogout());
        await act(async () => staleRescan.resolve(status('https://api.example.test', 'Stale account')));
        await waitFor(() => expect(statusCall).toBe(2));

        await act(async () => latest.resolve({ ...status('https://api.example.test'), authenticated: false, account: undefined }));
        await waitFor(() => expect(screen.getByText('Not connected')).toBeTruthy());
        expect(screen.queryByText('Stale account')).toBeNull();
    });
});
