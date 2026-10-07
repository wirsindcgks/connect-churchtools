import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, SelectControl, RangeControl, CheckboxControl, Notice } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { __, sprintf } from '@wordpress/i18n';
import metadata from './block.json';

// Localized by PostListBlock::localizeGroups() - die Gruppen, aus denen gerade
// Beiträge gespeichert sind.
const knownGroups = window.ctpBlockPostGroups || [];

const parseIds = (value) =>
	String(value || '')
		.split(',')
		.map((part) => parseInt(part, 10))
		.filter((id) => id > 0);

registerBlockType(metadata.name, {
	edit: ({ attributes, setAttributes }) => {
		const { groups, layout, columns, limit } = attributes;
		const blockProps = useBlockProps();
		const selectedIds = parseIds(groups);
		const groupsById = new Map(knownGroups.map((group) => [group.id, group]));

		const toggleGroup = (id, checked) => {
			const next = checked ? [...selectedIds.filter((existing) => existing !== id), id] : selectedIds.filter((existing) => existing !== id);
			setAttributes({ groups: next.join(',') });
		};

		// Gewählte Gruppen ohne gespeicherte Beiträge bleiben mit Hinweis
		// stehen, statt still aus der Auswahl zu fallen - wie im Block
		// „ChurchTools Gruppen“.
		const missingIds = selectedIds.filter((id) => !groupsById.has(id));

		return (
			<div {...blockProps}>
				<InspectorControls>
					<PanelBody title={__('Auswahl', 'churchtools-plugin')}>
						<p className="components-base-control__help">
							{__(
								'Ohne Haken erscheinen die Beiträge aller öffentlichen Gruppen. Zur Auswahl stehen die Gruppen, aus denen gerade Beiträge vorliegen.',
								'churchtools-plugin'
							)}
						</p>
						{missingIds.length > 0 && (
							<Notice status="warning" isDismissible={false}>
								{sprintf(
									__('Zurzeit ohne Beiträge: %s. Diese Gruppen zeigen erst wieder etwas, wenn ChurchTools öffentliche Beiträge von ihnen liefert.', 'churchtools-plugin'),
									missingIds.map((id) => `#${id}`).join(', ')
								)}
							</Notice>
						)}
						{knownGroups.length === 0 && (
							<p>
								{__(
									'Noch keine Beiträge abgeglichen. Unter „ChurchTools → Beiträge → Synchronisation“ den Abgleich einschalten.',
									'churchtools-plugin'
								)}
							</p>
						)}
						{knownGroups.map((group) => (
							<CheckboxControl
								key={group.id}
								label={group.name}
								checked={selectedIds.includes(group.id)}
								onChange={(checked) => toggleGroup(group.id, checked)}
							/>
						))}
						{missingIds.map((id) => (
							<CheckboxControl
								key={`missing-${id}`}
								label={sprintf(__('#%d (zurzeit ohne Beiträge)', 'churchtools-plugin'), id)}
								checked
								onChange={() => toggleGroup(id, false)}
							/>
						))}
					</PanelBody>
					<PanelBody title={__('Darstellung', 'churchtools-plugin')}>
						<SelectControl
							label={__('Ansicht', 'churchtools-plugin')}
							value={layout}
							options={[
								{ label: __('Raster', 'churchtools-plugin'), value: 'grid' },
								{ label: __('Hervorgehoben', 'churchtools-plugin'), value: 'featured' },
							]}
							help={__('„Hervorgehoben“ zeigt je Beitrag eine große Kachel – gedacht für die neuesten ein, zwei Beiträge.', 'churchtools-plugin')}
							onChange={(value) => setAttributes({ layout: value })}
						/>
						{layout === 'grid' && (
							<RangeControl
								label={__('Spalten', 'churchtools-plugin')}
								help={__(
									'Höchstens so viele, wie in den Inhaltsbereich passen – je Kachel mindestens 240px. Für mehr Spalten den Block auf „Weite Breite“ stellen.',
									'churchtools-plugin'
								)}
								value={columns}
								onChange={(value) => setAttributes({ columns: value })}
								min={2}
								max={6}
							/>
						)}
						<RangeControl
							label={__('Anzahl Beiträge', 'churchtools-plugin')}
							help={__('Die neuesten zuerst. 0 = alle gespeicherten (höchstens 30).', 'churchtools-plugin')}
							value={limit}
							onChange={(value) => setAttributes({ limit: value })}
							min={0}
							max={30}
						/>
					</PanelBody>
				</InspectorControls>
				<ServerSideRender block={metadata.name} attributes={attributes} />
			</div>
		);
	},
	save: () => null,
});
