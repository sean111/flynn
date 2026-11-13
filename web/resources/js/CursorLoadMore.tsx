import type { MouseEventHandler } from 'react';

export function CursorLoadMore({ cursor, label, onLoadMore }: { cursor?: string | null; label: string; onLoadMore: MouseEventHandler<HTMLButtonElement> }) {
    if (!cursor) return null;

    return <button type="button" onClick={onLoadMore} className="inline-flex items-center justify-center rounded-xl border border-white/10 bg-white/[.04] px-4 py-2.5 text-sm font-semibold text-stone-200 hover:bg-white/[.08]">
        {label}
    </button>;
}
