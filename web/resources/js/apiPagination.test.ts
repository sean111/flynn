import { describe, expect, it } from 'vitest';
import { appendCursorPage, cursorUrl, withCursor } from './apiPagination';

describe('cursor pagination metadata', () => {
    it('preserves nextCursor on API list data and through appending pages', () => {
        const first = withCursor([{ id: 1 }], 'cursor / one');
        const second = withCursor([{ id: 2 }], null);
        const combined = appendCursorPage(first, second);

        expect(first.nextCursor).toBe('cursor / one');
        expect(Array.from(combined)).toEqual([{ id: 1 }, { id: 2 }]);
        expect(combined.nextCursor).toBeNull();
    });

    it('builds cursor URLs without losing existing filters', () => {
        expect(cursorUrl('/guilds?query=smith', 'next/+')).toBe('/guilds?query=smith&cursor=next%2F%2B');
    });
});
