'use strict';

document.addEventListener( 'DOMContentLoaded', function () {
	let activeDialog = null;

	function focusableElements( container ) {
		return Array.from(
			container.querySelectorAll(
				'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
			)
		).filter(
			( element ) =>
				element.getClientRects().length &&
				'hidden' !== window.getComputedStyle( element ).visibility
		);
	}

	function closeDialog( restoreFocus = true ) {
		if ( ! activeDialog ) {
			return;
		}

		const { dialog, trigger } = activeDialog;
		dialog.style.display = 'none';
		dialog.setAttribute( 'aria-hidden', 'true' );
		trigger.setAttribute( 'aria-expanded', 'false' );
		document.body.classList.remove( 'directorist-account-modal-open' );
		activeDialog = null;

		if ( restoreFocus && document.contains( trigger ) ) {
			trigger.focus( { preventScroll: true } );
		}
	}

	function openDialog( dialog, trigger ) {
		if ( activeDialog ) {
			closeDialog( false );
		}

		activeDialog = { dialog, trigger };
		dialog.style.display = 'block';
		dialog.setAttribute( 'aria-hidden', 'false' );
		trigger.setAttribute( 'aria-expanded', 'true' );
		document.body.classList.add( 'directorist-account-modal-open' );
		window.requestAnimationFrame( () => {
			focusableElements( dialog )[ 0 ]?.focus( { preventScroll: true } );
		} );
	}

	document
		.querySelectorAll( '.directorist-account-block-logout-mode' )
		.forEach( ( block ) => {
			const trigger = block.querySelector(
				'.directorist-account-block__trigger'
			);
			const dialog = document.getElementById(
				trigger?.getAttribute( 'aria-controls' )
			);
			const closeButton = dialog?.querySelector(
				'.directorist-account-block-close'
			);

			if ( ! trigger || ! dialog || ! closeButton ) {
				return;
			}

			const dialogContainer = dialog.closest(
				'.directorist-account-block-authentication-modal'
			);
			if ( dialogContainer?.parentElement !== document.body ) {
				document.body.appendChild( dialogContainer );
			}

			trigger.addEventListener( 'click', () =>
				openDialog( dialog, trigger )
			);
			closeButton.addEventListener( 'click', () => closeDialog() );
			dialog.addEventListener( 'click', ( event ) => {
				if ( event.target === dialog ) {
					closeDialog();
				}
			} );
		} );

	document
		.querySelectorAll( '.directorist-account-block-logged-mode' )
		.forEach( ( block ) => {
			const trigger = block.querySelector(
				'.directorist-account-block__trigger[aria-controls]'
			);
			const navigation = block.querySelector(
				'.directorist-account-block-logged-mode__navigation'
			);

			if ( ! trigger || ! navigation ) {
				return;
			}

			let shade = block.querySelector(
				'.directorist-account-block-logged-mode__overlay'
			);

			if ( ! shade ) {
				shade = document.createElement( 'div' );
				shade.className =
					'directorist-account-block-logged-mode__overlay';
			}
			document.body.appendChild( shade );

			let positionFrame = null;
			const alignmentClass =
				'directorist-account-block-logged-mode__navigation--align-end';

			function positionNavigation() {
				if ( ! navigation.classList.contains( 'show' ) ) {
					return;
				}

				const viewport = window.visualViewport;
				const width = viewport?.width || document.documentElement.clientWidth;
				const left = ( viewport?.offsetLeft || 0 ) + 8;
				const right = left + width - 16;
				navigation.style.setProperty(
					'--directorist-account-menu-max-width',
					`${ Math.max( 0, width - 16 ) }px`
				);
				navigation.style.setProperty( '--directorist-account-menu-offset', '0px' );
				navigation.classList.remove( alignmentClass );

				let rectangle = navigation.getBoundingClientRect();
				if ( rectangle.left < left || rectangle.right > right ) {
					// Try the other trigger edge first, in both LTR and RTL.
					navigation.classList.add( alignmentClass );
					rectangle = navigation.getBoundingClientRect();
				}

				const targetLeft = Math.max(
					left,
					Math.min( rectangle.left, right - rectangle.width )
				);
				navigation.style.setProperty(
					'--directorist-account-menu-offset',
					`${ targetLeft - rectangle.left }px`
				);
			}

			function schedulePosition() {
				if ( positionFrame !== null ) {
					return;
				}
				positionFrame = window.requestAnimationFrame( () => {
					positionFrame = null;
					positionNavigation();
				} );
			}

			window.addEventListener( 'resize', schedulePosition );
			window.visualViewport?.addEventListener( 'resize', schedulePosition );
			window.visualViewport?.addEventListener( 'scroll', schedulePosition );
			if ( window.ResizeObserver ) {
				const observer = new window.ResizeObserver( schedulePosition );
				observer.observe( block );
				observer.observe( navigation );
			}

			function closeNavigation() {
				navigation.classList.remove( 'show' );
				navigation.setAttribute( 'aria-hidden', 'true' );
				shade.classList.remove( 'show' );
				trigger.setAttribute( 'aria-expanded', 'false' );
			}

			trigger.addEventListener( 'click', () => {
				const willOpen = ! navigation.classList.contains( 'show' );
				navigation.classList.toggle( 'show', willOpen );
				navigation.setAttribute( 'aria-hidden', String( ! willOpen ) );
				shade.classList.toggle( 'show', willOpen );
				trigger.setAttribute( 'aria-expanded', String( willOpen ) );

				if ( willOpen ) {
					positionNavigation();
				}
			} );
			shade.addEventListener( 'click', closeNavigation );
			document.addEventListener( 'keydown', ( event ) => {
				if (
					'Escape' === event.key &&
					navigation.classList.contains( 'show' )
				) {
					closeNavigation();
					trigger.focus();
				}
			} );
		} );

	document.addEventListener( 'keydown', ( event ) => {
		if ( ! activeDialog ) {
			return;
		}

		if ( 'Escape' === event.key ) {
			event.preventDefault();
			closeDialog();
			return;
		}

		if ( 'Tab' !== event.key ) {
			return;
		}

		const focusable = focusableElements( activeDialog.dialog );
		const first = focusable[ 0 ];
		const last = focusable[ focusable.length - 1 ];

		if (
			event.shiftKey &&
			activeDialog.dialog.ownerDocument.activeElement === first
		) {
			event.preventDefault();
			last?.focus();
		} else if (
			! event.shiftKey &&
			activeDialog.dialog.ownerDocument.activeElement === last
		) {
			event.preventDefault();
			first?.focus();
		}
	} );
} );
