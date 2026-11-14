import { useCallback, useEffect, useRef, useState } from 'react';
import { listen } from '@tauri-apps/api/event';
import { invoke } from '@tauri-apps/api/core';
import { open } from '@tauri-apps/plugin-dialog';
import { AlertCircle, Check, ChevronRight, CircleCheck, CloudUpload, Cog, FileText, FolderOpen, LoaderCircle, LogOut, RefreshCw, ShieldCheck, UserRound, X } from 'lucide-react';
import { StatusResponseGuard } from './statusResponse.js';

type CharacterResult = { identity?: unknown; status?: string; code?: string; message?: string };
type Activity = { id: string; file: string; status: string; message: string; at: string; charactersSeen?: number; charactersUpdated?: number; partial: boolean; results: CharacterResult[] };
type DumpFile = { path: string; flavor: string; account: string; sizeBytes: number; enabled: boolean };
type SubmissionAttempt = { file: string; state: 'ambiguous' | 'failed'; message: string };
type Status = { authenticated: boolean; account?: { name: string; email: string }; apiBaseUrl: string; wowRoot?: string; autoSync: boolean; minimizeToTray: boolean; launchAtLogin: boolean; detectedInstallations: string[]; files: DumpFile[]; activity: Activity[]; submissionAttempts: SubmissionAttempt[]; lastUploadAt?: string; durabilityWarning?: string; credentialCleanupWarning?: string };
type Outcome = { path: string; status: string; message: string; partial: boolean };

