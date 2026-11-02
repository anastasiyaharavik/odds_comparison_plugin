/**
 * Registers the odds-comparison/odds-table block in the editor.
 *
 * Dynamic block: markup is rendered server-side by render.php, so save() returns null.
 */
import { registerBlockType } from '@wordpress/blocks';

import Edit from './edit';
import metadata from './block.json';
import './style.scss';

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );
