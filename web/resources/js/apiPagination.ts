export type CursorList<T> = T[] & { nextCursor?: string | null };

export function withCursor<T>(items: T[], nextCursor?: string | null): CursorList<T> {
    Object.defineProperty(items, 'nextCursor', { value: nextCursor ?? null, configurable: true, enumerable: true, writable: true });
    return items as CursorList<T>;
}

export function appendCursorPage<T>(current: CursorList<T>, page: CursorList<T>): CursorList<T> {
    return withCursor([...current, ...page], page.nextCursor);
}

export function cursorUrl(path: string, cursor: string): string {
    return `${path}${path.includes('?') ? '&' : '?'}cursor=${encodeURIComponent(cursor)}`;
}
