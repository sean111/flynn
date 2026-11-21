// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { CharactersPage } from './app';

const acceptedResponse = () => new Response(JSON.stringify({ data: { dumpId: 'dump-example-1', status: 'queued' } }), { status: 202, headers: { 'Content-Type': 'application/json' } });
const queuedDump = { data: { dumpId: 'dump-example-1', status: 'queued', error: null, charactersSeen: 0, charactersUpdated: 0, results: [] } };

function renderPage(fetchMock: ReturnType<typeof vi.fn>) {
    vi.stubGlobal('fetch', fetchMock);
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: 0 } } });
    return render(<QueryClientProvider client={client}><CharactersPage /></QueryClientProvider>);
}

function backendMock(options: { post?: () => Promise<Response> | Response; dump?: () => Response; onCharacters?: () => void } = {}) {
    return vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = String(input);
        if (url.includes('/sanctum/csrf-cookie')) return new Response(null, { status: 204 });
        if (url.endsWith('/characters')) {
            options.onCharacters?.();
            return new Response(JSON.stringify({ data: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } });
        }
        if (url.endsWith('/guilds')) return new Response(JSON.stringify({ data: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } });
        if (url.endsWith('/dumps') && init?.method === 'POST') return options.post ? await options.post() : acceptedResponse();
        if (url.endsWith('/dumps/dump-example-1')) return options.dump?.() ?? new Response(JSON.stringify(queuedDump), { status: 200, headers: { 'Content-Type': 'application/json' } });
        throw new Error(`Unexpected request: ${url}`);
    });
}

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('CharactersPage manual imports', () => {
    it('sends file uploads as multipart file and resets the input for same-file retries', async () => {
        const fetchMock = backendMock();
        renderPage(fetchMock);
        const input = screen.getByLabelText(/choose Flynn.lua/i) as HTMLInputElement;
        const file = new File(['FlynnDB = {}'], 'Flynn.lua', { type: 'text/plain' });

        fireEvent.change(input, { target: { files: [file] } });
        await screen.findByText(/Upload received/);
        expect(input.value).toBe('');

        fireEvent.change(input, { target: { files: [file] } });
        await waitFor(() => expect(fetchMock.mock.calls.filter(([url, init]) => String(url).endsWith('/dumps') && init?.method === 'POST')).toHaveLength(2));
        for (const [, init] of fetchMock.mock.calls.filter(([url, request]) => String(url).endsWith('/dumps') && request?.method === 'POST')) {
            expect(init?.body).toBeInstanceOf(FormData);
            expect((init?.body as FormData).get('file')).toBeInstanceOf(File);
        }
    });

    it('posts a pasted FLYNN1 string as an exportString JSON request', async () => {
        const fetchMock = backendMock();
        renderPage(fetchMock);
        const textarea = screen.getByPlaceholderText(/Paste a FLYNN1/i);
        fireEvent.change(textarea, { target: { value: '  FLYNN1:abc=  ' } });
        fireEvent.click(screen.getByRole('button', { name: /Import pasted data/i }));

        await screen.findByText(/Upload received/);
        const [, request] = fetchMock.mock.calls.find(([url, init]) => String(url).endsWith('/dumps') && init?.method === 'POST') ?? [];
        expect(JSON.parse(request?.body as string)).toEqual({ format: 'exportString', payload: 'FLYNN1:abc=' });
        expect((textarea as HTMLTextAreaElement).value).toBe('');
    });

    it('routes pasted FlynnDB Lua with leading comments through multipart Flynn.lua', async () => {
        const fetchMock = backendMock();
        renderPage(fetchMock);
        const textarea = screen.getByPlaceholderText(/Paste a FLYNN1/i);
        const lua = '-- file header\n--[=[ block comment ]=]\nFlynnDB -- assignment follows\n = {}';
        fireEvent.change(textarea, { target: { value: lua } });
        fireEvent.click(screen.getByRole('button', { name: /Import pasted data/i }));

        await screen.findByText(/Upload received/);
        const [, request] = fetchMock.mock.calls.find(([url, init]) => String(url).endsWith('/dumps') && init?.method === 'POST') ?? [];
        const form = request?.body as FormData;
        expect(form).toBeInstanceOf(FormData);
        expect((form.get('file') as File).name).toBe('Flynn.lua');
        expect(await (form.get('file') as File).text()).toBe(lua);
    });

    it('shows backend POST errors verbatim and preserves pasted content on failure', async () => {
        const fetchMock = backendMock({
            post: () => new Response(JSON.stringify({ error: { code: 'invalid_saved_variables', message: 'The pasted Flynn.lua is malformed; export it again.' } }), { status: 422, headers: { 'Content-Type': 'application/json' } }),
        });
        renderPage(fetchMock);
        const textarea = screen.getByPlaceholderText(/Paste a FLYNN1/i);
        fireEvent.change(textarea, { target: { value: 'FlynnDB = {' } });
        fireEvent.click(screen.getByRole('button', { name: /Import pasted data/i }));

        expect(await screen.findByText('The pasted Flynn.lua is malformed; export it again.')).toBeTruthy();
        expect((textarea as HTMLTextAreaElement).value).toBe('FlynnDB = {');
    });

    it('shows terminal failed dump error text and its dump ID', async () => {
        const fetchMock = backendMock({
            dump: () => new Response(JSON.stringify({ data: { ...queuedDump.data, status: 'failed', error: { code: 'invalid_payload', message: 'This dump contains an invalid profession record.' } } }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
        });
        renderPage(fetchMock);
        fireEvent.change(screen.getByPlaceholderText(/Paste a FLYNN1/i), { target: { value: 'FLYNN1:abc=' } });
        fireEvent.click(screen.getByRole('button', { name: /Import pasted data/i }));

        expect(await screen.findByText(/This dump contains an invalid profession record\. \(Dump ID: dump-example-1\)/)).toBeTruthy();
        expect(screen.getByText('Dump ID: dump-example-1')).toBeTruthy();
    });

    it('surfaces status polling errors to the user', async () => {
        const fetchMock = backendMock({
            dump: () => new Response(JSON.stringify({ error: { code: 'status_unavailable', message: 'Status service temporarily unavailable.' } }), { status: 503, headers: { 'Content-Type': 'application/json' } }),
        });
        renderPage(fetchMock);
        fireEvent.change(screen.getByPlaceholderText(/Paste a FLYNN1/i), { target: { value: 'FLYNN1:abc=' } });
        fireEvent.click(screen.getByRole('button', { name: /Import pasted data/i }));

        expect(await screen.findByText('Status service temporarily unavailable.')).toBeTruthy();
    });

    it('replaces queued notice with completion summary and refreshes the character list', async () => {
        let characterReads = 0;
        const fetchMock = backendMock({
            onCharacters: () => { characterReads++; },
            dump: () => new Response(JSON.stringify({ data: { ...queuedDump.data, status: 'completed', charactersSeen: 1, charactersUpdated: 1 } }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
        });
        renderPage(fetchMock);
        fireEvent.change(screen.getByPlaceholderText(/Paste a FLYNN1/i), { target: { value: 'FLYNN1:abc=' } });
        fireEvent.click(screen.getByRole('button', { name: /Import pasted data/i }));

        expect(await screen.findByText('Import complete. 1 character snapshot(s) updated.')).toBeTruthy();
        expect(screen.queryByText(/Upload received/)).toBeNull();
        await waitFor(() => expect(characterReads).toBeGreaterThan(1));
    });

    it('prevents duplicate submissions while pending and keeps text after a rejected request', async () => {
        let resolvePost!: (response: Response) => void;
        const post = () => new Promise<Response>((resolve) => { resolvePost = resolve; });
        const fetchMock = backendMock({ post });
        renderPage(fetchMock);
        const textarea = screen.getByPlaceholderText(/Paste a FLYNN1/i);
        fireEvent.change(textarea, { target: { value: 'FLYNN2:unsupported' } });
        const button = screen.getByRole('button', { name: /Import pasted data/i }) as HTMLButtonElement;
        fireEvent.click(button);
        expect((await screen.findByRole('button', { name: /Importing/i }) as HTMLButtonElement).disabled).toBe(true);
        fireEvent.click(button);
        expect(fetchMock.mock.calls.filter(([url, init]) => String(url).endsWith('/dumps') && init?.method === 'POST')).toHaveLength(1);

        resolvePost(new Response(JSON.stringify({ error: { code: 'invalid_export_string', message: 'Export must start with FLYNN1:.' } }), { status: 422, headers: { 'Content-Type': 'application/json' } }));
        expect(await screen.findByText('Export must start with FLYNN1:.')).toBeTruthy();
        expect((textarea as HTMLTextAreaElement).value).toBe('FLYNN2:unsupported');
    });
});
