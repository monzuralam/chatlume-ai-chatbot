/**
 * Voice input and read-aloud through the browser's own Web Speech API.
 * Nothing is sent to the site: speech recognition runs in the browser (some
 * browsers use their vendor's online service for it), and read-aloud uses
 * the voices installed on the device. Both are hidden where unsupported.
 */

interface RecognitionResult {
	readonly isFinal: boolean;
	readonly 0: { transcript: string };
}

interface RecognitionEvent {
	readonly resultIndex: number;
	readonly results: ArrayLike< RecognitionResult >;
}

interface Recognition {
	lang: string;
	interimResults: boolean;
	continuous: boolean;
	onresult: ( ( event: RecognitionEvent ) => void ) | null;
	onerror: ( ( event: { error: string } ) => void ) | null;
	onend: ( () => void ) | null;
	start: () => void;
	stop: () => void;
	abort: () => void;
}

type RecognitionClass = new () => Recognition;

/**
 * The page language, for recognition and voices.
 *
 * @return Language tag.
 */
export function pageLanguage(): string {
	return (
		document.documentElement.lang ||
		( typeof navigator !== 'undefined' ? navigator.language : '' ) ||
		'en'
	);
}

/**
 * The browser's speech recognition, if any.
 *
 * @return Constructor or null.
 */
function recognitionClass(): RecognitionClass | null {
	const scope = window as unknown as {
		SpeechRecognition?: RecognitionClass;
		webkitSpeechRecognition?: RecognitionClass;
	};
	return scope.SpeechRecognition ?? scope.webkitSpeechRecognition ?? null;
}

/**
 * Whether voice input is available.
 *
 * @return Supported.
 */
export function canListen(): boolean {
	return recognitionClass() !== null;
}

/**
 * Listens once and reports the words as they are recognized.
 *
 * @param handlers         Callbacks.
 * @param handlers.onText  Receives everything heard so far.
 * @param handlers.onEnd   Called when listening stops.
 * @param handlers.onError Receives "blocked" (no microphone permission) or "failed".
 * @return Stops listening.
 */
export function listen( handlers: {
	onText: ( text: string ) => void;
	onEnd: () => void;
	onError: ( kind: 'blocked' | 'failed' ) => void;
} ): () => void {
	const Speech = recognitionClass();
	if ( ! Speech ) {
		handlers.onError( 'failed' );
		handlers.onEnd();
		return () => undefined;
	}

	const recognition = new Speech();
	recognition.lang = pageLanguage();
	recognition.interimResults = true;
	recognition.continuous = false;

	const heard: string[] = [];
	recognition.onresult = ( event ) => {
		for ( let i = event.resultIndex; i < event.results.length; i++ ) {
			heard[ i ] = event.results[ i ][ 0 ].transcript;
		}
		handlers.onText( heard.join( ' ' ).replace( /\s+/g, ' ' ).trim() );
	};
	recognition.onerror = ( event ) => {
		// "no-speech" and "aborted" are normal endings, not failures.
		if (
			event.error === 'not-allowed' ||
			event.error === 'service-not-allowed'
		) {
			handlers.onError( 'blocked' );
		} else if ( event.error !== 'no-speech' && event.error !== 'aborted' ) {
			handlers.onError( 'failed' );
		}
	};
	recognition.onend = () => handlers.onEnd();

	try {
		recognition.start();
	} catch {
		handlers.onError( 'failed' );
		handlers.onEnd();
	}

	return () => {
		try {
			recognition.stop();
		} catch {
			// Already stopped.
		}
	};
}

/**
 * Whether read-aloud is available.
 *
 * @return Supported.
 */
export function canSpeak(): boolean {
	return (
		typeof window !== 'undefined' &&
		'speechSynthesis' in window &&
		'SpeechSynthesisUtterance' in window
	);
}

let speaking: string | null = null;
const listeners = new Set< ( id: string | null ) => void >();

/**
 * Notifies subscribers of the message being read.
 *
 * @param id Message ID, or null.
 */
function setSpeaking( id: string | null ): void {
	speaking = id;
	listeners.forEach( ( listener ) => listener( id ) );
}

/**
 * The message being read aloud.
 *
 * @return Message ID, or null.
 */
export function speakingId(): string | null {
	return speaking;
}

/**
 * Subscribes to read-aloud changes.
 *
 * @param listener Receives the message being read, or null.
 * @return Unsubscribes.
 */
export function onSpeaking(
	listener: ( id: string | null ) => void
): () => void {
	listeners.add( listener );
	return () => listeners.delete( listener );
}

/**
 * Reads a message aloud (stopping any other reading).
 *
 * @param id   Message ID.
 * @param text Plain text.
 */
export function speak( id: string, text: string ): void {
	if ( ! canSpeak() || ! text.trim() ) {
		return;
	}
	window.speechSynthesis.cancel();
	const utterance = new window.SpeechSynthesisUtterance( text );
	utterance.lang = pageLanguage();
	const done = () => {
		if ( speaking === id ) {
			setSpeaking( null );
		}
	};
	utterance.onend = done;
	utterance.onerror = done;
	setSpeaking( id );
	window.speechSynthesis.speak( utterance );
}

/**
 * Stops reading aloud.
 */
export function stopSpeaking(): void {
	if ( canSpeak() ) {
		window.speechSynthesis.cancel();
	}
	setSpeaking( null );
}
