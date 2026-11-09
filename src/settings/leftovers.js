/**
 * The rules another image optimizer left behind once it was switched off, each
 * with what it still does, its lines, and a Remove asked in the page, or what to
 * remove by hand where the plugin can't write the file. Drawn from what the
 * server found (`Switching\Leftovers::found()`); nothing goes without a press.
 */
import {
	createInterpolateElement,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	Button,
	Callout,
	CodeCopy,
	Confirm,
	Panel,
	PanelTitle,
	useAsk,
} from '../ui';

/**
 * Which leftovers this page lists: on a network the shared files on the
 * network's page (or the main site's, where the plugin isn't on for the whole
 * network), and each site's own on its own page.
 *
 * @param {Object}  state       The page's state.
 * @param {boolean} networkPage Whether this is the network admin's page.
 * @return {Object[]} The leftovers.
 */
export function shownLeftovers( state, networkPage ) {
	return ( state.leftovers ?? [] ).filter( ( leftover ) => {
		if ( networkPage ) {
			return leftover.shared;
		}

		return ! leftover.shared || ! state.network;
	} );
}

/**
 * Where the lines are, set apart in the sentence.
 *
 * @param {string} text The sentence, with `<file />` where the path goes.
 * @param {string} file The path.
 * @return {Element} The sentence.
 */
function withFile( text, file ) {
	return createInterpolateElement( text, {
		file: <code dir="ltr">{ file }</code>,
	} );
}

/**
 * Where a leftover is, in a sentence.
 *
 * @param {Object} leftover The leftover.
 * @return {Element|string} The sentence.
 */
function where( leftover ) {
	if ( 'rules' === leftover.kind ) {
		return __( 'In WordPress’s rewrite rules:', 'iminify-image-optimizer' );
	}

	return withFile(
		__( 'In <file />:', 'iminify-image-optimizer' ),
		leftover.file
	);
}

/**
 * Why the plugin leaves a leftover to be removed by hand, or null when it can
 * remove it.
 *
 * @param {Object}  leftover The leftover.
 * @param {boolean} network  Whether the plugin is on for the whole network.
 * @return {Element|string|null} The sentence.
 */
function byHand( leftover, network ) {
	if ( 'damaged' === leftover.why ) {
		return withFile(
			sprintf(
				/* translators: %s: the first line of another plugin's block, such as "# BEGIN EWWWIO". */
				__(
					'<file /> holds the line %s, but the block it starts has no end of its own, or runs across another block, so the plugin leaves the file alone. Remove the block’s lines by hand.',
					'iminify-image-optimizer'
				),
				leftover.lines
			),
			leftover.file
		);
	}

	if ( 'unwritable' === leftover.why ) {
		return withFile(
			'nginx' === leftover.kind
				? __(
						'The plugin can’t write to <file />. Remove these lines from it by hand, then reload Nginx.',
						'iminify-image-optimizer'
					)
				: __(
						'The plugin can’t write to <file />. Remove these lines from it by hand.',
						'iminify-image-optimizer'
					),
			leftover.file
		);
	}

	if ( ! leftover.can ) {
		return network
			? __(
					'Every site of the network shares these lines, so someone who can manage the network removes them, from the network admin’s Iminify settings.',
					'iminify-image-optimizer'
				)
			: __(
					'Every site of the network shares these lines, so a super admin removes them, on this page.',
					'iminify-image-optimizer'
				);
	}

	return null;
}

/**
 * What the page asks before a leftover goes.
 *
 * @param {Object} leftover The leftover.
 * @return {string} The question.
 */
function question( leftover ) {
	if ( 'rules' === leftover.kind ) {
		return sprintf(
			/* translators: %s: another image optimizer's name. */
			__(
				'Take %s’s rule out of WordPress’s rewrite rules? Every other rule stays.',
				'iminify-image-optimizer'
			),
			leftover.name
		);
	}

	if ( 'nginx' === leftover.kind ) {
		return sprintf(
			/* translators: %s: another image optimizer's name. */
			__(
				'Remove %s’s lines from this file? Everything else in it stays as it is. Nginx keeps them until it is reloaded, so reload it afterwards.',
				'iminify-image-optimizer'
			),
			leftover.name
		);
	}

	return sprintf(
		/* translators: %s: another image optimizer's name. */
		__(
			'Remove %s’s lines from this file? Everything else in it stays as it is.',
			'iminify-image-optimizer'
		),
		leftover.name
	);
}