const flavors: Record<string, string> = { retail: 'Retail', classic_era: 'Classic Era', classic: 'Classic Progression' };
const friendlyDate = (value?: string) => value ? new Date(Number(value) * 1000).toLocaleString() : 'Not synced yet';
const size = (bytes: number) => bytes > 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MiB` : `${Math.max(1, Math.round(bytes / 1024))} KiB`;

export default function App() {
    const [status, setStatus] = useState<Status>();
    const [apiUrl, setApiUrl] = useState('http://127.0.0.1:8000');
    const [busy, setBusy] = useState('');
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [showSettings, setShowSettings] = useState(false);
    const statusResponses = useRef(new StatusResponseGuard());
    const mutationsInFlight = useRef(0);
    const refreshAfterMutation = useRef(false);

    const refresh = useCallback(async () => {
        // A refresh invoked by another command while a settings/session mutation
        // is pending would snapshot the old state. Defer it until all mutations
        // settle, then read the committed state once.
        if (mutationsInFlight.current > 0) {
            refreshAfterMutation.current = true;
            return;
        }
        const ticket = statusResponses.current.begin();
        try {
            const next = await invoke<Status>('get_status');
            if (!statusResponses.current.isCurrent(ticket)) return;
            setStatus(next);
            setApiUrl(next.apiBaseUrl);
        }
        catch (reason) {
            if (!statusResponses.current.isCurrent(ticket)) return;
            setError(String(reason));
            setStatus((current) => current ?? { authenticated: false, apiBaseUrl: 'http://127.0.0.1:8000', autoSync: false, minimizeToTray: true, launchAtLogin: false, detectedInstallations: [], files: [], activity: [], submissionAttempts: [] });
        }
    }, []);

    const finishMutation = () => {
        mutationsInFlight.current = Math.max(0, mutationsInFlight.current - 1);
        if (mutationsInFlight.current === 0 && refreshAfterMutation.current) {
            refreshAfterMutation.current = false;
            void refresh();
        }
    };

    useEffect(() => {
        void refresh();
        const unlisten = listen<Activity>('sync-event', ({ payload }) => setStatus((current) => {
            if (!current) return current;
            const activity = [payload, ...current.activity.filter((item) => item.id !== payload.id)].slice(0, 100);
            const lastUploadAt = payload.status === 'completed' && !payload.partial ? payload.at : current.lastUploadAt;
            return { ...current, activity, lastUploadAt };
        }));
        const files = listen<DumpFile[]>('files-changed', ({ payload }) => setStatus((current) => current ? { ...current, files: payload } : current));
        return () => { void unlisten.then((fn) => fn()); void files.then((fn) => fn()); };
    }, [refresh]);

    const run = async <T,>(label: string, operation: () => Promise<T>) => {
        mutationsInFlight.current += 1;
        statusResponses.current.invalidate();
        setBusy(label); setError(''); setNotice('');
        try { return await operation(); }
        catch (reason) { setError(String(reason)); return undefined; }
        finally { setBusy(''); finishMutation(); }
    };

    // Every command returning a complete status snapshot shares the same
    // response-order fence as refresh. A later refresh, command, or login
    // invalidates this ticket before an older result can replace the UI.
    const runStatus = async (label: string, operation: () => Promise<Status>) => {
        mutationsInFlight.current += 1;
        const ticket = statusResponses.current.begin();
        setBusy(label); setError(''); setNotice('');
        try {
            const next = await operation();
            if (!statusResponses.current.isCurrent(ticket)) {
                refreshAfterMutation.current = true;
                return undefined;
            }
            setStatus(next);
            setApiUrl(next.apiBaseUrl);
            return next;
        }
        catch (reason) {
            if (statusResponses.current.isCurrent(ticket)) setError(String(reason));
            else refreshAfterMutation.current = true;
            return undefined;
        }
        finally { setBusy(''); finishMutation(); }
    };

    const connect = async () => {
        const account = await run('connect', () => invoke<Status['account']>('connect', { apiUrl }));
        if (account) { setNotice(`Connected as ${account.name}.`); await refresh(); }
    };

    const upload = async () => {
        const outcomes = await run('upload', () => invoke<Outcome[]>('upload_now'));
        if (outcomes) {
            const completed = outcomes.filter((item) => item.status === 'completed' && !item.partial).length;
            const attention = outcomes.filter((item) => item.status === 'failed' || item.status === 'ambiguous' || item.status === 'reauthentication_required' || item.partial).length;
            const pending = outcomes.filter((item) => item.status === 'processing' || item.status === 'queued').length;
            setNotice(completed || attention || pending
                ? `Upload check finished: ${completed} complete, ${attention} need attention, ${pending} still processing. Review the activity details below.`
                : 'No changed dump files needed uploading.');
            await refresh();
        }
    };

    const saveSettings = async (change: object) => {
        await runStatus('settings', () => invoke<Status>('update_settings', { settings: change }));
    };

    const chooseRoot = async () => {
        const selected = await open({ directory: true, multiple: false, title: 'Select World of Warcraft installation folder' });
        if (typeof selected === 'string') await saveSettings({ wowRoot: selected });
    };

    const toggleScope = async (file: DumpFile) => {
        await runStatus('scope', () => invoke<Status>('set_sync_scope', { flavor: file.flavor, account: file.account, enabled: !file.enabled }));
    };

    const logout = async () => {
        if (!window.confirm('Remove the Flynn login from this device? Server-side token revocation is managed in your Flynn account in the browser.')) return;
        const result = await run('logout', async () => { await invoke<void>('logout'); return true; });
        if (result !== undefined) { setNotice('Signed out on this device. To revoke the server token, open Flynn account settings in your browser.'); await refresh(); }
        else await refresh();
    };

    const retryAmbiguous = async (file: string) => {
        const confirmed = window.confirm('Flynn could not confirm whether this upload was accepted. Retrying may create a duplicate dump. Do you still want to send it again?');
        if (!confirmed) return;
        const result = await run('retry', () => invoke<Outcome>('retry_ambiguous_upload', { file, acceptDuplicateRisk: true }));
        if (result) { setNotice(result.message); await refresh(); }
    };

    if (!status) return <main className="loading"><LoaderCircle className="spin" /><span>Opening Flynn Desktop…</span></main>;

    return <div className="app-shell">
        <aside className="rail">
            <div className="brand-mark">F</div>
            <div className="rail-spacer" />
            <button className={`rail-button ${showSettings ? '' : 'selected'}`} aria-label="Sync dashboard" onClick={() => setShowSettings(false)}><CloudUpload size={19} /></button>
            <button className={`rail-button ${showSettings ? 'selected' : ''}`} aria-label="Settings" onClick={() => setShowSettings(true)}><Cog size={19} /></button>
            <div className="rail-spacer" />
            <div className="realm-dot" title="Flynn secure sync" />
        </aside>
        <main className="main-panel">
            <header className="topbar">
                <div><div className="eyebrow">WORLD OF WARCRAFT · DATA SYNC</div><h1>{showSettings ? 'Settings' : 'Profession ledger'}</h1></div>
                <div className="top-actions">
                    <button className="icon-button" onClick={() => void runStatus('rescan', () => invoke<Status>('rescan'))} title="Rescan WoW files"><RefreshCw size={17} /></button>
                    {status.authenticated ? <div className="profile-chip"><span className="avatar"><UserRound size={15} /></span><span>{status.account?.name ?? 'Signed in'}</span><button aria-label="Sign out" onClick={() => void logout()}><LogOut size={14} /></button></div> : <span className="signed-out"><span /> Not connected</span>}
                </div>
            </header>
            {error && <div className="message error"><AlertCircle size={16} /><span>{error}</span><button onClick={() => setError('')} aria-label="Dismiss"><X size={15} /></button></div>}
            {notice && <div className="message success"><CircleCheck size={16} /><span>{notice}</span><button onClick={() => setNotice('')} aria-label="Dismiss"><X size={15} /></button></div>}
            {status.durabilityWarning && <div className="message warning"><AlertCircle size={16} /><span>{status.durabilityWarning}</span></div>}
            {status.credentialCleanupWarning && <div className="message warning"><AlertCircle size={16} /><span>{status.credentialCleanupWarning}</span></div>}

            {showSettings ? <section className="content settings-content">
                <div className="section-heading"><div><div className="eyebrow">PREFERENCES</div><h2>Desktop settings</h2></div></div>
                <div className="settings-card">
                    <label className="field-label" htmlFor="api-url">Flynn API base URL</label>
                    <div className="inline-field"><input id="api-url" value={apiUrl} onChange={(event) => setApiUrl(event.target.value)} placeholder="https://flynn.example.com" /><button className="button secondary" onClick={() => void saveSettings({ apiBaseUrl: apiUrl })}>Save URL</button></div>
                    <p className="helper">Use the server origin, without <code>/api/v1</code>. Credentials are held in your OS credential store, never in settings.</p>
                </div>
                <div className="settings-card">
                    <div className="setting-row"><div><strong>World of Warcraft folder</strong><p>{status.wowRoot ?? 'No installation folder selected'}</p></div><button className="button secondary" onClick={() => void chooseRoot()}><FolderOpen size={16} /> Choose folder</button></div>
                    {status.detectedInstallations.length > 0 && <div className="detected"><span>Detected</span>{status.detectedInstallations.map((path) => <button key={path} onClick={() => void saveSettings({ wowRoot: path })}>{path}<ChevronRight size={14} /></button>)}</div>}
                </div>
                <div className="settings-card preference-list">
                    <Toggle title="Automatic Sync" description="Watch enabled Flynn.lua files and sync settled changes." checked={status.autoSync} onChange={(value) => void saveSettings({ autoSync: value })} />
                    <Toggle title="Minimize to tray" description="Keep Flynn running when the window is closed." checked={status.minimizeToTray} onChange={(value) => void saveSettings({ minimizeToTray: value })} />
                    <Toggle title="Launch at login" description="Start Flynn when you sign in to this computer." checked={status.launchAtLogin} onChange={(value) => void runStatus('autostart', () => invoke<Status>('set_launch_at_login', { enabled: value }))} />
                </div>
                <div className="settings-card security-note"><ShieldCheck size={20} /><div><strong>Credentials stay on this device</strong><p>Tokens are stored only in the system credential store. Logout removes the local credential. Revoke the server token from Flynn account settings in your browser.</p></div></div>
            </section> : <section className="content">
                <div className="welcome-row"><div><div className="eyebrow">YOUR ACCOUNT DATA</div><h2>Keep your crafters up to date.</h2><p>Flynn syncs profession and recipe data from your WoW SavedVariables.</p></div><div className="sync-orb"><CloudUpload size={23} /></div></div>
                {!status.authenticated && <div className="connect-card"><div className="connect-icon"><ShieldCheck size={20} /></div><div className="connect-copy"><strong>Connect your Flynn account</strong><p>Sign in securely in your browser. Your password never enters this app.</p></div><div className="connect-controls"><input aria-label="Flynn API base URL" value={apiUrl} onChange={(event) => setApiUrl(event.target.value)} /><button className="button primary" disabled={!!busy} onClick={() => void connect()}>{busy === 'connect' ? <LoaderCircle className="spin" size={15} /> : null} Connect to Flynn <ChevronRight size={15} /></button></div></div>}
                <div className="stats-row">
                    <div className="stat-card"><span className="stat-icon"><FileText size={17} /></span><div><span className="stat-label">SAVEDVARIABLES FILES</span><strong>{status.files.length}</strong></div></div>
                    <div className="stat-card"><span className="stat-icon"><Check size={17} /></span><div><span className="stat-label">LAST SUCCESSFUL SYNC</span><strong className="small-stat">{friendlyDate(status.lastUploadAt)}</strong></div></div>
                </div>
                <div className="section-heading"><div><div className="eyebrow">WORLD OF WARCRAFT</div><h2>Discovered files</h2></div><button className="button primary" disabled={!status.authenticated || !!busy} onClick={() => void upload()}>{busy === 'upload' ? <LoaderCircle className="spin" size={15} /> : <CloudUpload size={16} />} Upload Now</button></div>
                {!status.wowRoot && <div className="empty-card"><FolderOpen size={22} /><div><strong>Choose your WoW installation folder</strong><p>Flynn looks for account SavedVariables under the supported client flavors.</p></div><button className="button secondary" onClick={() => void chooseRoot()}>Choose folder</button></div>}
                {status.wowRoot && status.files.length === 0 && <div className="empty-card"><FileText size={22} /><div><strong>No Flynn.lua files found</strong><p>Log into a character with the Flynn addon, then /reload or log out to write SavedVariables.</p></div><button className="button secondary" onClick={() => void chooseRoot()}>Change folder</button></div>}
                <div className="file-list">{status.files.map((file) => <div className={`file-row ${file.enabled ? '' : 'disabled'}`} key={file.path}>
                    <div className="file-icon"><FileText size={18} /></div><div className="file-info"><div className="file-title"><strong>{file.account}</strong><span className={`flavor ${file.flavor}`}>{flavors[file.flavor] ?? file.flavor}</span></div><div className="file-path">{file.path}</div><div className="file-meta">{size(file.sizeBytes)} <span>·</span> {file.enabled ? 'Included in sync' : 'Sync disabled'}</div></div>
                    <label className="switch" title={file.enabled ? 'Disable sync' : 'Enable sync'}><input type="checkbox" checked={file.enabled} onChange={() => void toggleScope(file)} /><span /></label>
                </div>)}</div>
                {status.submissionAttempts.length > 0 && <>
                    <div className="section-heading activity-heading"><div><div className="eyebrow">PERSISTED UPLOAD ATTENTION</div><h2>Needs your decision</h2></div></div>
                    <div className="activity-list">{status.submissionAttempts.map((attempt) => <article className="activity-row" key={`${attempt.file}-${attempt.state}`}>
                        <span className={`activity-mark ${attempt.state}`}><AlertCircle size={15} /></span>
                        <div className="activity-copy"><div className="activity-title"><strong>{attempt.state === 'ambiguous' ? 'Upload outcome unknown' : 'Upload paused after failure'}</strong></div>
                            <p>{attempt.message}</p><small>{attempt.file.split(/[\\/]/).slice(-4).join(' / ')}</small>
                            {attempt.state === 'ambiguous'
                                ? <button className="button secondary" disabled={!status.authenticated || !!busy} onClick={() => void retryAmbiguous(attempt.file)}>Retry anyway (may duplicate)</button>
                                : <button className="button secondary" disabled={!status.authenticated || !!busy} onClick={() => void upload()}>Retry upload</button>}
                        </div>
                    </article>)}</div>
                </>}
                <div className="section-heading activity-heading"><div><div className="eyebrow">RECENT ACTIVITY</div><h2>Sync history</h2></div></div>
                {status.activity.length === 0 ? <div className="quiet-empty">No sync activity yet. Connect and upload a dump to get started.</div> : <div className="activity-list">{status.activity.slice(0, 8).map((item) => <article className="activity-row" key={item.id}>
                    <span className={`activity-mark ${item.status}`}>{item.status === 'completed' && !item.partial ? <Check size={15} /> : item.status === 'unchanged' ? <Check size={15} /> : item.status === 'processing' || item.status === 'queued' ? <LoaderCircle className="spin" size={15} /> : <AlertCircle size={15} />}</span>
                    <div className="activity-copy"><div className="activity-title"><strong>{item.status === 'completed' ? item.partial ? 'Completed with partial results' : 'Sync complete' : item.status === 'queued' ? 'Ingestion queued' : item.status === 'unchanged' ? 'No changes' : item.status === 'ambiguous' ? 'Upload outcome unknown' : item.status === 'reauthentication_required' ? 'Sign in required' : 'Sync needs attention'}</strong><time>{friendlyDate(item.at)}</time></div><p>{item.message}</p><small>{item.file.split(/[\\/]/).slice(-4).join(' / ')}{item.charactersUpdated != null ? ` · ${item.charactersUpdated}/${item.charactersSeen ?? item.charactersUpdated} characters updated` : ''}</small>
                        {item.results.length > 0 && <details><summary>Character results <ChevronRight size={12} /></summary><ul>{item.results.map((result, index) => <li key={`${item.id}-${index}`}><b>{typeof result.identity === 'string' ? result.identity : result.identity ? JSON.stringify(result.identity) : 'Character'}</b><span>{result.status ?? result.code ?? 'error'}</span><p>{result.message}</p></li>)}</ul></details>}
                    </div>
                </article>)}</div>}
                <footer><span className="footer-check"><ShieldCheck size={14} /> Encrypted by your system credential store</span><span>Files are read only · 10 MiB upload cap</span></footer>
            </section>}
        </main>
    </div>;
}

function Toggle({ title, description, checked, onChange }: { title: string; description: string; checked: boolean; onChange: (value: boolean) => void }) {
    return <div className="setting-row"><div><strong>{title}</strong><p>{description}</p></div><label className="switch"><input type="checkbox" checked={checked} onChange={(event) => onChange(event.target.checked)} /><span /></label></div>;
}
