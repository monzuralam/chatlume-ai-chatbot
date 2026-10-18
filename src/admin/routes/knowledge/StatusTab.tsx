/**
 * Index status: size, rebuild progress, embeddings, and search settings.
 */
import { __, sprintf } from '@wordpress/i18n';
import { CircleAlert, RefreshCw, TriangleAlert } from 'lucide-react';
import { useEffect, useId, useState, type ReactNode } from 'react';
import { Link } from 'react-router';
import { toast } from 'sonner';

import { FormSection } from '../../components/form/fields';
import { ConfirmDialog } from '../../components/ui/alert-dialog';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Select } from '../../components/ui/select';
import { Switch } from '../../components/ui/switch';
import { useReindex } from '../../hooks/useKnowledge';
import { useUpdateSettings } from '../../hooks/useSettings';
import {
	formatBytes,
	progressPercent,
	sourceTypeLabel,
	type KnowledgeSettings,
	type KnowledgeStatus,
	type SourceType,
} from '../../lib/knowledge';
import { cn } from '../../lib/utils';

/**
 * One number with its label.
 *
 * @param props       Props.
 * @param props.label Label.
 * @param props.value Value.
 */
function Stat( { label, value }: { label: string; value: string } ) {
	return (
		<div className="chatlume:rounded-control chatlume:border chatlume:border-border chatlume:p-4">
			<dt className="chatlume:text-xs chatlume:text-fg-muted">
				{ label }
			</dt>
			<dd className="chatlume:mt-1 chatlume:text-xl chatlume:font-semibold chatlume:text-fg">
				{ value }
			</dd>
		</div>
	);
}

/**
 * A warning or note.
 *
 * @param props          Props.
 * @param props.tone     warning or danger.
 * @param props.children Text.
 */
function Notice( {
	tone,
	children,
}: {
	tone: 'warning' | 'danger';
	children: ReactNode;
} ) {
	const Icon = tone === 'danger' ? CircleAlert : TriangleAlert;
	return (
		<div
			className={ cn(
				'chatlume:flex chatlume:gap-3 chatlume:rounded-control chatlume:p-3 chatlume:text-sm',
				tone === 'danger'
					? 'chatlume:bg-danger-soft chatlume:text-danger'
					: 'chatlume:bg-warning-soft chatlume:text-warning'
			) }
		>
			<Icon
				aria-hidden="true"
				className="chatlume:mt-0.5 chatlume:size-4 chatlume:shrink-0"
			/>
			<div className="chatlume:text-fg">{ children }</div>
		</div>
	);
}

/**
 * Index status tab.
 *
 * @param props           Props.
 * @param props.knowledge Saved knowledge settings.
 * @param props.status    Index status.
 */