/**
 * One leftover: who left it and what it does, its lines, and how it goes.
 *
 * @param {Object}                          props          The component's props.
 * @param {Object}                          props.leftover The leftover.
 * @param {boolean}                         props.network  Whether the plugin is on for the whole network.
 * @param {boolean}                         props.busy     Whether a press is on its way.
 * @param {(element: ?HTMLElement) => void} props.button   Keeps its Remove button, for the focus after another goes.
 * @param {() => Promise<boolean>}          props.onRemove Removes it, answering whether it went.
 */
function Leftover( { leftover, network, busy, button, onRemove } ) {
	const { asking, open, close, opener } = useAsk();
	const hand = byHand( leftover, network );

	return (
		<li className="iminify-leftovers__item">
			<h3>
				{ 'rules' === leftover.kind
					? sprintf(
							/* translators: %s: another image optimizer's name. */
							__(
								'%s’s rewrite rule',
								'iminify-image-optimizer'
							),
							leftover.name
						)
					: sprintf(
							/* translators: %s: another image optimizer's name. */
							__( '%s’s rules', 'iminify-image-optimizer' ),
							leftover.name
						) }
			</h3>
			<p className="iminify-text">{ leftover.what }</p>
			<p className="iminify-text">{ where( leftover ) }</p>
			{ 'damaged' !== leftover.why && (
				<CodeCopy command={ leftover.lines } block />
			) }
			{ hand && (
				<Callout tone="info">
					<p>{ hand }</p>
				</Callout>
			) }
			{ ! hand &&
				( asking ? (
					<Confirm
						question={ question( leftover ) }
						yes={ __( 'Remove', 'iminify-image-optimizer' ) }
						danger
						busy={ busy }
						onYes={ () =>
							onRemove().then( ( removed ) => {
								/* Once it went, the panel moves the focus on; a refusal gives it back to Remove. */
								if ( ! removed ) {
									close();
								}
							} )
						}
						onNo={ close }
					/>
				) : (
					<div className="iminify-actions">
						<Button
							ref={ ( element ) => {
								opener.current = element;
								button( element );
							} }
							disabled={ busy }
							onClick={ open }
						>
							{ __( 'Remove', 'iminify-image-optimizer' ) }
						</Button>
					</div>
				) ) }
		</li>
	);
}

/**
 * The panel, or nothing when no other plugin left rules this page lists. Once
 * one goes, the focus moves to the next one's Remove, or, when none is left, to
 * the line saying so, which stays until the page is left.
 *
 * @param {Object}                                 props           The component's props.
 * @param {Object[]}                               props.leftovers The leftovers this page lists.
 * @param {boolean}                                props.network   Whether the plugin is on for the whole network.
 * @param {boolean}                                props.busy      Whether a press is on its way.
 * @param {(leftover: Object) => Promise<boolean>} props.onRemove  Removes one, answering whether it went.
 */
export default function Leftovers( { leftovers, network, busy, onRemove } ) {
	const [ cleared, setCleared ] = useState( false );
	const buttons = useRef( {} );
	const done = useRef( null );
	const focusAt = useRef( null );

	useEffect( () => {
		/* A Remove stays disabled while the page waits, and a disabled button takes no focus. */
		if ( null === focusAt.current || busy ) {
			return;
		}

		const next =
			leftovers[ Math.min( focusAt.current, leftovers.length - 1 ) ];

		focusAt.current = null;
		( next ? buttons.current[ next.id ] : done.current )?.focus();
	}, [ leftovers, busy ] );

	if ( 0 === leftovers.length && ! cleared ) {
		return null;
	}

	const remove = ( leftover, index ) =>
		onRemove( leftover ).then( ( removed ) => {
			if ( removed ) {
				focusAt.current = index;
				setCleared( true );
			}

			return removed;
		} );

	return (
		<Panel className="iminify-leftovers">
			<PanelTitle icon="server">
				{ __(
					'Rules another optimizer left',
					'iminify-image-optimizer'
				) }
			</PanelTitle>
			{ 0 === leftovers.length ? (
				<p className="iminify-text" ref={ done } tabIndex={ -1 }>
					{ __(
						'The rules another optimizer left are gone.',
						'iminify-image-optimizer'
					) }
				</p>
			) : (
				<>
					<p className="iminify-text">
						{ __(
							'An image optimizer you had before left these rules behind when it was switched off. Iminify never removes another plugin’s rules by itself.',
							'iminify-image-optimizer'
						) }
					</p>
					<ul className="iminify-leftovers__list">
						{ leftovers.map( ( leftover, index ) => (
							<Leftover
								key={ leftover.id }
								leftover={ leftover }
								network={ network }
								busy={ busy }
								button={ ( element ) => {
									buttons.current[ leftover.id ] = element;
								} }
								onRemove={ () => remove( leftover, index ) }
							/>
						) ) }
					</ul>
				</>
			) }
		</Panel>
	);
}
