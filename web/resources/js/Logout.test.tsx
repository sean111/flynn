// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { App } from './app';

const account = { id: '1', name: 'Aria', email: 'aria@example.com', discord: null };

function jsonResponse(status: number, body: unknown) {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function renderApp(fetchMock: ReturnType<typeof vi.fn>) {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    queryClient.setQueryData(['private-cache'], { value: 'private' });
    vi.stubGlobal('fetch', fetchMock);
    render(<QueryClientProvider client={queryClient}><MemoryRouter><App /></MemoryRouter></QueryClientProvider>);
    return queryClient;
}

function createFetchMock(logoutResponse: (call: number) => Promise<Response> | Response) {
    let logoutCalls = 0;
    return vi.fn(async (input: RequestInfo | URL) => {
        const url = String(input);
        if (url === '/sanctum/csrf-cookie') return new Response(null, { status: 204 });
        if (url === '/api/v1/me') return jsonResponse(200, { data: account });
        if (url === '/api/v1/auth/logout') return logoutResponse(++logoutCalls);
        return jsonResponse(200, { data: [] });
    });
}

async function openSignedInApp(fetchMock: ReturnType<typeof vi.fn>) {
    const queryClient = renderApp(fetchMock);
    await screen.findByText('Signed in as');
    return queryClient;
}

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('sign out', () => {
    it('clears local account and query state and navigates to login after a successful 204', async () => {
        const fetchMock = createFetchMock(() => new Response(null, { status: 204 }));
        const queryClient = await openSignedInApp(fetchMock);

        fireEvent.click(screen.getByRole('button', { name: /sign out/i }));

        expect(await screen.findByRole('heading', { name: 'Welcome back' })).toBeTruthy();
        expect(queryClient.getQueryData(['private-cache'])).toBeUndefined();
        expect(fetchMock).toHaveBeenCalledWith('/api/v1/auth/logout', expect.objectContaining({ method: 'POST' }));
    });

    it('clears local state when logout returns 401 because the session already expired', async () => {
        const fetchMock = createFetchMock(() => jsonResponse(401, { error: { code: 'unauthenticated', message: 'Unauthenticated.', details: {}, requestId: 'request-1' } }));
        const queryClient = await openSignedInApp(fetchMock);

        fireEvent.click(screen.getByRole('button', { name: /sign out/i }));

        expect(await screen.findByRole('heading', { name: 'Welcome back' })).toBeTruthy();
        expect(queryClient.getQueryData(['private-cache'])).toBeUndefined();
        expect(screen.queryByRole('alert')).toBeNull();
    });

    it('shows pending state and submits only once while sign out is in flight', async () => {
        let finishLogout!: (response: Response) => void;
        const pendingResponse = new Promise<Response>((resolve) => { finishLogout = resolve; });
        const fetchMock = createFetchMock(() => pendingResponse);
        await openSignedInApp(fetchMock);

        fireEvent.click(screen.getByRole('button', { name: /sign out/i }));
        const pendingButton = await screen.findByRole('button', { name: 'Signing out…' });
        fireEvent.click(pendingButton);

        expect(pendingButton.hasAttribute('disabled')).toBe(true);
        expect(fetchMock.mock.calls.filter(([url]) => String(url) === '/api/v1/auth/logout')).toHaveLength(1);

        finishLogout(new Response(null, { status: 204 }));
        expect(await screen.findByRole('heading', { name: 'Welcome back' })).toBeTruthy();
    });

    it('refreshes CSRF once after 419 and retries the logout only once', async () => {
        const events: string[] = [];
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const url = String(input);
            events.push(url);
            if (url === '/sanctum/csrf-cookie') return new Response(null, { status: 204 });
            if (url === '/api/v1/me') return jsonResponse(200, { data: account });
            if (url === '/api/v1/auth/logout') {
                const attempt = events.filter((event) => event === url).length;
                return attempt === 1 ? jsonResponse(419, { message: 'Page Expired' }) : new Response(null, { status: 204 });
            }
            return jsonResponse(200, { data: [] });
        });
        await openSignedInApp(fetchMock);

        fireEvent.click(screen.getByRole('button', { name: /sign out/i }));

        expect(await screen.findByRole('heading', { name: 'Welcome back' })).toBeTruthy();
        const logoutIndexes = events.flatMap((event, index) => event === '/api/v1/auth/logout' ? [index] : []);
        expect(logoutIndexes).toHaveLength(2);
        expect(events.slice(logoutIndexes[0] + 1, logoutIndexes[1])).toEqual(['/sanctum/csrf-cookie']);
    });

    it('does not retry after a repeated 419 and keeps the signed-in view with retry guidance', async () => {
        const events: string[] = [];
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const url = String(input);
            events.push(url);
            if (url === '/sanctum/csrf-cookie') return new Response(null, { status: 204 });
            if (url === '/api/v1/me') return jsonResponse(200, { data: account });
            if (url === '/api/v1/auth/logout') return jsonResponse(419, { message: 'Page Expired' });
            return jsonResponse(200, { data: [] });
        });
        await openSignedInApp(fetchMock);

        fireEvent.click(screen.getByRole('button', { name: /sign out/i }));

        expect((await screen.findByRole('alert')).textContent).toContain('You are still signed in; please try again.');
        expect(screen.getByText('Signed in as')).toBeTruthy();
        expect(events.filter((event) => event === '/api/v1/auth/logout')).toHaveLength(2);
        const logoutIndexes = events.flatMap((event, index) => event === '/api/v1/auth/logout' ? [index] : []);
        expect(events.slice(logoutIndexes[0] + 1, logoutIndexes[1])).toEqual(['/sanctum/csrf-cookie']);
    });

    it('does not retry when refreshing the CSRF cookie fails', async () => {
        let logoutCalls = 0;
        const events: string[] = [];
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const url = String(input);
            events.push(url);
            if (url === '/sanctum/csrf-cookie') return new Response(null, { status: logoutCalls === 0 ? 204 : 503 });
            if (url === '/api/v1/me') return jsonResponse(200, { data: account });
            if (url === '/api/v1/auth/logout') { logoutCalls += 1; return jsonResponse(419, { message: 'Page Expired' }); }
            return jsonResponse(200, { data: [] });
        });
        await openSignedInApp(fetchMock);

        fireEvent.click(screen.getByRole('button', { name: /sign out/i }));

        expect((await screen.findByRole('alert')).textContent).toContain('please try again');
        expect(events.filter((event) => event === '/api/v1/auth/logout')).toHaveLength(1);
        const logoutIndex = events.indexOf('/api/v1/auth/logout');
        expect(events.slice(logoutIndex + 1)).toContain('/sanctum/csrf-cookie');
        expect(screen.getByText('Signed in as')).toBeTruthy();
    });

    it('keeps the signed-in view and retry guidance when the logout request fails over the network', async () => {
        const fetchMock = createFetchMock(() => Promise.reject(new TypeError('Network unavailable')));
        await openSignedInApp(fetchMock);

        fireEvent.click(screen.getByRole('button', { name: /sign out/i }));

        expect((await screen.findByRole('alert')).textContent).toContain('please try again');
        expect(screen.getByText('Signed in as')).toBeTruthy();
        expect(await screen.findByRole('button', { name: /sign out/i })).toBeTruthy();
    });

    it('keeps the signed-in view when refreshing the CSRF cookie fails over the network', async () => {
        let logoutCalls = 0;
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const url = String(input);
            if (url === '/sanctum/csrf-cookie') {
                if (logoutCalls > 0) throw new TypeError('Network unavailable');
                return new Response(null, { status: 204 });
            }
            if (url === '/api/v1/me') return jsonResponse(200, { data: account });
            if (url === '/api/v1/auth/logout') { logoutCalls += 1; return jsonResponse(419, { message: 'Page Expired' }); }
            return jsonResponse(200, { data: [] });
        });
        await openSignedInApp(fetchMock);

        fireEvent.click(screen.getByRole('button', { name: /sign out/i }));

        expect((await screen.findByRole('alert')).textContent).toContain('please try again');
        expect(fetchMock.mock.calls.filter(([url]) => String(url) === '/api/v1/auth/logout')).toHaveLength(1);
        expect(screen.getByText('Signed in as')).toBeTruthy();
    });

    it('keeps the signed-in view and shows retry guidance for other logout failures', async () => {
        const fetchMock = createFetchMock(() => jsonResponse(500, { error: { code: 'server_error', message: 'Unexpected error.' } }));
        await openSignedInApp(fetchMock);

        fireEvent.click(screen.getByRole('button', { name: /sign out/i }));

        expect((await screen.findByRole('alert')).textContent).toContain('please try again');
        expect(screen.getByText('Signed in as')).toBeTruthy();
        await waitFor(() => expect(screen.getByRole('button', { name: /sign out/i })).toBeTruthy());
    });
});
