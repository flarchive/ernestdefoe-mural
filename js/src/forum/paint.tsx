/*
 * Everything a member needs once they open the mural: the canvas engine,
 * the pages, the timelapse player and the moderation tools. One chunk,
 * fetched on first use; forum.js stays small.
 */

declare const flarum: any;

/** Core page parts, loaded through the registry before a page draws (they are lazy in Flarum 2). */
export let PageStructure: any;
export let IndexSidebar: any;

export async function loadCore(): Promise<void> {
  if (PageStructure) return;
  const [structure, sidebar] = await Promise.all([
    flarum.reg.asyncModuleImport('flarum/forum/components/PageStructure'),
    flarum.reg.asyncModuleImport('flarum/forum/components/IndexSidebar'),
  ]);
  PageStructure = structure.default || structure;
  IndexSidebar = sidebar.default || sidebar;
}

export { default as MuralPage } from './components/MuralPage';
export { default as GalleryPage } from './components/GalleryPage';
export { default as SeasonPage } from './components/SeasonPage';
