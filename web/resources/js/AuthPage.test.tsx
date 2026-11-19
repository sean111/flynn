// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { AuthPage } from './app';

function renderAuthPage(mode: 'login' | 'register') {
    return render(<MemoryRouter><AuthPage mode={mode} onAuthenticated={vi.fn()} /></MemoryRouter>);
}

function mockApiResponse(status: number, body: unknown) {
    const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
        if (String(input).includes('/sanctum/csrf-cookie')) {
            return new Response(null, { status: 204 });
        }

        return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
    });
    vi.stubGlobal('fetch', fetchMock);
    return fetchMock;
}

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('registration form validation', () => {
    it('shows accessible client-side errors and does not submit invalid registration data', async () => {
        const fetchMock = mockApiResponse(201, { data: {} });
        renderAuthPage('register');

        fireEvent.click(screen.getByRole('button', { name: /create account/i }));

        expect(await screen.findByText('Enter a display name.')).toBeTruthy();
        expect(screen.getByText('Enter your email address.')).toBeTruthy();
        expect(screen.getByText('Enter a password.')).toBeTruthy();
        expect(screen.getByText('Confirm your password.')).toBeTruthy();
        expect(screen.getByLabelText('Display name').getAttribute('aria-invalid')).toBe('true');
        expect(screen.getByLabelText('Display name').getAttribute('aria-describedby')).toBe('display-name-error');
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('explains registration length, password strength, and confirmation failures', async () => {
        const fetchMock = mockApiResponse(201, { data: {} });
        renderAuthPage('register');
        fireEvent.change(screen.getByLabelText('Display name'), { target: { value: 'A' } });
        fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'aria@example.com' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'abcdefghijkl' } });
        fireEvent.change(screen.getByLabelText('Confirm password'), { target: { value: 'differentone' } });
        fireEvent.click(screen.getByRole('button', { name: /create account/i }));

        expect(await screen.findByText('Display name must be at least 2 characters.')).toBeTruthy();
        expect(screen.getByText('Include at least one uppercase letter.')).toBeTruthy();
        expect(screen.getByText('Passwords do not match.')).toBeTruthy();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('submits malformed email values for backend validation and maps the field error', async () => {
        const fetchMock = mockApiResponse(422, {
            error: {
                code: 'invalid_input',
                message: 'The submitted data is invalid.',
                details: { email: ['Enter a valid email address.'] },
            },
        });
        renderAuthPage('register');
        fireEvent.change(screen.getByLabelText('Display name'), { target: { value: 'Aria' } });
        fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'not-an-email' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'Valid-Password-123' } });
        fireEvent.change(screen.getByLabelText('Confirm password'), { target: { value: 'Valid-Password-123' } });
        fireEvent.click(screen.getByRole('button', { name: /create account/i }));

        expect(await screen.findByText('Enter a valid email address.')).toBeTruthy();
        expect(screen.getByLabelText('Email').getAttribute('aria-invalid')).toBe('true');
        expect(fetchMock).toHaveBeenCalledWith('/api/v1/auth/register', expect.objectContaining({
            method: 'POST',
            body: expect.stringContaining('not-an-email'),
        }));
    });

    it('submits a localhost email address for backend validation', async () => {
        const account = { id: '1', name: 'Dev', email: 'dev@localhost', discord: null };
        const fetchMock = mockApiResponse(201, { data: account });
        renderAuthPage('register');

        const email = screen.getByLabelText('Email');
        expect(email.getAttribute('type')).toBe('email');
        expect(email.getAttribute('autocomplete')).toBe('email');
        fireEvent.change(screen.getByLabelText('Display name'), { target: { value: 'Dev' } });
        fireEvent.change(email, { target: { value: 'dev@localhost' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'Valid-Password-123' } });
        fireEvent.change(screen.getByLabelText('Confirm password'), { target: { value: 'Valid-Password-123' } });
        fireEvent.click(screen.getByRole('button', { name: /create account/i }));

        await waitFor(() => expect(fetchMock).toHaveBeenCalledWith('/api/v1/auth/register', expect.objectContaining({
            method: 'POST',
            body: JSON.stringify({
                name: 'Dev',
                email: 'dev@localhost',
                password: 'Valid-Password-123',
                password_confirmation: 'Valid-Password-123',
            }),
        })));
        expect(screen.queryByText('Enter a valid email address.')).toBeNull();
    });

    it.each([
        ['123456789012', 'Include at least one letter.'],
        ['ABCDEFGHIJKL1', 'Include at least one lowercase letter.'],
        ['abcdefghijkl1', 'Include at least one uppercase letter.'],
        ['Abcdefghijkl', 'Include at least one number.'],
    ])('enforces password letter case and number rules', async (password, message) => {
        const fetchMock = mockApiResponse(201, { data: {} });
        renderAuthPage('register');
        fireEvent.change(screen.getByLabelText('Display name'), { target: { value: 'Aria' } });
        fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'aria@example.com' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: password } });
        fireEvent.change(screen.getByLabelText('Confirm password'), { target: { value: password } });
        fireEvent.click(screen.getByRole('button', { name: /create account/i }));

        expect(await screen.findByText(message)).toBeTruthy();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('maps backend validation details to fields and keeps the form-level error', async () => {
        const fetchMock = mockApiResponse(422, {
            error: {
                code: 'invalid_input',
                message: 'The submitted data is invalid.',
                details: { email: ['This email address is already in use.'] },
            },
        });
        renderAuthPage('register');
        fireEvent.change(screen.getByLabelText('Display name'), { target: { value: 'Aria' } });
        fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'aria@example.com' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'Valid-Password-123' } });
        fireEvent.change(screen.getByLabelText('Confirm password'), { target: { value: 'Valid-Password-123' } });
        fireEvent.click(screen.getByRole('button', { name: /create account/i }));

        expect(await screen.findByText('This email address is already in use.')).toBeTruthy();
        expect(screen.getByRole('status').textContent).toContain('The submitted data is invalid.');
        expect(screen.getByLabelText('Email').getAttribute('aria-invalid')).toBe('true');
        expect(fetchMock).toHaveBeenCalled();
    });

    it('allows short passwords on login and sends them to the backend', async () => {
        const fetchMock = mockApiResponse(401, {
            error: { code: 'invalid_credentials', message: 'The email or password is incorrect.', details: {} },
        });
        renderAuthPage('login');
        fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'aria@example.com' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'x' } });
        fireEvent.click(screen.getByRole('button', { name: /sign in/i }));

        await waitFor(() => expect(fetchMock).toHaveBeenCalledWith('/api/v1/auth/login', expect.anything()));
        expect(screen.queryByText('Use at least 12 characters.')).toBeNull();
        expect(screen.getByRole('status').textContent).toContain('The email or password is incorrect.');
    });

    it('clears server and field errors when switching between registration and login', async () => {
        mockApiResponse(422, {
            error: {
                code: 'invalid_input',
                message: 'The submitted data is invalid.',
                details: { email: ['This email address is already in use.'] },
            },
        });
        const { rerender } = renderAuthPage('register');
        fireEvent.change(screen.getByLabelText('Display name'), { target: { value: 'Aria' } });
        fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'aria@example.com' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'Valid-Password-123' } });
        fireEvent.change(screen.getByLabelText('Confirm password'), { target: { value: 'Valid-Password-123' } });
        fireEvent.click(screen.getByRole('button', { name: /create account/i }));

        expect(await screen.findByText('This email address is already in use.')).toBeTruthy();
        expect(screen.getByRole('status').textContent).toContain('The submitted data is invalid.');

        rerender(<MemoryRouter><AuthPage mode="login" onAuthenticated={vi.fn()} /></MemoryRouter>);

        await waitFor(() => {
            expect(screen.queryByText('This email address is already in use.')).toBeNull();
            expect(screen.queryByRole('status')).toBeNull();
        });
        expect(screen.getByLabelText('Email').getAttribute('aria-invalid')).not.toBe('true');

        rerender(<MemoryRouter><AuthPage mode="register" onAuthenticated={vi.fn()} /></MemoryRouter>);

        await waitFor(() => {
            expect(screen.queryByText('This email address is already in use.')).toBeNull();
            expect(screen.queryByRole('status')).toBeNull();
        });
    });
});
