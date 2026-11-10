/** The palette presets, mirrored from src/Palette.php for the admin's preview. */
export const PRESETS: Record<string, string[]> = {
  mural32: [
    '#ffffff',
    '#c9ccd1',
    '#8b8f97',
    '#4b4f58',
    '#1d1f24',
    '#7a1631',
    '#d7263d',
    '#ff6b6b',
    '#ffb3c1',
    '#e0457b',
    '#f46036',
    '#ff9f1c',
    '#ffd23f',
    '#fff3b0',
    '#1b5e20',
    '#2e9e44',
    '#8fd14f',
    '#c8f7c5',
    '#0b7a75',
    '#17bebb',
    '#9be7f0',
    '#14213d',
    '#2c5eda',
    '#4ea5f7',
    '#b8d8ff',
    '#4b2a8f',
    '#7c3aed',
    '#b892ff',
    '#f0c2ff',
    '#5c3a21',
    '#a8693a',
    '#f2c79b',
  ],
  mural16: [
    '#ffffff',
    '#c9ccd1',
    '#8b8f97',
    '#1d1f24',
    '#d7263d',
    '#ff6b6b',
    '#f46036',
    '#ffd23f',
    '#2e9e44',
    '#8fd14f',
    '#0b7a75',
    '#2c5eda',
    '#4ea5f7',
    '#7c3aed',
    '#e0457b',
    '#5c3a21',
  ],
  pastel16: [
    '#fffdf7',
    '#e6e1d8',
    '#a59e94',
    '#4a4540',
    '#ffadad',
    '#ffd6a5',
    '#fdffb6',
    '#caffbf',
    '#9bf6ff',
    '#a0c4ff',
    '#bdb2ff',
    '#ffc6ff',
    '#e07a5f',
    '#81b29a',
    '#3d405b',
    '#f2cc8f',
  ],
};

/** Same rules as Palette::parse: a preset name, or 2–32 hex colours. */
export function parsePalette(value: string | null | undefined): string[] {
  const v = (value || '').trim();
  if (!v || PRESETS[v]) return PRESETS[v || 'mural32'];
  const seen: string[] = [];
  for (const match of v.matchAll(/#?\b([0-9a-f]{6}|[0-9a-f]{3})\b/gi)) {
    let hex = match[1].toLowerCase();
    if (hex.length === 3) hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
    if (!seen.includes('#' + hex)) seen.push('#' + hex);
  }
  return seen.length >= 2 ? seen.slice(0, 32) : PRESETS.mural32;
}
