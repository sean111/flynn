import React, { useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Link, NavLink, Route, Routes, useLocation, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { QueryClient, QueryClientProvider, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { appendCursorPage, cursorUrl, withCursor, type CursorList } from './apiPagination';
import { CursorLoadMore } from './CursorLoadMore';
import '../css/app.css';

type Account = { id: string; name: string; email: string; discord: null | { discordUserID: string; handle: string } };
type Guild = { id: string; name: string; gameFlavor: string; region: string; realm: string; discordGuildID: string | null; discoverable: boolean; membershipStatus?: string | null; membershipRole?: string | null };
type GuildMember = { id: string; userId: string; name: string; role: string; status: string; appliedAt: string };
type Character = { id: string; ownerId: string | null; claimStatus: 'claimed' | 'claimable'; guildIds: string[]; gameFlavor: string; region: string; realm: string; characterName: string; class: string; faction: string; capturedAt: number; lastSyncedAt: string };
type Recipe = { id: string; spellID: number; name: string; description?: string | null; skillLineID: number; crafters: { characterId: string; characterName: string; ownerName: string; currentSkill: number; maxSkill: number }[] };
type CraftRequest = { id: string; spellID: number; recipeName: string; quantity: number; status: string; materialsProvided: string; crafterCharacterName: string; recipientCharacterName?: string | null; createdAt: string; requesterNotes?: string | null; crafterNotes?: string | null; reagents?: { itemID: number; name: string; quantity: number }[] };
type AccessToken = { id: string; name: string; last_used_at: string | null; created_at: string; expires_at: string | null };
class ApiError extends Error { constructor(public status: number, public code: string, message: string, public details?: unknown) { super(message); } }
const queryClient = new QueryClient({ defaultOptions: { queries: { retry: 1, staleTime: 30_000 } } });
let csrfReady = false;

async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
    const method = (init.method ?? 'GET').toUpperCase();
    if (!csrfReady && !['GET', 'HEAD', 'OPTIONS'].includes(method)) {
        await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' });
        csrfReady = true;
    }
    const xsrf = document.cookie.split('; ').find((value) => value.startsWith('XSRF-TOKEN='))?.split('=').slice(1).join('=');
    const headers = new Headers(init.headers);
    if (!(init.body instanceof FormData)) headers.set('Content-Type', 'application/json');
    headers.set('Accept', 'application/json');
    if (xsrf) headers.set('X-XSRF-TOKEN', decodeURIComponent(xsrf));
    const response = await fetch(`/api/v1${path}`, { ...init, method, headers, credentials: 'same-origin' });
    if (response.status === 204) return undefined as T;
    const result = await response.json().catch(() => ({})) as { data?: T; nextCursor?: string | null; error?: { code: string; message: string; details?: unknown } };
    if (!response.ok) throw new ApiError(response.status, result.error?.code ?? 'request_failed', result.error?.message ?? 'The request could not be completed.', result.error?.details);
    const data = (result.data ?? result) as T;
    return (Array.isArray(data) ? withCursor(data, result.nextCursor) : data) as T;
}

async function logoutRequest(): Promise<void> {
    const request = () => api<void>('/auth/logout', { method: 'POST', body: '{}' });
    try {
        await request();
    } catch (exception) {
        if (!(exception instanceof ApiError) || exception.status !== 419) throw exception;

        const csrfResponse = await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' });
        if (!csrfResponse.ok) throw new Error('Could not refresh the sign-out security token.', { cause: exception });
        csrfReady = true;
        await request();
    }
}

async function loadCursorPage<T>(client: QueryClient, queryKey: readonly unknown[], path: string, current?: CursorList<T>) {
    if (!current?.nextCursor) return;
    const page = await api<CursorList<T>>(cursorUrl(path, current.nextCursor));
    client.setQueryData<CursorList<T>>(queryKey, (previous) => previous ? appendCursorPage(previous, page) : page);
}

function Button({ children, kind = 'primary', ...props }: React.ButtonHTMLAttributes<HTMLButtonElement> & { kind?: 'primary' | 'subtle' | 'danger' }) {
    const color = kind === 'primary' ? 'bg-[#c99c5c] text-[#191612] hover:bg-[#ddb879]' : kind === 'danger' ? 'border border-rose-900/70 text-rose-200 hover:bg-rose-950/60' : 'border border-white/10 bg-white/[.04] text-stone-200 hover:bg-white/[.08]';
    return <button {...props} className={`inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold transition disabled:cursor-not-allowed disabled:opacity-45 ${color} ${props.className ?? ''}`}>{children}</button>;
}

function Card({ children, className = '' }: { children: React.ReactNode; className?: string }) { return <section className={`panel rounded-2xl p-5 md:p-6 ${className}`}>{children}</section>; }
function Title({ eyebrow, children, note }: { eyebrow?: string; children: React.ReactNode; note?: string }) { return <div className="mb-6"><div className="mb-2 text-[10px] font-bold uppercase tracking-[.25em] text-[#c39a5d]">{eyebrow ?? 'Flynn · Guild Ledger'}</div><h1 className="text-3xl font-semibold tracking-tight text-stone-100">{children}</h1>{note && <p className="mt-2 max-w-2xl text-sm text-stone-400">{note}</p>}</div>; }
function Notice({ error, success }: { error?: string; success?: string }) { if (!error && !success) return null; return <div role="status" className={`mb-4 rounded-xl border px-4 py-3 text-sm ${error ? 'border-rose-900/70 bg-rose-950/30 text-rose-200' : 'border-emerald-900/70 bg-emerald-950/30 text-emerald-200'}`}>{error || success}</div>; }
function Field({ label, error, id, ...props }: React.InputHTMLAttributes<HTMLInputElement> & { label: string; error?: string }) {
    const inputId = id ?? label.toLowerCase().replace(/\s+/g, '-');
    const errorId = error ? `${inputId}-error` : undefined;
    return <div className="space-y-2 text-sm text-stone-300"><label htmlFor={inputId} className="block">{label}</label><input {...props} id={inputId} aria-invalid={error ? true : props['aria-invalid']} aria-describedby={errorId ?? props['aria-describedby']} className={`w-full rounded-xl border border-white/10 bg-[#151513] px-3.5 py-3 text-stone-100 outline-none transition placeholder:text-stone-600 focus:border-[#b68a4e] ${error ? 'border-rose-700/80' : ''} ${props.className ?? ''}`} />{error && <span id={errorId} role="alert" className="block text-xs leading-5 text-rose-300">{error}</span>}</div>;
}
function FlavorBadge({ flavor }: { flavor: string }) { return <span className="rounded-full border border-[#b9945d]/35 bg-[#b9945d]/10 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wider text-[#d3b479]">{flavor.replace('_', ' ')}</span>; }

export function AuthPage({ mode, onAuthenticated, returnTo = '/' }: { mode: 'login' | 'register'; onAuthenticated: (account: Account) => void; returnTo?: string }) {
    return <AuthPageForm key={mode} mode={mode} onAuthenticated={onAuthenticated} returnTo={returnTo} />;
}

function AuthPageForm({ mode, onAuthenticated, returnTo = '/' }: { mode: 'login' | 'register'; onAuthenticated: (account: Account) => void; returnTo?: string }) {
    const navigate = useNavigate();
    const safeReturnTo = returnTo.startsWith('/') && !returnTo.startsWith('//') ? returnTo : '/';
    const [error, setError] = useState('');
    const { register, handleSubmit, setError: setFieldError, formState: { errors, isSubmitting } } = useForm<{ name: string; email: string; password: string; password_confirmation: string }>();
    const isRegistering = mode === 'register';
    const registrationPasswordRules = {
        required: 'Enter a password.',
        validate: (value: string) => {
            if ([...value].length < 12) return 'Use at least 12 characters.';
            if (!/\p{L}/u.test(value)) return 'Include at least one letter.';
            if (!/\p{Ll}/u.test(value)) return 'Include at least one lowercase letter.';
            if (!/\p{Lu}/u.test(value)) return 'Include at least one uppercase letter.';
            if (!/\p{N}/u.test(value)) return 'Include at least one number.';
            return true;
        },
    };
    const emailRules = {
        required: 'Enter your email address.',
        ...(isRegistering ? { maxLength: { value: 254, message: 'Email addresses must be 254 characters or fewer.' } } : {}),
    };
    const submit = handleSubmit(async (form) => {
        setError('');
        try {
            const result = await api<Account>(`/auth/${isRegistering ? 'register' : 'login'}`, { method: 'POST', body: JSON.stringify(form) });
            onAuthenticated(result); navigate(safeReturnTo);
        } catch (exception) {
            if (exception instanceof ApiError) {
                const details = exception.details;
                if (details && typeof details === 'object') {
                    for (const field of ['name', 'email', 'password', 'password_confirmation'] as const) {
                        const messages = (details as Record<string, unknown>)[field];
                        if (Array.isArray(messages) && typeof messages[0] === 'string') {
                            setFieldError(field, { type: 'server', message: messages[0] });
                        }
                    }
                }
                setError(exception.message);
            } else {
                setError('Something went wrong.');
            }
        }
    });

    return <main className="rune-grid flex min-h-screen items-center justify-center px-5 py-12"><div className="grid w-full max-w-5xl overflow-hidden rounded-[28px] border border-[#c39a5d]/20 bg-[#171715] shadow-2xl md:grid-cols-[1fr_.85fr]"><div className="hidden min-h-[580px] flex-col justify-between bg-[radial-gradient(ellipse_at_55%_30%,rgba(154,108,48,.25),transparent_50%),linear-gradient(145deg,#29251e,#131312)] p-12 md:flex"><div><div className="mb-7 text-4xl text-[#d1a96b]">✦</div><div className="text-xs font-bold uppercase tracking-[.3em] text-[#d2aa68]">The artisan’s ledger</div><h1 className="mt-5 max-w-sm text-5xl font-semibold leading-tight">Craft together.<br /><span className="text-[#d1a96d]">Remember everything.</span></h1></div><p className="max-w-sm text-sm leading-6 text-stone-400">A living catalog of guild knowledge, skilled hands, and the next great commission.</p></div><div className="p-8 md:p-12"><Link to="/" className="text-xs uppercase tracking-[.22em] text-stone-500">← Flynn</Link><h2 className="mt-12 text-3xl font-semibold">{mode === 'login' ? 'Welcome back' : 'Create your account'}</h2><p className="mb-8 mt-2 text-sm text-stone-400">{mode === 'login' ? 'Return to your guild’s craft hall.' : 'Begin building your guild craft network.'}</p><Notice error={error} /><form noValidate onSubmit={submit} className="space-y-4">
        {isRegistering && <Field label="Display name" autoComplete="name" error={errors.name?.message} {...register('name', { required: 'Enter a display name.', validate: (value) => {
            const length = [...value.trim()].length;
            if (length < 2) return 'Display name must be at least 2 characters.';
            if (length > 100) return 'Display name must be 100 characters or fewer.';
            return true;
        } })} />}
        <Field label="Email" type="email" autoComplete="email" error={errors.email?.message} {...register('email', emailRules)} />
        <Field label="Password" type="password" autoComplete={isRegistering ? 'new-password' : 'current-password'} error={errors.password?.message} {...register('password', isRegistering ? registrationPasswordRules : { required: 'Enter your password.' })} />
        {isRegistering && <Field label="Confirm password" type="password" autoComplete="new-password" error={errors.password_confirmation?.message} {...register('password_confirmation', { required: 'Confirm your password.', validate: (value, values) => value === values.password || 'Passwords do not match.' })} />}
        <Button type="submit" disabled={isSubmitting} className="mt-2 w-full">{isSubmitting ? 'One moment…' : mode === 'login' ? 'Sign in' : 'Create account'} <span>→</span></Button>
        </form><p className="mt-7 text-center text-sm text-stone-500">{mode === 'login' ? 'New to Flynn?' : 'Already have a ledger?'} <Link className="gold-text" to={mode === 'login' ? `/register?returnTo=${encodeURIComponent(safeReturnTo)}` : `/login?returnTo=${encodeURIComponent(safeReturnTo)}`}>{mode === 'login' ? 'Create account' : 'Sign in'}</Link></p></div></div></main>;
}

function Shell({ account, onLogout, logoutPending, logoutError, children }: { account: Account; onLogout: () => void; logoutPending: boolean; logoutError: string; children: React.ReactNode }) {
    const links = [['Overview', '/', '⌂'], ['Guild halls', '/guilds', '♜'], ['Characters', '/characters', '✧'], ['Crafting', '/crafting', '⚒'], ['Requests', '/requests', '◈'], ['Account', '/account', '⚙']];
    return <div className="min-h-screen bg-[#111110] text-stone-100"><aside className="fixed inset-y-0 left-0 z-30 hidden w-[250px] border-r border-white/[.07] bg-[#151514] px-5 py-7 lg:flex lg:flex-col"><Link to="/" className="mb-12 flex items-center gap-3"><span className="flex h-10 w-10 items-center justify-center rounded-xl border border-[#c49a5d]/35 bg-[#c49a5d]/10 text-xl text-[#d5ae70]">✦</span><span><b className="block tracking-[.16em]">FLYNN</b><small className="text-[10px] uppercase tracking-[.18em] text-stone-600">Guild craft ledger</small></span></Link><nav className="space-y-1">{links.map(([label, path, icon]) => <NavLink key={path} to={path} end={path === '/'} className={({ isActive }) => `flex items-center gap-3 rounded-xl px-3.5 py-3 text-sm transition ${isActive ? 'bg-[#b38a50]/15 text-[#e2bd7f]' : 'text-stone-500 hover:bg-white/[.04] hover:text-stone-200'}`}><span className="w-5 text-center">{icon}</span>{label}</NavLink>)}</nav><div className="mt-auto rounded-2xl border border-white/[.07] bg-white/[.025] p-4"><div className="mb-2 text-[10px] uppercase tracking-[.2em] text-stone-600">Signed in as</div><div className="truncate text-sm font-medium">{account.name}</div><div className="mt-1 truncate text-xs text-stone-500">{account.email}</div><button onClick={onLogout} disabled={logoutPending} className="mt-4 text-xs text-stone-500 hover:text-rose-300 disabled:cursor-wait disabled:opacity-60">{logoutPending ? 'Signing out…' : 'Sign out ↗'}</button>{logoutError && <p role="alert" className="mt-3 text-xs leading-5 text-rose-300">{logoutError}</p>}</div></aside><div className="lg:pl-[250px]"><header className="sticky top-0 z-20 flex h-[68px] items-center justify-between border-b border-white/[.07] bg-[#111110]/90 px-5 backdrop-blur md:px-9"><div className="text-xs uppercase tracking-[.2em] text-stone-500">The armory · <span className="text-stone-300">Knowledge shared</span></div><Link to="/account" className="flex items-center gap-2 rounded-full border border-white/10 bg-white/[.03] px-3 py-1.5 text-xs text-stone-300"><span className="h-2 w-2 rounded-full bg-emerald-500" />{account.name}</Link></header><main className="mx-auto max-w-[1400px] px-5 py-8 md:px-9 md:py-10">{children}</main><nav className="fixed inset-x-0 bottom-0 z-30 flex justify-around border-t border-white/10 bg-[#151514]/95 py-2 backdrop-blur lg:hidden">{links.slice(0, 5).map(([label, path, icon]) => <NavLink key={path} to={path} className={({ isActive }) => `flex min-w-[52px] flex-col items-center gap-1 px-2 py-1 text-[10px] ${isActive ? 'text-[#d5ae70]' : 'text-stone-500'}`}><span className="text-lg">{icon}</span>{label}</NavLink>)}</nav><div className="h-16 lg:hidden" /></div></div>;
}

function HomePage({ account }: { account: Account }) {
    const characters = useQuery({ queryKey: ['characters'], queryFn: () => api<CursorList<Character>>('/characters') });
    const guilds = useQuery({ queryKey: ['guilds'], queryFn: () => api<CursorList<Guild>>('/guilds') });
    const outgoing = useQuery({ queryKey: ['outgoing'], queryFn: () => api<CursorList<CraftRequest>>('/me/requests/outgoing') });
    const incoming = useQuery({ queryKey: ['incoming'], queryFn: () => api<CursorList<CraftRequest>>('/me/requests/incoming') });
    const stats = [{ label: 'Known characters', value: characters.data?.length ?? '—', foot: 'Your imported roster', icon: '✧' }, { label: 'Guild halls', value: guilds.data?.length ?? '—', foot: 'Discover the network', icon: '♜' }, { label: 'Open commissions', value: (outgoing.data?.filter((item) => ['pending', 'accepted'].includes(item.status)).length ?? 0) + (incoming.data?.filter((item) => ['pending', 'accepted'].includes(item.status)).length ?? 0), foot: 'Requests in motion', icon: '◈' }];
    return <><Title eyebrow="Friday, 13 November 2026" note={`Welcome to the workshop, ${account.name}. Here is the current state of your guild craft network.`}>The craft hall</Title><div className="mb-8 grid gap-4 md:grid-cols-3">{stats.map((stat) => <Card key={stat.label} className="relative overflow-hidden"><div className="absolute -right-3 -top-5 text-7xl text-[#c39a5d]/10">{stat.icon}</div><div className="text-xs uppercase tracking-wider text-stone-500">{stat.label}</div><div className="mt-3 text-4xl font-semibold">{stat.value}</div><div className="mt-2 text-xs text-stone-600">{stat.foot}</div></Card>)}</div><div className="grid gap-5 xl:grid-cols-[1.3fr_.7fr]"><Card><div className="mb-5 flex items-center justify-between"><div><h2 className="text-lg font-semibold">A good place to begin</h2><p className="mt-1 text-xs text-stone-500">Keep your roster and guild knowledge current.</p></div><span className="text-2xl text-[#d1a96b]">✦</span></div><div className="grid gap-3 md:grid-cols-3">{[['01', 'Import your roster', 'Upload a fresh Flynn export', '/characters'], ['02', 'Find your guild', 'Join a matching craft hall', '/guilds'], ['03', 'Make a commission', 'Route work to a skilled crafter', '/crafting']].map(([number, title, caption, path]) => <Link key={number} to={path} className="rounded-xl border border-white/[.07] bg-white/[.025] p-4 transition hover:border-[#c39a5d]/35"><div className="text-[10px] tracking-[.2em] text-[#c39a5d]">{number}</div><div className="mt-5 text-sm font-semibold">{title}</div><div className="mt-1 text-xs text-stone-500">{caption}</div><div className="mt-4 text-xs text-[#c39a5d]">Open ledger →</div></Link>)}</div></Card><Card><div className="mb-5 flex items-center justify-between"><h2 className="text-lg font-semibold">Your identity</h2><Link to="/account" className="text-xs text-[#d3ad70]">Manage →</Link></div><div className="rounded-xl border border-white/[.07] p-4"><div className="text-xs text-stone-500">Discord link</div><div className="mt-2 flex items-center justify-between"><span className="text-sm">{account.discord?.handle ?? 'Not connected'}</span><span className={`rounded-full px-2.5 py-1 text-[10px] ${account.discord ? 'bg-emerald-950 text-emerald-300' : 'bg-amber-950/60 text-amber-300'}`}>{account.discord ? 'Linked' : 'Action needed'}</span></div><p className="mt-3 text-xs leading-5 text-stone-600">Link Discord to use guild features from the Flynn bot and receive crafting updates.</p></div></Card></div></>;
}

function GuildsPage() {
    const client = useQueryClient();
    const [error, setError] = useState('');
    const [success, setSuccess] = useState('');
    const [manageGuildId, setManageGuildId] = useState('');
    const [discordGuildId, setDiscordGuildId] = useState('');
    const [inviteToken, setInviteToken] = useState('');
    const guilds = useQuery({ queryKey: ['guilds'], queryFn: () => api<CursorList<Guild>>('/guilds') });
    const selectedGuild = guilds.data?.find((guild) => guild.id === manageGuildId);
    const members = useQuery({ queryKey: ['members', manageGuildId], queryFn: () => api<CursorList<GuildMember>>(`/guilds/${manageGuildId}/members`), enabled: !!manageGuildId });
    const form = useForm<{ name: string; gameFlavor: string; region: string; realm: string; discordGuildID?: string }>();
    const create = useMutation({
        mutationFn: (data: { name: string; gameFlavor: string; region: string; realm: string; discordGuildID?: string }) => api<Guild>('/guilds', { method: 'POST', body: JSON.stringify(data) }),
        onSuccess: () => { form.reset(); setSuccess('Your guild hall is ready. You are its owner.'); void client.invalidateQueries({ queryKey: ['guilds'] }); },
        onError: (exception) => setError(exception instanceof Error ? exception.message : 'Could not create guild.'),
    });
    const apply = useMutation({
        mutationFn: (id: string) => api(`/guilds/${id}/applications`, { method: 'POST', body: '{}' }),
        onSuccess: () => { setSuccess('Application sent. Membership remains pending until approved.'); void client.invalidateQueries({ queryKey: ['guilds'] }); },
        onError: (exception) => setError(exception instanceof Error ? exception.message : 'Could not apply.'),
    });
    const review = useMutation({
        mutationFn: ({ membershipId, action }: { membershipId: string; action: 'approve' | 'reject' | 'revoke' }) => api(`/guilds/${manageGuildId}/members/${membershipId}/${action}`, { method: 'POST', body: '{}' }),
        onSuccess: () => { setSuccess('Membership roster updated.'); void client.invalidateQueries({ queryKey: ['members', manageGuildId] }); void client.invalidateQueries({ queryKey: ['guilds'] }); },
        onError: (exception) => setError(exception instanceof Error ? exception.message : 'Could not update membership.'),
    });
    const changeRole = useMutation({
        mutationFn: ({ membershipId, role }: { membershipId: string; role: 'officer' | 'member' }) => api(`/guilds/${manageGuildId}/members/${membershipId}/role`, { method: 'PATCH', body: JSON.stringify({ role }) }),
        onSuccess: () => { setSuccess('Officer role updated.'); void client.invalidateQueries({ queryKey: ['members', manageGuildId] }); },
        onError: (exception) => setError(exception instanceof Error ? exception.message : 'Could not update role.'),
    });
    const createInvite = useMutation({
        mutationFn: () => api<{ inviteToken: string }>('/guilds/'+manageGuildId+'/invites', { method: 'POST', body: JSON.stringify({ expiresInDays: 7 }) }),
        onSuccess: (result) => { setInviteToken(result.inviteToken); setSuccess('Invite created. Copy the token and share it with a guild applicant.'); },
        onError: (exception) => setError(exception instanceof Error ? exception.message : 'Could not create invite.'),
    });
    const verifyDiscord = useMutation({
        mutationFn: () => api(`/guilds/${manageGuildId}/discord-link/verify`, { method: 'POST', body: JSON.stringify({ discordGuildID: discordGuildId }) }),
        onSuccess: () => { setDiscordGuildId(''); setSuccess('Discord server verified and linked to this guild.'); void client.invalidateQueries({ queryKey: ['guilds'] }); },
        onError: (exception) => setError(exception instanceof Error ? exception.message : 'Discord server verification failed.'),
    });

    return <>
        <Title note="Find a guild with the same world, join its approval queue, or establish a new home for your craft community.">Guild halls</Title>
        <Notice error={error} success={success} />
        <div className="grid gap-5 xl:grid-cols-[1.35fr_.65fr]">
            <Card>
                <div className="mb-5 flex items-center justify-between"><div><h2 className="text-lg font-semibold">Discoverable guilds</h2><p className="mt-1 text-xs text-stone-500">Applications always begin pending.</p></div><span className="text-xs text-stone-500">{guilds.data?.length ?? '—'} listed</span></div>
                <div className="space-y-3">
                    {guilds.data?.map((guild) => <div key={guild.id} className="flex flex-col gap-4 rounded-xl border border-white/[.07] bg-white/[.025] p-4 sm:flex-row sm:items-center sm:justify-between">
                        <div><div className="flex items-center gap-2"><h3 className="font-medium">{guild.name}</h3><FlavorBadge flavor={guild.gameFlavor} /></div><div className="mt-2 text-xs text-stone-500">{guild.region.toUpperCase()} · {guild.realm}{guild.membershipStatus && <span className="ml-2 capitalize text-stone-400">· {guild.membershipStatus}{guild.membershipRole ? ` ${guild.membershipRole}` : ''}</span>}</div></div>
                        <div className="flex flex-wrap gap-2"><Link to={`/crafting?guild=${guild.id}`} className="rounded-xl border border-white/10 px-3 py-2 text-xs text-stone-300 hover:bg-white/5">View crafts</Link>
                            {['owner', 'officer'].includes(guild.membershipRole ?? '') && <Button kind="subtle" onClick={() => { setManageGuildId(guild.id); setInviteToken(''); }}>Manage</Button>}
                            {guild.membershipStatus === 'approved' && !['owner', 'officer'].includes(guild.membershipRole ?? '') && <span className="rounded-xl border border-emerald-900/60 px-3 py-2 text-xs text-emerald-300">Approved member</span>}
                            {guild.membershipStatus === 'pending' && <span className="rounded-xl border border-amber-900/60 px-3 py-2 text-xs text-amber-300">Application pending</span>}
                            {!['pending', 'approved'].includes(guild.membershipStatus ?? '') && <Button kind="subtle" onClick={() => apply.mutate(guild.id)} disabled={apply.isPending}>Apply</Button>}
                        </div>
                    </div>)}
                    {guilds.isLoading && <div className="text-sm text-stone-500">Loading guild halls…</div>}
                    {guilds.data?.length === 0 && <div className="rounded-xl border border-dashed border-white/10 p-8 text-center text-sm text-stone-500">No guild halls found. Start a new one.</div>}
                    <CursorLoadMore cursor={guilds.data?.nextCursor} label="Load more guilds" onLoadMore={() => void loadCursorPage(client, ['guilds'], '/guilds', guilds.data)} />
                </div>
                {selectedGuild && <div className="mt-6 border-t border-white/[.08] pt-5">
                    <div className="flex flex-wrap items-start justify-between gap-3"><div><h3 className="font-semibold">Manage {selectedGuild.name}</h3><p className="mt-1 text-xs text-stone-500">Approve applications, manage Officers, and invite guildmates.</p></div><Button kind="subtle" onClick={() => createInvite.mutate()} disabled={createInvite.isPending}>Create invite</Button></div>
                    {inviteToken && <Link to={`/guild-invites/${inviteToken}`} className="mt-3 block break-all rounded-lg border border-[#c39a5d]/20 bg-[#c39a5d]/[.05] p-3 font-mono text-xs text-[#d3ad70]">Open invite link → /guild-invites/{inviteToken}</Link>}
                    {selectedGuild.membershipRole === 'owner' && <div className="mt-4 flex flex-wrap gap-2"><input value={discordGuildId} onChange={(event) => setDiscordGuildId(event.target.value)} placeholder="Discord server ID" className="min-w-52 flex-1 rounded-xl border border-white/10 bg-[#151513] px-3 py-2.5 text-xs outline-none focus:border-[#b68a4e]" /><Button kind="subtle" disabled={!/^\d{17,20}$/.test(discordGuildId) || verifyDiscord.isPending} onClick={() => verifyDiscord.mutate()}>Verify Discord admin access</Button></div>}
                    <div className="mt-4 space-y-2">{members.data?.map((member) => <div key={member.id} className="flex flex-col gap-3 rounded-lg bg-white/[.025] px-3 py-3 sm:flex-row sm:items-center sm:justify-between"><div><div className="text-sm">{member.name}</div><div className="mt-1 text-[10px] capitalize text-stone-500">{member.status} · {member.role}</div></div><div className="flex flex-wrap gap-2">{member.status === 'pending' && <><Button kind="subtle" onClick={() => review.mutate({ membershipId: member.id, action: 'approve' })}>Approve</Button><Button kind="danger" onClick={() => review.mutate({ membershipId: member.id, action: 'reject' })}>Reject</Button></>}{member.status === 'approved' && member.role !== 'owner' && <><Button kind="danger" onClick={() => review.mutate({ membershipId: member.id, action: 'revoke' })}>Revoke</Button>{selectedGuild.membershipRole === 'owner' && <select aria-label={`Set ${member.name} role`} value={member.role} onChange={(event) => changeRole.mutate({ membershipId: member.id, role: event.target.value as 'officer' | 'member' })} className="rounded-lg border border-white/10 bg-[#151513] px-2 py-1 text-xs"><option value="member">Member</option><option value="officer">Officer</option></select>}</>}</div></div>)}{members.isLoading && <p className="py-4 text-xs text-stone-500">Loading guild roster…</p>}<CursorLoadMore cursor={members.data?.nextCursor} label="Load more members" onLoadMore={() => void loadCursorPage(client, ['members', manageGuildId], `/guilds/${manageGuildId}/members`, members.data)} /></div>
                </div>}
            </Card>
            <Card>
                <h2 className="text-lg font-semibold">Establish a guild</h2><p className="mb-5 mt-1 text-xs text-stone-500">You’ll become the guild Owner.</p>
                <form className="space-y-3.5" onSubmit={form.handleSubmit((data) => create.mutate(data))}>
                    <Field label="Guild name" placeholder="The Golden Anvil" {...form.register('name', { required: true, minLength: 2, maxLength: 100 })} />
                    <label className="block space-y-2 text-sm text-stone-300"><span>World flavor</span><select className="w-full rounded-xl border border-white/10 bg-[#151513] px-3 py-3" {...form.register('gameFlavor', { required: true })}><option value="retail">Retail</option><option value="classic_era">Classic Era</option><option value="classic">Classic Progression</option><option value="forever">WoW Forever</option></select></label>
                    <div className="grid grid-cols-2 gap-3"><label className="block space-y-2 text-sm text-stone-300"><span>Region</span><select className="w-full rounded-xl border border-white/10 bg-[#151513] px-3 py-3" {...form.register('region', { required: true })}>{['us', 'eu', 'kr', 'tw', 'cn'].map((region) => <option key={region}>{region}</option>)}</select></label><Field label="Realm" placeholder="Area 52" {...form.register('realm', { required: true, maxLength: 128 })} /></div>
                    <Field label="Discord server ID (optional)" placeholder="123456789012345678" inputMode="numeric" {...form.register('discordGuildID', { pattern: /^\d{17,20}$/ })} />
                    <p className="text-[10px] leading-4 text-stone-600">If linked, Flynn must already be in the Discord server with Administrator permission.</p>
                    <Button type="submit" disabled={create.isPending} className="mt-2 w-full">{create.isPending ? 'Creating…' : 'Create guild hall'} →</Button>
                </form>
            </Card>
        </div>
    </>;
}

function hasLeadingFlynnAssignment(source: string): boolean {
    let position = 0;
    const skipWhitespaceAndComments = () => {
        while (position < source.length) {
            while (/\s/.test(source[position] ?? '')) position++;
            if (!source.startsWith('--', position)) return;

            const blockComment = /^--\[(=*)\[/.exec(source.slice(position));
            if (blockComment) {
                const closing = `]${blockComment[1]}]`;
                const end = source.indexOf(closing, position + blockComment[0].length);
                if (end < 0) {
                    position = source.length;
                    return;
                }
                position = end + closing.length;
            } else {
                const newline = source.indexOf('\n', position + 2);
                position = newline < 0 ? source.length : newline + 1;
            }
        }
    };

    skipWhitespaceAndComments();
    if (!source.startsWith('FlynnDB', position) || /[A-Za-z0-9_]/.test(source[position + 7] ?? '')) return false;
    position += 'FlynnDB'.length;
    skipWhitespaceAndComments();
    return source[position] === '=';
}

export function CharactersPage() {
    const client = useQueryClient();
    const [error, setError] = useState('');
    const [success, setSuccess] = useState('');
    const [exportString, setExportString] = useState('');
    const [dumpId, setDumpId] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const submissionId = useRef(0);
    const submittingRef = useRef(false);
    const [selectedGuild, setSelectedGuild] = useState<Record<string, string>>({});
    const characters = useQuery({ queryKey: ['characters'], queryFn: () => api<CursorList<Character>>('/characters') });
    const guilds = useQuery({ queryKey: ['guilds'], queryFn: () => api<CursorList<Guild>>('/guilds') });
    const approvedGuilds = guilds.data?.filter((guild) => guild.membershipStatus === 'approved') ?? [];
    const dump = useQuery({
        queryKey: ['dump', dumpId],
        queryFn: () => api<{ status: string; error: { code: string; message: string } | null; charactersSeen: number; charactersUpdated: number; results: { status: string; identity: string; message: string }[] }>(`/dumps/${dumpId}`),
        enabled: !!dumpId,
        refetchInterval: (query) => query.state.data?.status === 'queued' || query.state.data?.status === 'processing' ? 2000 : false,
    });
    useEffect(() => {
        if (dump.data?.status === 'completed') void client.invalidateQueries({ queryKey: ['characters'] });
    }, [dump.data?.status, client]);
    const submitImport = async (body: FormData | string, clearTextOnAccepted = false) => {
        if (submittingRef.current) return;
        submittingRef.current = true;
        const requestId = ++submissionId.current;
        setIsSubmitting(true);
        setError('');
        setSuccess('');
        setDumpId('');
        try {
            const result = await api<{ dumpId: string }>('/dumps', { method: 'POST', body });
            if (requestId !== submissionId.current) return;
            setDumpId(result.dumpId);
            setSuccess('Upload received. Flynn is safely processing the data.');
            if (clearTextOnAccepted) setExportString('');
        } catch (exception) {
            if (requestId === submissionId.current) setError(exception instanceof ApiError ? exception.message : 'Upload failed.');
        } finally {
            if (requestId === submissionId.current) {
                submittingRef.current = false;
                setIsSubmitting(false);
            }
        }
    };
    const uploadFile = async (file: File, clearTextOnAccepted = false) => {
        const body = new FormData();
        body.append('file', file);
        await submitImport(body, clearTextOnAccepted);
    };
    const uploadString = async () => {
        const payload = exportString.trim();
        if (hasLeadingFlynnAssignment(payload)) {
            await uploadFile(new File([payload], 'Flynn.lua', { type: 'text/plain' }), true);
            return;
        }
        await submitImport(JSON.stringify({ format: 'exportString', payload }), true);
    };
    const claim = useMutation({
        mutationFn: (id: string) => api(`/characters/${id}/claim`, { method: 'POST', body: '{}' }),
        onSuccess: () => { setSuccess('Character claimed. It can now be associated with an approved guild.'); void client.invalidateQueries({ queryKey: ['characters'] }); },
        onError: (exception) => setError(exception instanceof Error ? exception.message : 'Could not claim character.'),
    });
    const assign = useMutation({
        mutationFn: ({ characterId, guildId }: { characterId: string; guildId: string }) => api(`/characters/${characterId}/guilds/${guildId}`, { method: 'PUT', body: '{}' }),
        onSuccess: () => { setSuccess('Character associated with the approved guild.'); void client.invalidateQueries({ queryKey: ['characters'] }); },
        onError: (exception) => setError(exception instanceof Error ? exception.message : 'Could not associate character.'),
    });
    const remove = useMutation({
        mutationFn: (id: string) => api(`/characters/${id}`, { method: 'DELETE' }),
        onSuccess: () => { setSuccess('Character removed from your roster.'); void client.invalidateQueries({ queryKey: ['characters'] }); },
        onError: (exception) => setError(exception instanceof Error ? exception.message : 'Could not delete character.'),
    });

    return <>
        <Title note="Import after /reload, logout, or exiting WoW so the latest SavedVariables are written. Unclaimed imports are yours to explicitly claim.">Character roster</Title>
        <Notice error={error || (dump.isError ? (dump.error instanceof Error ? dump.error.message : 'Could not check import status.') : '') || (dump.data?.status === 'failed' ? `${dump.data.error?.message ?? 'Import could not be processed. Check your file/export and try again.'} (Dump ID: ${dumpId})` : '')} success={dump.data?.status === 'completed' ? `Import complete. ${dump.data.charactersUpdated} character snapshot(s) updated.` : dump.data?.status === 'failed' ? '' : success} />
        <div className="grid gap-5 xl:grid-cols-[.8fr_1.2fr]">
            <Card>
                <div className="mb-4 text-2xl text-[#d2aa6c]">⇧</div>
                <h2 className="text-lg font-semibold">Sync your Flynn export</h2>
                <p className="mb-5 mt-1 text-xs leading-5 text-stone-500">SavedVariables files are parsed as data; no Lua code is executed. Imports remain private to your account.</p>
                <label className="flex cursor-pointer flex-col items-center justify-center rounded-2xl border border-dashed border-[#c39a5d]/30 bg-[#c39a5d]/[.04] px-4 py-8 text-center hover:bg-[#c39a5d]/[.08]">
                    <span className="text-sm font-medium">Choose Flynn.lua</span><span className="mt-1 text-xs text-stone-600">Maximum file size 10 MiB · save with /reload, logout, or exit first</span>
                    <input type="file" accept=".lua,text/plain" disabled={isSubmitting} className="sr-only" onChange={(event) => { const file = event.target.files?.[0]; event.currentTarget.value = ''; if (file) void uploadFile(file); }} />
                </label>
                <div className="my-5 flex items-center gap-3 text-[10px] uppercase tracking-widest text-stone-700"><span className="h-px flex-1 bg-white/10" />or paste export<span className="h-px flex-1 bg-white/10" /></div>
                <textarea value={exportString} onChange={(event) => setExportString(event.target.value)} rows={4} placeholder="Paste a FLYNN1: export string or the complete contents of Flynn.lua" className="w-full resize-y rounded-xl border border-white/10 bg-[#151513] p-3 text-xs text-stone-300 outline-none focus:border-[#b68a4e]" />
                <Button onClick={() => void uploadString()} disabled={!exportString.trim() || isSubmitting} className="mt-3 w-full">{isSubmitting ? 'Importing…' : 'Import pasted data →'}</Button>
                {dump.data && <div className="mt-4 rounded-xl bg-white/[.035] p-3 text-xs text-stone-400"><div>Import status: <b className="capitalize text-stone-200">{dump.data.status}</b></div>{dump.data.status === 'failed' && <p className="mt-2">Dump ID: {dumpId}</p>}{dump.data.results?.map((row, index) => <p key={`${row.identity}-${index}`} className="mt-2">{row.identity}: {row.message}</p>)}</div>}
            </Card>
            <Card>
                <div className="mb-5 flex items-center justify-between"><div><h2 className="text-lg font-semibold">Your characters</h2><p className="mt-1 text-xs text-stone-500">Flavor-aware identities and profession snapshots.</p></div><span className="text-xs text-stone-500">{characters.data?.length ?? '—'} characters</span></div>
                <div className="space-y-3">
                    {characters.data?.map((character) => {
                        const matchingGuilds = approvedGuilds.filter((guild) => guild.gameFlavor === character.gameFlavor && guild.region === character.region && guild.realm === character.realm);
                        const guildId = selectedGuild[character.id] ?? matchingGuilds.find((guild) => character.guildIds.includes(guild.id))?.id ?? matchingGuilds[0]?.id ?? '';
                        return <div key={character.id} className="rounded-xl border border-white/[.07] bg-white/[.025] p-4">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div className="flex items-center gap-3"><span className={`flex h-10 w-10 items-center justify-center rounded-xl text-sm font-bold ${character.faction === 'alliance' ? 'bg-blue-950 text-blue-300' : 'bg-red-950 text-red-300'}`}>{character.class.slice(0, 2)}</span><div><div className="flex items-center gap-2 font-medium">{character.characterName}<FlavorBadge flavor={character.gameFlavor} /></div><div className="mt-1 text-xs text-stone-500">{character.realm} · {character.region.toUpperCase()} · synced {new Date(character.lastSyncedAt).toLocaleDateString()}</div></div></div>
                                {character.claimStatus === 'claimable' ? <Button onClick={() => claim.mutate(character.id)} disabled={claim.isPending}>Claim character</Button> : <Button kind="danger" onClick={() => { if (window.confirm(`Remove ${character.characterName} from your roster?`)) remove.mutate(character.id); }} disabled={remove.isPending}>Delete</Button>}
                            </div>
                            {character.claimStatus === 'claimed' && <div className="mt-4 flex flex-col gap-2 border-t border-white/[.06] pt-3 sm:flex-row sm:items-center"><label className="flex-1 text-xs text-stone-500">Approved guild<select value={guildId} onChange={(event) => setSelectedGuild({ ...selectedGuild, [character.id]: event.target.value })} className="mt-1.5 w-full rounded-lg border border-white/10 bg-[#151513] px-3 py-2 text-sm text-stone-200"><option value="">No approved guild membership</option>{matchingGuilds.map((guild) => <option key={guild.id} value={guild.id}>{guild.name}</option>)}</select></label><Button kind="subtle" disabled={!guildId || character.guildIds.includes(guildId) || assign.isPending} onClick={() => assign.mutate({ characterId: character.id, guildId })}>{character.guildIds.includes(guildId) ? 'Already assigned' : 'Assign character'}</Button></div>}
                        </div>;
                    })}
                    {characters.isLoading && <div className="text-sm text-stone-500">Loading roster…</div>}
                    {characters.data?.length === 0 && <div className="rounded-xl border border-dashed border-white/10 p-9 text-center text-sm text-stone-500">Your ledger is empty. Import an export to begin.</div>}
                    <CursorLoadMore cursor={characters.data?.nextCursor} label="Load more characters" onLoadMore={() => void loadCursorPage(client, ['characters'], '/characters', characters.data)} />
                </div>
            </Card>
        </div>
    </>;
}

function CraftingPage() {
    const [params, setParams] = useSearchParams();
    const [query, setQuery] = useState('');
    const [error, setError] = useState('');
    const [success, setSuccess] = useState('');
    const [orderOptions, setOrderOptions] = useState<Record<string, { quantity: string; crafterCharacterId: string; materialsProvided: string; notes: string; recipientCharacterName: string }>>({});
    const client = useQueryClient();
    const guilds = useQuery({ queryKey: ['guilds'], queryFn: () => api<CursorList<Guild>>('/guilds') });
    const approvedGuilds = guilds.data?.filter((guild) => guild.membershipStatus === 'approved') ?? [];
    const guildId = params.get('guild') ?? approvedGuilds[0]?.id ?? '';
    const recipePath = `/guilds/${guildId}/recipes?query=${encodeURIComponent(query)}`;
    const recipes = useQuery({ queryKey: ['recipes', guildId, query], queryFn: () => api<CursorList<Recipe>>(recipePath), enabled: !!guildId });
    const cart = useQuery({
        queryKey: ['cart', guildId],
        queryFn: () => api<{ items: { id: string; recipeId: string; spellID: number; quantity: number; crafterCharacterId: string; crafterCharacterName: string; materialsProvided: string; notes: string | null; recipientCharacterName: string | null; crafters: Recipe['crafters'] }[]; reagentTotals: { itemID: number; name: string; quantity: number }[] }>(`/guilds/${guildId}/cart`),
        enabled: !!guildId,
    });
    const add = useMutation({
        mutationFn: (data: { spellID: number; quantity: number; crafterCharacterId: string; materialsProvided: string; notes: string; recipientCharacterName: string }) => api(`/guilds/${guildId}/cart/items`, { method: 'POST', body: JSON.stringify(data) }),
        onSuccess: () => { setSuccess('Recipe added to your order cart.'); void client.invalidateQueries({ queryKey: ['cart', guildId] }); },
        onError: (exception) => setError(exception instanceof Error ? exception.message : 'Could not add recipe.'),
    });
    const updateItem = useMutation({
        mutationFn: ({ id, data }: { id: string; data: Record<string, unknown> }) => api(`/guilds/${guildId}/cart/items/${id}`, { method: 'PATCH', body: JSON.stringify(data) }),
        onSuccess: () => { void client.invalidateQueries({ queryKey: ['cart', guildId] }); },
        onError: (exception) => setError(exception instanceof Error ? exception.message : 'Could not update cart item.'),
    });
    const removeItem = useMutation({
        mutationFn: (id: string) => api(`/guilds/${guildId}/cart/items/${id}`, { method: 'DELETE' }),
        onSuccess: () => { setSuccess('Recipe removed from the order cart.'); void client.invalidateQueries({ queryKey: ['cart', guildId] }); },
        onError: (exception) => setError(exception instanceof Error ? exception.message : 'Could not remove recipe.'),
    });
    const checkout = useMutation({
        mutationFn: () => api('/guilds/'+guildId+'/cart/checkout', { method: 'POST', headers: { 'Idempotency-Key': crypto.randomUUID() }, body: '{}' }),
        onSuccess: () => { setSuccess('Requests sent to your selected crafters.'); void client.invalidateQueries({ queryKey: ['cart', guildId] }); void client.invalidateQueries({ queryKey: ['outgoing'] }); },
        onError: (exception) => setError(exception instanceof Error ? exception.message : 'Checkout failed.'),
    });
    const updateOption = (recipe: Recipe, field: 'quantity' | 'crafterCharacterId' | 'materialsProvided' | 'notes' | 'recipientCharacterName', value: string) => {
        setOrderOptions((current) => ({
            ...current,
            [recipe.id]: {
                quantity: current[recipe.id]?.quantity ?? '1',
                crafterCharacterId: current[recipe.id]?.crafterCharacterId ?? recipe.crafters[0]?.characterId ?? '',
                materialsProvided: current[recipe.id]?.materialsProvided ?? 'requester',
                notes: current[recipe.id]?.notes ?? '',
                recipientCharacterName: current[recipe.id]?.recipientCharacterName ?? '',
                [field]: value,
            },
        }));
    };
    return <>
        <Title note="Find a recipe in your approved guild roster, then route a commission to a character who knows it.">Crafting directory</Title>
        <Notice error={error} success={success} />
        <div className="mb-5 flex flex-wrap gap-3">
            <select value={guildId} onChange={(event) => setParams({ guild: event.target.value })} className="min-w-52 rounded-xl border border-white/10 bg-[#191918] px-3 py-3 text-sm">{approvedGuilds.map((guild) => <option key={guild.id} value={guild.id}>{guild.name} · {guild.realm}</option>)}</select>
            <input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Search recipe, item, or spell ID…" className="min-w-64 flex-1 rounded-xl border border-white/10 bg-[#191918] px-4 py-3 text-sm outline-none focus:border-[#b68a4e]" />
        </div>
        <div className="grid gap-5 xl:grid-cols-[1fr_380px]">
            <Card>
                <div className="mb-4 flex items-center justify-between"><h2 className="font-semibold">Guild recipes</h2><span className="text-xs text-stone-500">Only approved guild crafters</span></div>
                <div className="space-y-3">
                    {recipes.data?.map((recipe) => {
                        const options = orderOptions[recipe.id] ?? { quantity: '1', crafterCharacterId: recipe.crafters[0]?.characterId ?? '', materialsProvided: 'requester', notes: '', recipientCharacterName: '' };
                        return <article key={recipe.id} className="rounded-xl border border-white/[.07] p-4">
                            <div className="flex items-center gap-2"><span className="text-lg text-[#d1a96b]">✧</span><h3 className="font-medium">{recipe.name}</h3></div>
                            <div className="mt-1 text-xs text-stone-600">Spell #{recipe.spellID} · Skill line {recipe.skillLineID}</div>
                            <div className="mt-3 grid gap-2 sm:grid-cols-2">
                                <label className="text-[10px] uppercase tracking-wider text-stone-600">Quantity<input type="number" min="1" max="10000" value={options.quantity} onChange={(event) => updateOption(recipe, 'quantity', event.target.value)} className="mt-1.5 w-full rounded-lg border border-white/10 bg-[#151513] px-2.5 py-2 text-sm text-stone-200" /></label>
                                <label className="text-[10px] uppercase tracking-wider text-stone-600">Send to<select value={options.crafterCharacterId} onChange={(event) => updateOption(recipe, 'crafterCharacterId', event.target.value)} className="mt-1.5 w-full rounded-lg border border-white/10 bg-[#151513] px-2.5 py-2 text-xs text-stone-200">{recipe.crafters.map((crafter) => <option key={crafter.characterId} value={crafter.characterId}>{crafter.characterName} · {crafter.currentSkill}/{crafter.maxSkill}</option>)}</select></label>
                                <label className="text-[10px] uppercase tracking-wider text-stone-600">Materials<select value={options.materialsProvided} onChange={(event) => updateOption(recipe, 'materialsProvided', event.target.value)} className="mt-1.5 w-full rounded-lg border border-white/10 bg-[#151513] px-2.5 py-2 text-xs text-stone-200"><option value="requester">I’ll provide materials</option><option value="crafter">Crafter provides materials</option><option value="partial">Partial materials</option></select></label>
                                <label className="text-[10px] uppercase tracking-wider text-stone-600">Recipient<input value={options.recipientCharacterName} onChange={(event) => updateOption(recipe, 'recipientCharacterName', event.target.value)} maxLength={128} placeholder="Optional character" className="mt-1.5 w-full rounded-lg border border-white/10 bg-[#151513] px-2.5 py-2 text-xs text-stone-200" /></label>
                                <label className="text-[10px] uppercase tracking-wider text-stone-600">Note<input value={options.notes} onChange={(event) => updateOption(recipe, 'notes', event.target.value)} maxLength={2000} placeholder="Optional request note" className="mt-1.5 w-full rounded-lg border border-white/10 bg-[#151513] px-2.5 py-2 text-xs text-stone-200" /></label>
                            </div>
                            <Button className="mt-3 w-full sm:w-auto" onClick={() => add.mutate({ spellID: recipe.spellID, quantity: Math.min(10000, Math.max(1, Number(options.quantity) || 1)), crafterCharacterId: options.crafterCharacterId, materialsProvided: options.materialsProvided, notes: options.notes, recipientCharacterName: options.recipientCharacterName })} disabled={add.isPending || recipe.crafters.length === 0}>Add to cart</Button>
                        </article>;
                    })}
                    {recipes.isLoading && <p className="py-10 text-center text-sm text-stone-500">Searching the guild recipe books…</p>}
                    {recipes.data?.length === 0 && <p className="py-10 text-center text-sm text-stone-500">No matching recipes found in this guild.</p>}
                    <CursorLoadMore cursor={recipes.data?.nextCursor} label="Load more recipes" onLoadMore={() => void loadCursorPage(client, ['recipes', guildId, query], recipePath, recipes.data)} />
                    {!guildId && <p className="py-10 text-center text-sm text-stone-500">Join an approved guild to browse its recipes.</p>}
                </div>
            </Card>
            <Card className="h-fit">
                <div className="flex items-center justify-between"><div><h2 className="font-semibold">Order cart</h2><p className="mt-1 text-xs text-stone-500">BOM calculated from known reagents.</p></div><span className="rounded-full bg-[#c39a5d]/10 px-2.5 py-1 text-xs text-[#d4ae71]">{cart.data?.items.length ?? 0}</span></div>
                <div className="my-5 space-y-3">{cart.data?.items.map((item) => <div key={item.id} className="rounded-lg bg-white/[.03] p-3"><div className="flex items-center justify-between text-xs"><span>Recipe #{item.spellID} · {item.crafterCharacterName}</span><button onClick={() => removeItem.mutate(item.id)} className="text-stone-600 hover:text-rose-300">Remove</button></div><div className="mt-2 grid grid-cols-2 gap-2"><label className="text-[9px] uppercase tracking-wider text-stone-600">Qty<input type="number" min="1" max="10000" defaultValue={item.quantity} onBlur={(event) => { const quantity = Number(event.target.value); if (quantity > 0 && quantity !== item.quantity) updateItem.mutate({ id: item.id, data: { quantity } }); }} className="mt-1 w-full rounded-md border border-white/10 bg-[#151513] px-2 py-1.5 text-xs text-stone-200" /></label><label className="text-[9px] uppercase tracking-wider text-stone-600">Crafter<select value={item.crafterCharacterId} onChange={(event) => updateItem.mutate({ id: item.id, data: { crafterCharacterId: event.target.value } })} className="mt-1 w-full rounded-md border border-white/10 bg-[#151513] px-2 py-1.5 text-xs text-stone-200">{item.crafters.map((crafter) => <option key={crafter.characterId} value={crafter.characterId}>{crafter.characterName}</option>)}</select></label><label className="col-span-2 text-[9px] uppercase tracking-wider text-stone-600">Recipient<input defaultValue={item.recipientCharacterName ?? ''} maxLength={128} onBlur={(event) => { if (event.target.value !== (item.recipientCharacterName ?? '')) updateItem.mutate({ id: item.id, data: { recipientCharacterName: event.target.value || null } }); }} placeholder="Optional character" className="mt-1 w-full rounded-md border border-white/10 bg-[#151513] px-2 py-1.5 text-xs text-stone-200" /></label><label className="col-span-2 text-[9px] uppercase tracking-wider text-stone-600">Materials<select value={item.materialsProvided} onChange={(event) => updateItem.mutate({ id: item.id, data: { materialsProvided: event.target.value } })} className="mt-1 w-full rounded-md border border-white/10 bg-[#151513] px-2 py-1.5 text-xs text-stone-200"><option value="requester">Requester provides</option><option value="crafter">Crafter provides</option><option value="partial">Partial</option></select></label></div></div>)}</div>
                <div className="border-t border-white/[.08] pt-4"><div className="mb-3 text-[10px] uppercase tracking-[.2em] text-stone-500">Bill of materials</div>{cart.data?.reagentTotals.map((reagent) => <div key={reagent.itemID} className="flex justify-between py-1.5 text-xs"><span className="text-stone-400">{reagent.name}</span><span className="font-medium text-stone-300">× {reagent.quantity}</span></div>)}{!cart.data?.reagentTotals.length && <p className="text-xs text-stone-600">Reagents will appear here.</p>}</div>
                <Button className="mt-5 w-full" disabled={!cart.data?.items.length || checkout.isPending} onClick={() => checkout.mutate()}>{checkout.isPending ? 'Routing orders…' : 'Send crafting requests →'}</Button>
            </Card>
        </div>
    </>;
}

function RequestsPage() {
    const [kind, setKind] = useState<'incoming' | 'outgoing'>('incoming');
    const [status, setStatus] = useState('all');
    const [error, setError] = useState('');
    const client = useQueryClient();
    const querySuffix = status === 'all' ? '' : `?status=${encodeURIComponent(status)}`;
    const requestPath = `/me/requests/${kind}${querySuffix}`;
    const requests = useQuery({ queryKey: [kind, status], queryFn: () => api<CursorList<CraftRequest>>(requestPath) });
    const action = useMutation({
        mutationFn: ({ id, action }: { id: string; action: string }) => api(`/requests/${id}/${action}`, { method: 'POST', body: '{}' }),
        onSuccess: () => { void client.invalidateQueries({ queryKey: ['incoming'] }); void client.invalidateQueries({ queryKey: ['outgoing'] }); },
        onError: (exception) => setError(exception instanceof Error ? exception.message : 'Request action failed.'),
    });
    return <>
        <Title note="Requests retain a snapshot of known reagent quantities and the character selected to craft each item.">Commission ledger</Title>
        <Notice error={error} />
        <div className="mb-5 flex flex-wrap items-center gap-2">
            <Button kind={kind === 'incoming' ? 'primary' : 'subtle'} onClick={() => setKind('incoming')}>Incoming</Button>
            <Button kind={kind === 'outgoing' ? 'primary' : 'subtle'} onClick={() => setKind('outgoing')}>Outgoing</Button>
            <select aria-label="Filter requests by status" value={status} onChange={(event) => setStatus(event.target.value)} className="ml-auto rounded-xl border border-white/10 bg-[#191918] px-3 py-2.5 text-xs text-stone-300">
                <option value="all">All requests</option><option value="pending">Pending</option><option value="accepted">Accepted</option><option value="history">History</option>
            </select>
        </div>
        <Card><div className="space-y-3">
            {requests.data?.map((request) => <div key={request.id} className="flex flex-col gap-4 rounded-xl border border-white/[.07] p-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <div className="flex items-center gap-2"><h3 className="font-medium">{request.recipeName} × {request.quantity}</h3><span className={`rounded-full px-2 py-1 text-[10px] uppercase tracking-wider ${request.status === 'pending' ? 'bg-amber-950 text-amber-300' : request.status === 'completed' ? 'bg-emerald-950 text-emerald-300' : 'bg-white/10 text-stone-400'}`}>{request.status}</span></div>
                    <p className="mt-1 text-xs text-stone-500">Crafter: {request.crafterCharacterName} · {request.materialsProvided} materials{request.recipientCharacterName ? ` · for ${request.recipientCharacterName}` : ''} · {new Date(request.createdAt).toLocaleString()}</p>
                    {request.reagents && <p className="mt-2 text-xs text-stone-600">{request.reagents.map((reagent) => `${reagent.name} × ${reagent.quantity}`).join(' · ')}</p>}
                    {request.crafterNotes && <p className="mt-2 text-xs text-amber-200/80">Crafter note: {request.crafterNotes}</p>}
                </div>
                <div className="flex flex-wrap gap-2">
                    {kind === 'incoming' && request.status === 'pending' && <><Button onClick={() => action.mutate({ id: request.id, action: 'accept' })}>Accept</Button><Button kind="danger" onClick={() => action.mutate({ id: request.id, action: 'deny' })}>Deny</Button></>}
                    {kind === 'incoming' && request.status === 'accepted' && <Button onClick={() => action.mutate({ id: request.id, action: 'complete' })}>Mark complete</Button>}
                    {kind === 'outgoing' && request.status === 'accepted' && <Button onClick={() => action.mutate({ id: request.id, action: 'completion-confirmation' })}>Confirm complete</Button>}
                    {kind === 'outgoing' && ['pending', 'accepted'].includes(request.status) && <Button kind="subtle" onClick={() => action.mutate({ id: request.id, action: 'cancel' })}>Cancel request</Button>}
                </div>
            </div>)}
            {requests.isLoading && <p className="py-10 text-center text-sm text-stone-500">Loading commission ledger…</p>}
            {requests.data?.length === 0 && <p className="py-10 text-center text-sm text-stone-500">No matching {kind} requests.</p>}
            <CursorLoadMore cursor={requests.data?.nextCursor} label="Load more requests" onLoadMore={() => void loadCursorPage(client, [kind, status], requestPath, requests.data)} />
        </div></Card>
    </>;
}

function AccountPage({ account, onAccount }: { account: Account; onAccount: (account: Account) => void }) {
    const [notice, setNotice] = useState('');
    const [error, setError] = useState('');
    const discordLinked = new URLSearchParams(location.search).get('discord') === 'linked';
    const tokens = useQuery({ queryKey: ['tokens'], queryFn: () => api<AccessToken[]>('/me/tokens') });
    const passwordForm = useForm<{ currentPassword: string; password: string; password_confirmation: string }>();
    useEffect(() => { if (discordLinked) void api<Account>('/me').then(onAccount); }, [discordLinked, onAccount]);
    const updatePassword = useMutation({
        mutationFn: (data: { currentPassword: string; password: string; password_confirmation: string }) => api<{ passwordUpdated: boolean }>('/me/password', { method: 'PATCH', body: JSON.stringify(data) }),
        onSuccess: () => { passwordForm.reset(); setNotice('Password updated. All desktop access tokens were revoked.'); void queryClient.invalidateQueries({ queryKey: ['tokens'] }); },
        onError: (exception) => setError(exception instanceof Error ? exception.message : 'Could not update password.'),
    });
    const revokeToken = useMutation({
        mutationFn: (id: string) => api(`/auth/tokens/${id}`, { method: 'DELETE' }),
        onSuccess: () => { setNotice('Desktop token revoked.'); void queryClient.invalidateQueries({ queryKey: ['tokens'] }); },
        onError: (exception) => setError(exception instanceof Error ? exception.message : 'Could not revoke token.'),
    });
    const unlinkDiscord = async () => {
        try { await api('/me/discord', { method: 'DELETE' }); onAccount({ ...account, discord: null }); setNotice('Discord account unlinked.'); }
        catch (exception) { setError(exception instanceof Error ? exception.message : 'Could not unlink Discord.'); }
    };
    return <>
        <Title note="Manage the identity attached to your guild memberships, desktop uploads, and bot interactions.">Account settings</Title>
        <Notice error={error} success={notice || (discordLinked ? 'Discord identity linked successfully.' : '')} />
        <div className="grid gap-5 lg:grid-cols-2">
            <Card>
                <h2 className="text-lg font-semibold">Flynn account</h2>
                <div className="mt-5 space-y-4">
                    <div><div className="text-xs text-stone-500">Display name</div><div className="mt-1">{account.name}</div></div>
                    <div><div className="text-xs text-stone-500">Email</div><div className="mt-1">{account.email}</div></div>
                    <div><div className="text-xs text-stone-500">Account ID</div><div className="mt-1 font-mono text-xs text-stone-500">{account.id}</div></div>
                </div>
            </Card>
            <Card>
                <h2 className="text-lg font-semibold">Discord identity</h2>
                <p className="mt-2 text-sm text-stone-500">Verified through Discord OAuth2. Required for acting through the guild bot.</p>
                <div className="my-5 rounded-xl border border-white/[.07] p-4"><div className="text-xs text-stone-500">Linked identity</div><div className="mt-2 flex items-center justify-between"><span>{account.discord?.handle ?? 'No Discord account linked'}</span><span className="text-xs text-stone-500">{account.discord?.discordUserID ?? ''}</span></div></div>
                {account.discord ? <Button kind="danger" onClick={() => void unlinkDiscord()}>Unlink Discord</Button> : <Button onClick={() => { window.location.href = '/api/v1/auth/discord/redirect'; }}>Link Discord identity ↗</Button>}
            </Card>
            <Card>
                <h2 className="text-lg font-semibold">Change password</h2>
                <p className="mb-5 mt-1 text-xs text-stone-500">Changing your password revokes existing desktop tokens.</p>
                <form className="space-y-3" onSubmit={passwordForm.handleSubmit((data) => updatePassword.mutate(data))}>
                    <Field label="Current password" type="password" autoComplete="current-password" {...passwordForm.register('currentPassword', { required: true })} />
                    <Field label="New password" type="password" autoComplete="new-password" {...passwordForm.register('password', { required: true, minLength: 12 })} />
                    <Field label="Confirm new password" type="password" autoComplete="new-password" {...passwordForm.register('password_confirmation', { required: true })} />
                    <Button type="submit" disabled={updatePassword.isPending}>{updatePassword.isPending ? 'Updating…' : 'Update password'}</Button>
                </form>
            </Card>
            <Card>
                <div className="mb-4 flex items-center justify-between"><div><h2 className="text-lg font-semibold">Desktop access</h2><p className="mt-1 text-xs text-stone-500">Sanctum tokens can upload dumps and read account identity.</p></div><span className="text-xs text-stone-500">{tokens.data?.length ?? 0} tokens</span></div>
                <div className="space-y-2">{tokens.data?.map((token) => <div key={token.id} className="flex items-center justify-between rounded-xl border border-white/[.07] px-3 py-3"><div><div className="text-sm font-medium">{token.name}</div><div className="mt-1 text-[10px] text-stone-600">Created {new Date(token.created_at).toLocaleDateString()} · last used {token.last_used_at ? new Date(token.last_used_at).toLocaleDateString() : 'never'}</div></div><Button kind="danger" onClick={() => revokeToken.mutate(token.id)} disabled={revokeToken.isPending}>Revoke</Button></div>)}{tokens.data?.length === 0 && <div className="rounded-xl border border-dashed border-white/10 p-5 text-center text-xs text-stone-600">No desktop access tokens are active.</div>}</div>
            </Card>
        </div>
    </>;
}

function GuildInvitePage({ account, onAuthenticated }: { account: Account | null; onAuthenticated: (account: Account) => void }) {
    const { inviteToken = '' } = useParams();
    const navigate = useNavigate();
    const location = useLocation();
    const [error, setError] = useState('');
    const apply = useMutation({
        mutationFn: () => api<{ guildId: string }>('/guild-invites/'+inviteToken+'/applications', { method: 'POST', body: '{}' }),
        onSuccess: () => { void queryClient.invalidateQueries({ queryKey: ['guilds'] }); navigate('/guilds'); },
        onError: (exception) => setError(exception instanceof Error ? exception.message : 'This guild invite could not be used.'),
    });
    if (!account) return <AuthPage mode="login" onAuthenticated={onAuthenticated} returnTo={`${location.pathname}${location.search}`} />;
    return <main className="rune-grid flex min-h-screen items-center justify-center px-5"><Card className="w-full max-w-lg"><div className="mb-5 text-3xl text-[#d1a96b]">♜</div><Title note="An invite is a path to apply, not an instant membership. Guild leadership must approve your application.">Guild invitation</Title><Notice error={error} /><div className="rounded-xl border border-white/[.07] bg-white/[.025] p-4 text-sm text-stone-400">Signed in as <b className="text-stone-200">{account.name}</b></div><div className="mt-5 flex gap-3"><Button onClick={() => apply.mutate()} disabled={apply.isPending}>{apply.isPending ? 'Sending application…' : 'Apply to this guild →'}</Button><Link to="/guilds" className="rounded-xl border border-white/10 px-4 py-2.5 text-sm text-stone-400">Not now</Link></div></Card></main>;
}

function DesktopConsentPage({ account, onAuthenticated }: { account: Account | null; onAuthenticated: (account: Account) => void }) {
    const [params] = useSearchParams();
    const location = useLocation();
    const codeChallenge = params.get('codeChallenge') ?? '';
    const state = params.get('state') ?? '';
    const redirectUri = params.get('redirectUri') ?? '';
    let callbackHost = 'the registered Flynn Desktop app';
    try { callbackHost = new URL(redirectUri).host; } catch { /* Invalid query parameters are reported below. */ }
    const validRequest = /^[A-Za-z0-9_-]{43}$/.test(codeChallenge) && state.length >= 16 && state.length <= 256 && !!redirectUri;
    if (!account) return <AuthPage mode="login" onAuthenticated={onAuthenticated} returnTo={`${location.pathname}${location.search}`} />;
    if (!validRequest) return <main className="rune-grid flex min-h-screen items-center justify-center px-5"><Card className="w-full max-w-lg"><Title>Invalid authorization request</Title><p className="text-sm text-stone-400">The desktop authorization details are incomplete or invalid. Return to Flynn Desktop and start sign-in again.</p><Link to="/" className="mt-5 inline-block text-sm text-[#d3ad70]">Return to Flynn</Link></Card></main>;
    const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
    return <main className="rune-grid flex min-h-screen items-center justify-center px-5 py-12"><Card className="w-full max-w-xl p-8 md:p-10"><div className="mb-6 text-3xl text-[#d1a96b]">✦</div><div className="text-[10px] font-bold uppercase tracking-[.25em] text-[#c39a5d]">Flynn Desktop</div><h1 className="mt-3 text-3xl font-semibold">Authorize this device?</h1><p className="mt-3 text-sm leading-6 text-stone-400"><b className="text-stone-200">{account.name}</b> is signed in. Authorizing redirects a one-time code to <span className="text-[#d3ad70]">{callbackHost}</span>.</p><div className="my-6 rounded-xl border border-white/[.07] bg-white/[.025] p-4"><div className="text-xs font-semibold text-stone-200">Flynn Desktop will be allowed to</div><ul className="mt-3 space-y-2 text-xs text-stone-500"><li>• Read your Flynn account identity</li><li>• Upload your Flynn character export files</li></ul></div><form method="POST" action="/api/v1/auth/desktop/authorize"><input type="hidden" name="_token" value={csrfToken} /><input type="hidden" name="codeChallenge" value={codeChallenge} /><input type="hidden" name="state" value={state} /><input type="hidden" name="redirectUri" value={redirectUri} /><div className="flex flex-wrap gap-3"><Button type="submit">Authorize Flynn Desktop →</Button><Link to="/" className="rounded-xl border border-white/10 px-4 py-2.5 text-sm text-stone-400 hover:bg-white/[.04]">Cancel</Link></div></form><p className="mt-6 text-[10px] leading-5 text-stone-600">The authorization code can only be used once and is bound to this device’s PKCE verifier. Flynn Desktop never receives your password.</p></Card></main>;
}

export function App() {
    const [account, setAccount] = useState<Account | null>(null); const [loaded, setLoaded] = useState(false); const navigate = useNavigate(); const query = useQueryClient();
    const [logoutPending, setLogoutPending] = useState(false); const [logoutError, setLogoutError] = useState(''); const logoutInFlight = useRef(false);
    const location = useLocation();
    useEffect(() => { void api<Account>('/me').then((current) => setAccount(current)).catch(() => setAccount(null)).finally(() => setLoaded(true)); }, []);
    const clearLocalSession = () => { setAccount(null); query.clear(); navigate('/login'); };
    const logout = async () => {
        if (logoutInFlight.current) return;
        logoutInFlight.current = true;
        setLogoutPending(true);
        setLogoutError('');
        try {
            await logoutRequest();
            clearLocalSession();
        } catch (exception) {
            if (exception instanceof ApiError && exception.status === 401) {
                clearLocalSession();
            } else {
                setLogoutError('Sign-out could not be completed. You are still signed in; please try again.');
            }
        } finally {
            logoutInFlight.current = false;
            setLogoutPending(false);
        }
    };
    if (!loaded) return <div className="flex min-h-screen items-center justify-center bg-[#111110] text-sm text-stone-500">Opening your ledger…</div>;
    return <Routes><Route path="/desktop/authorize" element={<DesktopConsentPage account={account} onAuthenticated={setAccount} />} /><Route path="/guild-invites/:inviteToken" element={<GuildInvitePage account={account} onAuthenticated={setAccount} />} /><Route path="/login" element={account ? <HomePage account={account} /> : <AuthPage mode="login" onAuthenticated={setAccount} returnTo={new URLSearchParams(location.search).get('returnTo') ?? '/'} />} /><Route path="/register" element={account ? <HomePage account={account} /> : <AuthPage mode="register" onAuthenticated={setAccount} returnTo={new URLSearchParams(location.search).get('returnTo') ?? '/'} />} /><Route path="*" element={account ? <Shell account={account} onLogout={() => void logout()} logoutPending={logoutPending} logoutError={logoutError}><Routes><Route path="/" element={<HomePage account={account} />} /><Route path="/guilds" element={<GuildsPage />} /><Route path="/characters" element={<CharactersPage />} /><Route path="/crafting" element={<CraftingPage />} /><Route path="/requests" element={<RequestsPage />} /><Route path="/account" element={<AccountPage account={account} onAccount={setAccount} />} /><Route path="*" element={<HomePage account={account} />} /></Routes></Shell> : <AuthPage mode="login" onAuthenticated={setAccount} />} /></Routes>;
}

const mount = document.getElementById('app');
if (mount) createRoot(mount).render(<React.StrictMode><QueryClientProvider client={queryClient}><BrowserRouter><App /></BrowserRouter></QueryClientProvider></React.StrictMode>);
