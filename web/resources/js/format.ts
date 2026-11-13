export function formatFlavorName(flavor: string): string {
    return ({ retail: 'Retail', classic_era: 'Classic Era', classic: 'Classic Progression' } as Record<string, string>)[flavor] ?? flavor.replaceAll('_', ' ');
}

export function fallbackRecipeName(spellID: number): string {
    return `Recipe #${spellID}`;
}

export function fallbackItemName(itemID: number): string {
    return `Item #${itemID}`;
}