export function StatusTab( {
	knowledge,
	status,
}: {
	knowledge: KnowledgeSettings;
	status: KnowledgeStatus;
} ) {
	const reindex = useReindex();
	const update = useUpdateSettings();
	const [ confirming, setConfirming ] = useState( false );
	const [ topK, setTopK ] = useState( knowledge.top_k );
	const embeddingsId = useId();
	const topKId = useId();
	const { index, queue, embeddings, size } = status;
	const percent = progressPercent( queue );
	const running = queue.status === 'running';

	useEffect( () => setTopK( knowledge.top_k ), [ knowledge.top_k ] );

	const saveSetting = async ( change: Partial< KnowledgeSettings > ) => {
		try {
			await update.mutateAsync( { knowledge: change } );
			toast.success( __( 'Saved.', 'chatlume-ai-chatbot' ) );
		} catch ( error ) {
			toast.error(
				error instanceof Error
					? error.message
					: __(
							'Settings could not be saved.',
							'chatlume-ai-chatbot'
						)
			);
		}
	};

	const start = () => {
		setConfirming( false );
		reindex.mutate( undefined, {
			onSuccess: () =>
				toast.success(
					__(
						'Re-indexing started. It runs in the background; you can leave this page.',
						'chatlume-ai-chatbot'
					)
				),
			onError: ( error ) => toast.error( error.message ),
		} );
	};

	const embeddedShare =
		index.chunks > 0
			? Math.round( ( index.embedded / index.chunks ) * 100 )
			: 0;

	return (
		<div className="chatlume:flex chatlume:max-w-4xl chatlume:flex-col chatlume:gap-6">
			<FormSection
				title={ __( 'Index', 'chatlume-ai-chatbot' ) }
				description={
					index.updated_at
						? sprintf(
								/* translators: %s: Date and time. */
								__( 'Last updated %s.', 'chatlume-ai-chatbot' ),
								new Date( index.updated_at ).toLocaleString()
							)
						: __( 'Nothing is indexed yet.', 'chatlume-ai-chatbot' )
				}
				actions={
					<Button
						type="button"
						size="sm"
						disabled={ running || reindex.isPending }
						onClick={ () => setConfirming( true ) }
					>
						<RefreshCw
							aria-hidden="true"
							className={ cn(
								running && 'chatlume:animate-spin'
							) }
						/>
						{ __( 'Re-index all', 'chatlume-ai-chatbot' ) }
					</Button>
				}
			>
				<dl className="chatlume:grid chatlume:grid-cols-2 chatlume:gap-3 chatlume:sm:grid-cols-4">
					<Stat
						label={ __( 'Items', 'chatlume-ai-chatbot' ) }
						value={ index.sources.toLocaleString() }
					/>
					<Stat
						label={ __( 'Passages', 'chatlume-ai-chatbot' ) }
						value={ index.chunks.toLocaleString() }
					/>
					<Stat
						label={ __( 'Size', 'chatlume-ai-chatbot' ) }
						value={ formatBytes( index.bytes ) }
					/>
					<Stat
						label={ __( 'With embeddings', 'chatlume-ai-chatbot' ) }
						value={ `${ embeddedShare }%` }
					/>
				</dl>

				<div className="chatlume:flex chatlume:flex-wrap chatlume:gap-2">
					{ (
						Object.entries( index.types ) as Array<
							[ SourceType, number ]
						>
					 ).map( ( [ type, count ] ) => (
						<Badge key={ type }>
							{ sprintf(
								/* translators: 1: Source type, 2: Count. */
								__( '%1$s: %2$s', 'chatlume-ai-chatbot' ),
								sourceTypeLabel( type ),
								count.toLocaleString()
							) }
						</Badge>
					) ) }
				</div>

				{ running && (
					<div className="chatlume:flex chatlume:flex-col chatlume:gap-2">
						<div className="chatlume:flex chatlume:justify-between chatlume:text-sm">
							<span className="chatlume:text-fg">
								{ __(
									'Re-indexing in the background…',
									'chatlume-ai-chatbot'
								) }
							</span>
							<span className="chatlume:text-fg-muted">
								{ sprintf(
									/* translators: 1: Items done, 2: Items in total. */
									__( '%1$s of %2$s', 'chatlume-ai-chatbot' ),
									queue.done.toLocaleString(),
									queue.total.toLocaleString()
								) }
							</span>
						</div>
						<div
							role="progressbar"
							aria-valuemin={ 0 }
							aria-valuemax={ 100 }
							aria-valuenow={ percent }
							aria-label={ __(
								'Re-indexing progress',
								'chatlume-ai-chatbot'
							) }
							className="chatlume:h-2 chatlume:overflow-hidden chatlume:rounded-full chatlume:bg-surface-muted"
						>
							<div
								className="chatlume:h-full chatlume:rounded-full chatlume:bg-accent chatlume:transition-[width]"
								style={ { width: `${ percent }%` } }
							/>
						</div>
					</div>
				) }

				{ size.level !== 'ok' && (
					<Notice
						tone={ size.level === 'high' ? 'danger' : 'warning' }
					>
						{ size.level === 'high'
							? sprintf(
									/* translators: %s: Recommended number of passages. */
									__(
										'Your knowledge base is larger than about %s passages, the size that answers quickly on typical shared hosting. Leave out pages the bot does not need, or move to faster hosting if answers start to feel slow.',
										'chatlume-ai-chatbot'
									),
									size.chunks_limit.toLocaleString()
								)
							: sprintf(
									/* translators: %s: Recommended number of passages. */
									__(
										'Your knowledge base is nearing about %s passages, the size that answers quickly on typical shared hosting.',
										'chatlume-ai-chatbot'
									),
									size.chunks_limit.toLocaleString()
								) }
					</Notice>
				) }
				{ ! status.fulltext && (
					<Notice tone="warning">
						{ __(
							'Your database does not support fast full-text search, so Chatlume uses a slower search method. This works well for smaller sites.',
							'chatlume-ai-chatbot'
						) }
					</Notice>
				) }
			</FormSection>

			<FormSection
				title={ __( 'Search', 'chatlume-ai-chatbot' ) }
				description={ __(
					'How the bot finds the right passages for each question.',
					'chatlume-ai-chatbot'
				) }
			>
				<div className="chatlume:flex chatlume:items-start chatlume:justify-between chatlume:gap-6">
					<div>
						<label
							htmlFor={ embeddingsId }
							className="chatlume:text-sm chatlume:font-medium chatlume:text-fg"
						>
							{ __(
								'Understand meaning, not just keywords',
								'chatlume-ai-chatbot'
							) }
						</label>
						<p
							id={ `${ embeddingsId }-hint` }
							className="chatlume:mt-1 chatlume:max-w-xl chatlume:text-[13px] chatlume:text-fg-muted"
						>
							{ __(
								'Uses embeddings from your embeddings provider, so "When will my parcel arrive?" finds your delivery times. Your content is sent to that provider once, in the background.',
								'chatlume-ai-chatbot'
							) }
						</p>
					</div>
					<Switch
						id={ embeddingsId }
						aria-describedby={ `${ embeddingsId }-hint` }
						checked={ knowledge.use_embeddings }
						disabled={ update.isPending }
						onCheckedChange={ ( checked ) =>
							saveSetting( { use_embeddings: checked } )
						}
					/>
				</div>

				{ knowledge.use_embeddings && ! embeddings.available && (
					<Notice tone="warning">
						{ __(
							'No embeddings provider is set up, so the bot searches by keywords only.',
							'chatlume-ai-chatbot'
						) }{ ' ' }
						<Link
							to="/providers"
							className="chatlume:font-medium chatlume:text-accent chatlume:underline"
						>
							{ __(
								'Choose an embeddings model',
								'chatlume-ai-chatbot'
							) }
						</Link>
					</Notice>
				) }
				{ embeddings.available && (
					<p className="chatlume:text-sm chatlume:text-fg-muted">
						{ embeddings.pending > 0
							? sprintf(
									/* translators: 1: Model name, 2: Passages waiting. */
									__(
										'Model %1$s. %2$s passages are waiting for their embeddings; this runs in the background.',
										'chatlume-ai-chatbot'
									),
									embeddings.model,
									embeddings.pending.toLocaleString()
								)
							: sprintf(
									/* translators: %s: Model name. */
									__(
										'Model %s. Every passage has its embedding.',
										'chatlume-ai-chatbot'
									),
									embeddings.model
								) }
						{ index.stale > 0 &&
							' ' +
								sprintf(
									/* translators: %s: Number of passages. */
									__(
										'%s passages from an earlier model are being replaced.',
										'chatlume-ai-chatbot'
									),
									index.stale.toLocaleString()
								) }
					</p>
				) }
				{ embeddings.status === 'error' && (
					<Notice tone="danger">
						{ sprintf(
							/* translators: 1: Error explanation, 2: Time of the next try. */
							__(
								'Embeddings paused: %1$s Chatlume tries again at %2$s.',
								'chatlume-ai-chatbot'
							),
							embeddings.message,
							embeddings.retry_at
								? new Date(
										embeddings.retry_at
									).toLocaleTimeString()
								: '—'
						) }
					</Notice>
				) }

				<div className="chatlume:flex chatlume:flex-wrap chatlume:items-end chatlume:gap-3">
					<div className="chatlume:flex chatlume:flex-col chatlume:gap-1.5">
						<label
							htmlFor={ topKId }
							className="chatlume:text-sm chatlume:font-medium chatlume:text-fg"
						>
							{ __(
								'Passages per answer',
								'chatlume-ai-chatbot'
							) }
						</label>
						<div className="chatlume:w-28">
							<Select
								id={ topKId }
								aria-describedby={ `${ topKId }-hint` }
								value={ String( topK ) }
								onChange={ ( event ) =>
									setTopK( Number( event.target.value ) )
								}
							>
								{ Array.from(
									{ length: 10 },
									( _, i ) => i + 1
								).map( ( value ) => (
									<option key={ value } value={ value }>
										{ value }
									</option>
								) ) }
							</Select>
						</div>
					</div>
					<Button
						type="button"
						disabled={
							topK === knowledge.top_k || update.isPending
						}
						onClick={ () => saveSetting( { top_k: topK } ) }
					>
						{ __( 'Save', 'chatlume-ai-chatbot' ) }
					</Button>
					<p
						id={ `${ topKId }-hint` }
						className="chatlume:w-full chatlume:text-[13px] chatlume:text-fg-muted"
					>
						{ __(
							'More passages give the AI more to work with, but cost more tokens per answer. 3 to 5 suits most sites.',
							'chatlume-ai-chatbot'
						) }
					</p>
				</div>
			</FormSection>

			<ConfirmDialog
				open={ confirming }
				title={ __( 'Re-index everything?', 'chatlume-ai-chatbot' ) }
				description={ __(
					'Chatlume reads all your chosen content again in the background. The bot keeps answering from the current index meanwhile. Documents and quick answers are not affected.',
					'chatlume-ai-chatbot'
				) }
				confirmLabel={ __( 'Re-index all', 'chatlume-ai-chatbot' ) }
				cancelLabel={ __( 'Cancel', 'chatlume-ai-chatbot' ) }
				onConfirm={ start }
				onCancel={ () => setConfirming( false ) }
			/>
		</div>
	);
}
