import { describe, expect, it } from 'vitest';
import { fallbackItemName, fallbackRecipeName, formatFlavorName } from './format';

describe('catalog fallback labels', () => {
    it('uses human-readable supported game flavor names', () => {
        expect(formatFlavorName('classic_era')).toBe('Classic Era');
        expect(formatFlavorName('retail')).toBe('Retail');
    });

    it('keeps unknown catalog ids identifiable when local data is absent', () => {
        expect(fallbackRecipeName(12870)).toBe('Recipe #12870');
        expect(fallbackItemName(36913)).toBe('Item #36913');
    });
});
