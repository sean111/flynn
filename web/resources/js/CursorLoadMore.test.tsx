// @vitest-environment jsdom
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { CursorLoadMore } from './CursorLoadMore';

describe('cursor load-more control', () => {
    it('renders only when another page exists and requests it on click', () => {
        const onLoadMore = vi.fn();
        const { rerender } = render(<CursorLoadMore cursor={null} label="Load more recipes" onLoadMore={onLoadMore} />);
        expect(screen.queryByRole('button', { name: 'Load more recipes' })).toBeNull();

        rerender(<CursorLoadMore cursor="next-page" label="Load more recipes" onLoadMore={onLoadMore} />);
        fireEvent.click(screen.getByRole('button', { name: 'Load more recipes' }));
        expect(onLoadMore).toHaveBeenCalledOnce();
    });
});
