/**
 * Documents: drag-and-drop uploads with their reading status, and the list
 * of documents the bot learns from.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	CircleAlert,
	CircleCheck,
	FileText,
	LoaderCircle,
	Trash2,
	Upload,
} from 'lucide-react';
import { useEffect, useId, useRef, useState, type DragEvent } from 'react';
import { toast } from 'sonner';

import { FormSection } from '../../components/form/fields';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { ConfirmDialog } from '../../components/ui/alert-dialog';
import { Input } from '../../components/ui/input';
import { Select } from '../../components/ui/select';
import { Skeleton } from '../../components/ui/skeleton';
import {
	useDeleteDocument,
	useDocuments,
	useUploadDocument,
} from '../../hooks/useKnowledge';
import { useUpdateSettings } from '../../hooks/useSettings';
import {
	formatBytes,
	uploadProblem,
	type KnowledgeDocument,
	type KnowledgeSettings,
	type KnowledgeStatus,
} from '../../lib/knowledge';
import { cn } from '../../lib/utils';

interface UploadItem {
	key: string;
	name: string;
	state: 'waiting' | 'reading' | 'done' | 'error';
	message: string;
}

/**
 * Upload size limit, saved on its own.
 *
 * @param props           Props.
 * @param props.knowledge Saved knowledge settings.
 * @param props.server    The server's own limit in bytes.
 */
function SizeLimit( {
	knowledge,
	server,
}: {
	knowledge: KnowledgeSettings;
	server: number;
} ) {
	const id = useId();
	const update = useUpdateSettings();
	const [ value, setValue ] = useState( String( knowledge.max_upload_mb ) );
	const number = Number( value );
	const valid = Number.isInteger( number ) && number >= 1 && number <= 50;

	useEffect(
		() => setValue( String( knowledge.max_upload_mb ) ),
		[ knowledge.max_upload_mb ]
	);

	const save = async () => {
		try {
			await update.mutateAsync( {
				knowledge: { max_upload_mb: number },
			} );
			toast.success( __( 'Upload limit saved.', 'chatlume-ai-chatbot' ) );
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

	return (
		<div className="chatlume:flex chatlume:flex-wrap chatlume:items-end chatlume:gap-3">
			<div className="chatlume:flex chatlume:flex-col chatlume:gap-1.5">
				<label
					htmlFor={ id }
					className="chatlume:text-sm chatlume:font-medium chatlume:text-fg"
				>
					{ __( 'Largest file (MB)', 'chatlume-ai-chatbot' ) }
				</label>
				<Input
					id={ id }
					type="number"
					min={ 1 }
					max={ 50 }
					className="chatlume:w-28"
					aria-describedby={ `${ id }-hint` }
					aria-invalid={ ! valid }
					value={ value }
					onChange={ ( event ) => setValue( event.target.value ) }
				/>
			</div>
			<Button
				type="button"
				disabled={
					! valid ||
					number === knowledge.max_upload_mb ||
					update.isPending
				}
				onClick={ save }
			>
				{ __( 'Save limit', 'chatlume-ai-chatbot' ) }
			</Button>
			<p
				id={ `${ id }-hint` }
				className="chatlume:w-full chatlume:text-[13px] chatlume:text-fg-muted"
			>
				{ sprintf(
					/* translators: %s: The server's upload limit, for example "64 MB". */
					__(
						'From 1 to 50 MB. Your server accepts uploads up to %s, so a higher setting has no effect.',
						'chatlume-ai-chatbot'
					),
					formatBytes( server )
				) }
			</p>
		</div>
	);
}

/**
 * Documents tab.
 *
 * @param props           Props.
 * @param props.knowledge Saved knowledge settings.
 * @param props.status    Index status (for the languages in use).
 */
export function DocumentsTab( {
	knowledge,
	status,
}: {
	knowledge: KnowledgeSettings;
	status: KnowledgeStatus;
} ) {
	const documents = useDocuments();
	const uploadMutation = useUploadDocument();
	const remove = useDeleteDocument();
	const [ items, setItems ] = useState< UploadItem[] >( [] );
	const [ dragging, setDragging ] = useState( false );
	const [ language, setLanguage ] = useState( 'auto' );
	const [ deleting, setDeleting ] = useState< KnowledgeDocument | null >(
		null
	);
	const inputRef = useRef< HTMLInputElement >( null );
	const busy = useRef( false );
	const queue = useRef< Array< { key: string; file: File } > >( [] );
	const languageId = useId();
	const dropHintId = useId();

	const info = documents.data;
	const languages = Object.keys( status.index.languages ).filter(
		( code ) => code !== ''
	);

	const patch = ( key: string, change: Partial< UploadItem > ) =>
		setItems( ( current ) =>
			current.map( ( item ) =>
				item.key === key ? { ...item, ...change } : item
			)
		);

	// Files are read one at a time, so large uploads never compete.
	const next = async () => {
		if ( busy.current ) {
			return;
		}
		busy.current = true;
		while ( queue.current.length > 0 ) {
			const job = queue.current.shift() as { key: string; file: File };
			patch( job.key, { state: 'reading' } );
			try {
				const document = await uploadMutation.mutateAsync( {
					file: job.file,
					language,
				} );
				patch( job.key, {
					state: 'done',
					message: sprintf(
						/* translators: %d: Number of passages. */
						_n(
							'Learned %d passage.',
							'Learned %d passages.',
							document.chunks,
							'chatlume-ai-chatbot'
						),
						document.chunks
					),
				} );
			} catch ( error ) {
				patch( job.key, {
					state: 'error',
					message:
						error instanceof Error
							? error.message
							: __(
									'The file could not be uploaded.',
									'chatlume-ai-chatbot'
								),
				} );
			}
		}
		busy.current = false;
	};

	const add = ( files: FileList | File[] ) => {
		if ( ! info ) {
			return;
		}
		const added: UploadItem[] = [];
		for ( const file of Array.from( files ) ) {
			const key = `${ file.name }-${ file.size }-${ Date.now() }-${ Math.random() }`;
			const problem = uploadProblem( file, info );
			added.push( {
				key,
				name: file.name,
				state: problem ? 'error' : 'waiting',
				message: problem,
			} );
			if ( ! problem ) {
				queue.current.push( { key, file } );
			}
		}
		setItems( ( current ) => [ ...added, ...current ].slice( 0, 30 ) );
		next();
	};

	const onDrop = ( event: DragEvent< HTMLDivElement > ) => {
		event.preventDefault();
		setDragging( false );
		if ( event.dataTransfer.files.length > 0 ) {
			add( event.dataTransfer.files );
		}
	};

	const confirmDelete = () => {
		if ( ! deleting ) {
			return;
		}
		const target = deleting;
		setDeleting( null );
		remove.mutate( target.id, {
			onSuccess: () =>
				toast.success(
					sprintf(
						/* translators: %s: File name. */
						__(
							'“%s” deleted. The bot no longer uses it.',
							'chatlume-ai-chatbot'
						),
						target.name
					)
				),
			onError: ( error ) => toast.error( error.message ),
		} );
	};

	return (
		<div className="chatlume:flex chatlume:max-w-4xl chatlume:flex-col chatlume:gap-6">
			<FormSection
				title={ __( 'Upload documents', 'chatlume-ai-chatbot' ) }
				description={ __(
					'PDF, Word (DOCX), text, Markdown, CSV, and JSON files. Only the text is kept; the files themselves are never stored on your site.',
					'chatlume-ai-chatbot'
				) }
			>
				{ /* The whole area is a drop target; the button opens the file picker. */ }
				<div
					onDragOver={ ( event ) => {
						event.preventDefault();
						setDragging( true );
					} }
					onDragLeave={ () => setDragging( false ) }
					onDrop={ onDrop }
					className={ cn(
						'chatlume:flex chatlume:flex-col chatlume:items-center chatlume:gap-3 chatlume:rounded-card chatlume:border-2 chatlume:border-dashed chatlume:px-6 chatlume:py-10 chatlume:text-center chatlume:transition-colors',
						dragging
							? 'chatlume:border-accent chatlume:bg-accent-soft'
							: 'chatlume:border-border'
					) }
				>
					<Upload
						aria-hidden="true"
						className="chatlume:size-8 chatlume:text-fg-muted"
					/>
					<p
						id={ dropHintId }
						className="chatlume:text-sm chatlume:text-fg"
					>
						{ __(
							'Drop files here, or choose them from your computer.',
							'chatlume-ai-chatbot'
						) }
					</p>
					<input
						ref={ inputRef }
						type="file"
						multiple
						className="chatlume:sr-only"
						tabIndex={ -1 }
						aria-hidden="true"
						accept={ ( info?.types ?? [] )
							.map( ( type ) => `.${ type }` )
							.concat( '.markdown' )
							.join( ',' ) }
						onChange={ ( event ) => {
							if ( event.target.files ) {
								add( event.target.files );
							}
							event.target.value = '';
						} }
					/>
					<Button
						type="button"
						variant="primary"
						disabled={ ! info }
						aria-describedby={ dropHintId }
						onClick={ () => inputRef.current?.click() }
					>
						{ __( 'Choose files', 'chatlume-ai-chatbot' ) }
					</Button>
					{ info && (
						<p className="chatlume:text-xs chatlume:text-fg-muted">
							{ sprintf(
								/* translators: %s: Maximum file size, for example "10 MB". */
								__(
									'Up to %s per file.',
									'chatlume-ai-chatbot'
								),
								formatBytes( info.max_bytes )
							) }
						</p>
					) }
				</div>

				<div className="chatlume:flex chatlume:flex-col chatlume:gap-1.5 chatlume:sm:w-72">
					<label
						htmlFor={ languageId }
						className="chatlume:text-sm chatlume:font-medium chatlume:text-fg"
					>
						{ __(
							'Language of new uploads',
							'chatlume-ai-chatbot'
						) }
					</label>
					<Select
						id={ languageId }
						value={ language }
						onChange={ ( event ) =>
							setLanguage( event.target.value )
						}
					>
						<option value="auto">
							{ __( 'Automatic', 'chatlume-ai-chatbot' ) }
						</option>
						<option value="">
							{ __( 'All languages', 'chatlume-ai-chatbot' ) }
						</option>
						{ languages.map( ( code ) => (
							<option key={ code } value={ code }>
								{ code.toUpperCase() }
							</option>
						) ) }
					</Select>
				</div>

				{ items.length > 0 && (
					<ul
						className="chatlume:flex chatlume:flex-col chatlume:gap-2"
						aria-live="polite"
						aria-label={ __( 'Uploads', 'chatlume-ai-chatbot' ) }
					>
						{ items.map( ( item ) => (
							<li
								key={ item.key }
								className="chatlume:flex chatlume:items-start chatlume:gap-3 chatlume:rounded-control chatlume:border chatlume:border-border chatlume:px-3 chatlume:py-2 chatlume:text-sm"
							>
								{ item.state === 'done' && (
									<CircleCheck
										aria-hidden="true"
										className="chatlume:mt-0.5 chatlume:size-4 chatlume:shrink-0 chatlume:text-success"
									/>
								) }
								{ item.state === 'error' && (
									<CircleAlert
										aria-hidden="true"
										className="chatlume:mt-0.5 chatlume:size-4 chatlume:shrink-0 chatlume:text-danger"
									/>
								) }
								{ ( item.state === 'reading' ||
									item.state === 'waiting' ) && (
									<LoaderCircle
										aria-hidden="true"
										className={ cn(
											'chatlume:mt-0.5 chatlume:size-4 chatlume:shrink-0 chatlume:text-fg-muted',
											item.state === 'reading' &&
												'chatlume:animate-spin'
										) }
									/>
								) }
								<div className="chatlume:min-w-0">
									<p className="chatlume:truncate chatlume:font-medium chatlume:text-fg">
										{ item.name }
									</p>
									<p
										className={ cn(
											'chatlume:text-xs',
											item.state === 'error'
												? 'chatlume:text-danger'
												: 'chatlume:text-fg-muted'
										) }
									>
										{ item.state === 'waiting' &&
											__(
												'Waiting…',
												'chatlume-ai-chatbot'
											) }
										{ item.state === 'reading' &&
											__(
												'Reading the text…',
												'chatlume-ai-chatbot'
											) }
										{ ( item.state === 'done' ||
											item.state === 'error' ) &&
											item.message }
									</p>
								</div>
							</li>
						) ) }
					</ul>
				) }
			</FormSection>

			<FormSection
				title={ __( 'Your documents', 'chatlume-ai-chatbot' ) }
				description={ __(
					'To update a document, upload the new version and delete the old one.',
					'chatlume-ai-chatbot'
				) }
			>
				{ documents.isLoading && (
					<div className="chatlume:flex chatlume:flex-col chatlume:gap-2">
						<Skeleton className="chatlume:h-12" />
						<Skeleton className="chatlume:h-12" />
					</div>
				) }
				{ documents.isError && (
					<p className="chatlume:text-sm chatlume:text-danger">
						{ documents.error.message }
					</p>
				) }
				{ info && info.items.length === 0 && (
					<p className="chatlume:py-6 chatlume:text-center chatlume:text-sm chatlume:text-fg-muted">
						{ __(
							'No documents yet. Price lists, manuals, FAQs, and policies are good places to start.',
							'chatlume-ai-chatbot'
						) }
					</p>
				) }
				{ info && info.items.length > 0 && (
					<ul className="chatlume:divide-y chatlume:divide-border chatlume:rounded-control chatlume:border chatlume:border-border">
						{ info.items.map( ( document ) => (
							<li
								key={ document.id }
								className="chatlume:flex chatlume:items-center chatlume:gap-3 chatlume:px-4 chatlume:py-3"
							>
								<FileText
									aria-hidden="true"
									className="chatlume:size-5 chatlume:shrink-0 chatlume:text-fg-muted"
								/>
								<div className="chatlume:min-w-0 chatlume:flex-1">
									<p className="chatlume:truncate chatlume:text-sm chatlume:font-medium chatlume:text-fg">
										{ document.name }
									</p>
									<p className="chatlume:mt-0.5 chatlume:flex chatlume:flex-wrap chatlume:items-center chatlume:gap-2 chatlume:text-xs chatlume:text-fg-muted">
										<Badge>
											{ document.type.toUpperCase() }
										</Badge>
										<span>
											{ formatBytes( document.size ) }
										</span>
										<span>
											{ sprintf(
												/* translators: %d: Number of passages. */
												_n(
													'%d passage',
													'%d passages',
													document.chunks,
													'chatlume-ai-chatbot'
												),
												document.chunks
											) }
										</span>
										<span>
											{ document.language
												? document.language.toUpperCase()
												: __(
														'All languages',
														'chatlume-ai-chatbot'
													) }
										</span>
										<span>
											{ new Date(
												document.uploaded_at
											).toLocaleDateString() }
										</span>
									</p>
								</div>
								<Button
									type="button"
									size="icon"
									variant="ghost"
									aria-label={ sprintf(
										/* translators: %s: File name. */
										__(
											'Delete %s',
											'chatlume-ai-chatbot'
										),
										document.name
									) }
									disabled={ remove.isPending }
									onClick={ () => setDeleting( document ) }
								>
									<Trash2 aria-hidden="true" />
								</Button>
							</li>
						) ) }
					</ul>
				) }
				{ info && (
					<SizeLimit
						knowledge={ knowledge }
						server={ info.server_bytes }
					/>
				) }
			</FormSection>

			<ConfirmDialog
				open={ deleting !== null }
				title={ __( 'Delete this document?', 'chatlume-ai-chatbot' ) }
				description={ sprintf(
					/* translators: %s: File name. */
					__(
						'The bot will stop using what it learned from “%s”. This cannot be undone.',
						'chatlume-ai-chatbot'
					),
					deleting?.name ?? ''
				) }
				confirmLabel={ __( 'Delete', 'chatlume-ai-chatbot' ) }
				cancelLabel={ __( 'Cancel', 'chatlume-ai-chatbot' ) }
				tone="danger"
				onConfirm={ confirmDelete }
				onCancel={ () => setDeleting( null ) }
			/>
		</div>
	);
}
