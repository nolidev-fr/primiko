/**
 * Visitor form: one step at a time, display conditions, photos, review and
 * submission through the REST API. No framework, no build step.
 *
 * The display conditions follow the same rules as includes/class-conditions.php,
 * which evaluates them again on the server.
 *
 * @package
 */
( function () {
	'use strict';

	const OPERATORS = [ 'equals', 'in', 'not_in', 'filled' ];

	/**
	 * Replaces %s, %d and %1$s style placeholders.
	 *
	 * @param {string} text Text with placeholders.
	 * @return {string} Text with the values.
	 */
	function format( text ) {
		const args = Array.prototype.slice.call( arguments, 1 );
		let next = 0;

		return text.replace(
			/%(?:(\d+)\$)?[sd]/g,
			function ( match, position ) {
				const value = position ? args[ position - 1 ] : args[ next++ ];

				return undefined === value ? match : String( value );
			}
		);
	}

	/**
	 * Lists the non-empty strings an answer holds.
	 *
	 * @param {*} answer String, list of strings or address.
	 * @return {string[]} Values.
	 */
	function values( answer ) {
		const list = [];

		if ( null === answer || undefined === answer ) {
			return list;
		}

		if ( 'object' !== typeof answer ) {
			answer = [ answer ];
		}

		Object.keys( answer ).forEach( function ( key ) {
			const value = answer[ key ];

			if (
				( 'string' === typeof value || 'number' === typeof value ) &&
				'' !== String( value ).trim()
			) {
				list.push( String( value ) );
			}
		} );

		return list;
	}

	/**
	 * Tells whether a display condition is met.
	 *
	 * @param {Object|null} condition Condition.
	 * @param {Object}      answers   Answers of the visible fields so far.
	 * @return {boolean} Whether it is met.
	 */
	function matches( condition, answers ) {
		if ( ! condition || 'object' !== typeof condition ) {
			return true;
		}

		if ( condition.all ) {
			return condition.all.every( function ( inner ) {
				return matches( inner, answers );
			} );
		}

		if ( condition.any ) {
			return condition.any.some( function ( inner ) {
				return matches( inner, answers );
			} );
		}

		const list = values( answers[ condition.field ] );
		const found = OPERATORS.filter( function ( operator ) {
			return Object.prototype.hasOwnProperty.call( condition, operator );
		} )[ 0 ];

		switch ( found ) {
			case 'equals':
				return -1 !== list.indexOf( String( condition.equals ) );
			case 'in':
				return condition.in.some( function ( value ) {
					return -1 !== list.indexOf( String( value ) );
				} );
			case 'not_in':
				return ! condition.not_in.some( function ( value ) {
					return -1 !== list.indexOf( String( value ) );
				} );
			case 'filled':
				return Boolean( condition.filled ) === list.length > 0;
		}

		return true;
	}

	/**
	 * Resizes a photo in the browser, so that phone photos of several
	 * megabytes go through the upload limits of the host.
	 *
	 * @param {File}   file File chosen by the visitor.
	 * @param {number} max  Largest side, in pixels.
	 * @return {Promise<Blob|null>} JPEG image, or null when unreadable.
	 */
	function resize( file, max ) {
		return new Promise( function ( resolve ) {
			const url = URL.createObjectURL( file );
			const image = new window.Image();

			image.onload = function () {
				const scale = Math.min(
					1,
					max / Math.max( image.naturalWidth, image.naturalHeight )
				);

				URL.revokeObjectURL( url );

				if (
					1 === scale &&
					'image/jpeg' === file.type &&
					file.size < 1500000
				) {
					resolve( file );
					return;
				}

				const canvas = document.createElement( 'canvas' );
				canvas.width = Math.round( image.naturalWidth * scale );
				canvas.height = Math.round( image.naturalHeight * scale );
				canvas
					.getContext( '2d' )
					.drawImage( image, 0, 0, canvas.width, canvas.height );
				canvas.toBlob(
					function ( blob ) {
						resolve( blob );
					},
					'image/jpeg',
					0.82
				);
			};

			image.onerror = function () {
				URL.revokeObjectURL( url );
				resolve( null );
			};

			image.src = url;
		} );
	}

	/**
	 * One form on the page.
	 *
	 * @param {HTMLElement} root Wrapper with the settings.
	 */
	function QuoteForm( root ) {
		this.root = root;
		this.config = JSON.parse( root.getAttribute( 'data-primiko' ) );
		this.i18n = this.config.i18n;
		this.form = root.querySelector( '.primiko__form' );
		this.alert = root.querySelector( '.primiko__alert' );
		this.done = root.querySelector( '.primiko__done' );
		this.progressBar = root.querySelector( '.primiko__bar span' );
		this.live = root.querySelector( '[data-live]' );
		this.buttons = {
			prev: root.querySelector( '[data-action="prev"]' ),
			next: root.querySelector( '[data-action="next"]' ),
			submit: root.querySelector( '[data-action="submit"]' ),
		};
		this.photos = {};
		this.pending = 0;
		this.token = null;
		this.current = this.config.steps[ 0 ].id;

		this.start();
	}

	QuoteForm.prototype = {
		/**
		 * Shows the form and wires the events.
		 */
		start() {
			const self = this;

			this.form.hidden = false;
			this.root.classList.add( 'is-ready' );
			this.matchTheme();
			this.matchAnchorSpace();

			this.buttons.prev.addEventListener( 'click', function () {
				self.move( -1 );
			} );
			this.buttons.next.addEventListener( 'click', function () {
				self.next();
			} );
			this.form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();

				if ( self.isLast() ) {
					self.send();
				} else {
					self.next();
				}
			} );
			this.form.addEventListener( 'pointerdown', function () {
				self.pointerAt = Date.now();
			} );
			this.form.addEventListener( 'change', function ( event ) {
				self.changed( event.target );
				self.autoAdvance( event.target );
			} );
			this.form.addEventListener( 'input', function ( event ) {
				self.changed( event.target );
			} );

			this.root
				.querySelectorAll( '.primiko__photos' )
				.forEach( function ( picker ) {
					self.setupPhotos( picker );
				} );

			this.refresh();
			this.show( this.current, false );
			this.fetchToken();
		},

		/**
		 * Fits the form to the theme, from what the theme already shows.
		 *
		 * The main color is, in this order: the color set in the block or the
		 * theme CSS, the background of the theme buttons, the palette color
		 * the stylesheet found, the link color of the page, and the text
		 * color. The first that stands out on the page background (3:1) is
		 * kept: a theme accent is sometimes a pale decoration color.
		 *
		 * The secondary buttons get the shape and lettering of the theme
		 * button, and the answer cards the corners of the theme inputs.
		 */
		matchTheme() {
			const style = window.getComputedStyle( this.root );
			const background = this.pageBackground();
			const explicit = style
				.getPropertyValue( '--primiko-accent' )
				.trim();
			const preset = style
				.getPropertyValue( '--primiko-x-accent' )
				.trim();
			const button = window.getComputedStyle( this.buttons.next );
			const candidates = explicit
				? [ explicit ]
				: [
						button.backgroundColor,
						'#1f5f99' === preset.toLowerCase() ? '' : preset,
						this.linkColor(),
						style.color,
				  ];

			const accent = candidates.filter( Boolean ).find( ( color ) => {
				const rgb = this.toRgb( color );

				return (
					rgb && ( explicit || this.contrast( rgb, background ) >= 3 )
				);
			} );

			if ( accent ) {
				this.root.style.setProperty( '--primiko-x-accent', accent );
				this.root.style.setProperty(
					'--primiko-on-accent',
					this.luminance( this.toRgb( accent ) ) > 0.4
						? '#111'
						: '#fff'
				);
			}

			[
				[ 'radius', button.borderTopLeftRadius ],
				[ 'weight', button.fontWeight ],
				[ 'family', button.fontFamily ],
				[ 'size', button.fontSize ],
				[ 'transform', button.textTransform ],
				[ 'spacing', button.letterSpacing ],
			].forEach( ( [ name, value ] ) => {
				if ( value ) {
					this.root.style.setProperty(
						'--primiko-x-button-' + name,
						value
					);
				}
			} );

			const input = this.root.querySelector(
				'input.primiko__input, textarea.primiko__input'
			);

			if ( ! input ) {
				return;
			}

			const field = window.getComputedStyle( input );

			if ( ! style.getPropertyValue( '--primiko-radius' ).trim() ) {
				this.root.style.setProperty(
					'--primiko-x-radius',
					field.borderTopLeftRadius
				);
			}

			// Answer cards and the consent box take the background of the
			// theme fields, so they stand out on a colored section as the
			// fields do.
			if ( this.toRgb( field.backgroundColor ) ) {
				this.root.style.setProperty(
					'--primiko-x-field-background',
					field.backgroundColor
				);
			}
		},

		/**
		 * Drops the space kept above the form when a link leads to it, if the
		 * theme already keeps anchors below its header (scroll-padding-top):
		 * the two would add up. A page opened on the anchor of the form is
		 * then placed again.
		 */
		matchAnchorSpace() {
			const padding = parseFloat(
				window.getComputedStyle( document.documentElement )
					.scrollPaddingTop
			);
			const targets = [ this.root, this.root.parentElement ];

			if ( ! ( padding > 0 ) ) {
				return;
			}

			targets.forEach( function ( element ) {
				element.style.scrollMarginTop = '0px';
			} );

			const hash = window.location.hash.slice( 1 );

			if (
				hash &&
				targets.some( function ( element ) {
					return element.id === decodeURIComponent( hash );
				} )
			) {
				document
					.getElementById( decodeURIComponent( hash ) )
					.scrollIntoView();
			}
		},

		/**
		 * Color of a link placed where the form is, as the theme draws it.
		 *
		 * @return {string} CSS color.
		 */
		linkColor() {
			const probe = document.createElement( 'a' );

			probe.href = '#';
			probe.style.position = 'absolute';
			probe.style.visibility = 'hidden';
			this.root.parentNode.insertBefore( probe, this.root );

			const color = window.getComputedStyle( probe ).color;

			probe.remove();

			return color;
		},

		/**
		 * Background the form is drawn on: the first ancestor with a color.
		 *
		 * @return {number[]} Red, green and blue from 0 to 255.
		 */
		pageBackground() {
			for (
				let node = this.root;
				node && 1 === node.nodeType;
				node = node.parentNode
			) {
				const rgba = this.toRgb(
					window.getComputedStyle( node ).backgroundColor,
					true
				);

				if ( rgba && rgba[ 3 ] > 200 ) {
					return rgba.slice( 0, 3 );
				}
			}

			return [ 255, 255, 255 ];
		},

		/**
		 * Contrast ratio between two colors, from 1 to 21.
		 *
		 * @param {number[]} first  Red, green and blue.
		 * @param {number[]} second Red, green and blue.
		 * @return {number} Contrast ratio.
		 */
		contrast( first, second ) {
			const a = this.luminance( first );
			const b = this.luminance( second );

			return ( Math.max( a, b ) + 0.05 ) / ( Math.min( a, b ) + 0.05 );
		},

		/**
		 * Reads any CSS color through the browser.
		 *
		 * @param {string}  color     CSS color.
		 * @param {boolean} withAlpha Whether to return the opacity too.
		 * @return {number[]|null} Red, green, blue (and opacity) from 0 to 255,
		 *                         or null for an empty or transparent color.
		 */
		toRgb( color, withAlpha ) {
			const canvas = document
				.createElement( 'canvas' )
				.getContext( '2d', { willReadFrequently: true } );

			if ( ! canvas || ! color ) {
				return null;
			}

			canvas.clearRect( 0, 0, 1, 1 );
			canvas.fillStyle = 'rgba(0, 0, 0, 0)';
			canvas.fillStyle = color;
			canvas.fillRect( 0, 0, 1, 1 );

			const data = Array.prototype.slice.call(
				canvas.getImageData( 0, 0, 1, 1 ).data
			);

			if ( withAlpha ) {
				return data;
			}

			return data[ 3 ] > 200 ? data.slice( 0, 3 ) : null;
		},

		/**
		 * Relative luminance of a color, from 0 (black) to 1 (white).
		 *
		 * @param {number[]} rgb Red, green and blue from 0 to 255.
		 * @return {number} Luminance.
		 */
		luminance( rgb ) {
			const linear = rgb.map( function ( value ) {
				const channel = value / 255;

				return channel <= 0.03928
					? channel / 12.92
					: Math.pow( ( channel + 0.055 ) / 1.055, 2.4 );
			} );

			return (
				0.2126 * linear[ 0 ] +
				0.7152 * linear[ 1 ] +
				0.0722 * linear[ 2 ]
			);
		},

		/**
		 * Asks the server for a signed token. The page may come from a cache,
		 * so the token cannot be printed in it.
		 *
		 * @return {Promise<string|null>} Token.
		 */
		fetchToken() {
			const self = this;
			const url = new URL( this.config.tokenUrl, window.location.href );

			url.searchParams.set( 'form', this.config.form );

			return fetch( url.toString(), {
				credentials: 'omit',
				cache: 'no-store',
			} )
				.then( function ( response ) {
					return response.ok ? response.json() : null;
				} )
				.then( function ( data ) {
					self.token = data && data.token ? data.token : null;
					self.tokenAt = Date.now();
					return self.token;
				} )
				.catch( function () {
					return null;
				} );
		},

		/**
		 * Waits until the token is old enough for the server, which refuses a
		 * form sent within seconds of its display (robots do that). A quick
		 * visitor on a short form waits a moment instead of getting an error.
		 *
		 * @return {Promise} Resolved once the minimum time has passed.
		 */
		afterMinDelay() {
			const wait =
				( this.config.minFill || 0 ) * 1000 +
				500 -
				( Date.now() - ( this.tokenAt || 0 ) );

			return new Promise( function ( resolve ) {
				window.setTimeout( resolve, Math.max( 0, wait ) );
			} );
		},

		/**
		 * Element of a field.
		 *
		 * @param {string} id Field identifier.
		 * @return {HTMLElement|null} Field wrapper.
		 */
		fieldElement( id ) {
			return this.form.querySelector( '[data-field="' + id + '"]' );
		},

		/**
		 * Element of a step.
		 *
		 * @param {string} id Step identifier.
		 * @return {HTMLElement|null} Step fieldset.
		 */
		stepElement( id ) {
			return this.form.querySelector( '[data-step="' + id + '"]' );
		},

		/**
		 * Reads the answer of a field from the page.
		 *
		 * @param {string} id Field identifier.
		 * @return {*} Answer.
		 */
		read( id ) {
			const field = this.config.fields[ id ];
			const element = this.fieldElement( id );
			let checked;
			let address;

			switch ( field.type ) {
				case 'choice':
				case 'yesno':
					checked = Array.prototype.map.call(
						element.querySelectorAll( 'input:checked' ),
						function ( input ) {
							return input.value;
						}
					);
					return field.multiple ? checked : checked[ 0 ] || '';

				case 'address':
					address = {};
					element
						.querySelectorAll( '[data-part]' )
						.forEach( function ( input ) {
							address[ input.getAttribute( 'data-part' ) ] =
								input.value.trim();
						} );
					return address;

				case 'consent':
					return element.querySelector( 'input' ).checked ? '1' : '';

				case 'photos':
					return ( this.photos[ id ] || [] ).length
						? String( this.photos[ id ].length )
						: '';

				default:
					return element
						.querySelector( 'input, textarea, select' )
						.value.trim();
			}
		},

		/**
		 * Works out which steps and fields are shown, in form order. A hidden
		 * field counts as empty for the conditions that follow, and a step
		 * without any visible field is skipped.
		 *
		 * @return {{steps: string[], fields: string[], answers: Object}} Visible items.
		 */
		visible() {
			const self = this;
			const result = { steps: [], fields: [], answers: {} };

			this.config.steps.forEach( function ( step ) {
				let shown = 0;

				if ( ! matches( step.showIf, result.answers ) ) {
					return;
				}

				Object.keys( self.config.fields ).forEach( function ( id ) {
					const field = self.config.fields[ id ];

					if (
						field.step !== step.id ||
						! matches( field.showIf, result.answers )
					) {
						return;
					}

					result.fields.push( id );
					result.answers[ id ] = self.read( id );
					shown++;
				} );

				// A step whose questions are all hidden is skipped.
				if ( shown ) {
					result.steps.push( step.id );
				}
			} );

			return result;
		},

		/**
		 * Applies the conditions to the page and updates the progress.
		 */
		refresh() {
			const self = this;

			this.state = this.visible();

			Object.keys( this.config.fields ).forEach( function ( id ) {
				self.fieldElement( id ).hidden =
					-1 === self.state.fields.indexOf( id );
			} );

			this.progress();
		},

		/**
		 * Reacts to a change in the form.
		 *
		 * @param {HTMLElement} target Element that changed.
		 */
		changed( target ) {
			const wrapper = target.closest( '[data-field]' );
			const phone = this.fieldElement( 'phone' );

			this.refresh();

			// "Phone or email": an email address also fixes the phone field.
			if (
				this.config.either &&
				wrapper &&
				phone &&
				'email' === wrapper.getAttribute( 'data-field' ) &&
				phone.classList.contains( 'has-error' ) &&
				values( this.read( 'email' ) ).length &&
				! this.check( 'email' )
			) {
				this.clearError( 'phone' );
			}

			if (
				wrapper &&
				wrapper.classList.contains( 'has-error' ) &&
				! this.check( wrapper.getAttribute( 'data-field' ) )
			) {
				this.clearError( wrapper.getAttribute( 'data-field' ) );

				// Once every field of the step is fixed, the warning goes too.
				if (
					this.alert.textContent === this.i18n.checkFields &&
					! this.stepElement( this.current ).querySelector(
						'.has-error'
					)
				) {
					this.hideAlert();
				}
			}
		},

		/**
		 * Goes to the next step once a step made only of single choices is
		 * answered.
		 *
		 * Only after a click or a tap: with the keyboard, the arrow keys
		 * change the checked radio at each press, and moving on then would
		 * stop the visitor from going through the answers. The short delay
		 * lets the visitor see the answer before the step changes.
		 *
		 * @param {HTMLElement} target Element that changed.
		 */
		autoAdvance( target ) {
			const self = this;
			const step = this.current;

			window.clearTimeout( this.advanceTimer );

			if (
				'radio' !== target.type ||
				Date.now() - ( this.pointerAt || 0 ) > 1000 ||
				! this.singleChoicesDone()
			) {
				return;
			}

			this.advanceTimer = window.setTimeout( function () {
				if ( step === self.current && self.singleChoicesDone() ) {
					self.next();
				}
			}, 350 );
		},

		/**
		 * Tells whether the current step only holds single choice questions,
		 * all answered, and is not the last step.
		 *
		 * @return {boolean} Whether the step can move on by itself.
		 */
		singleChoicesDone() {
			const self = this;
			const fields = this.state.fields.filter( function ( id ) {
				return self.config.fields[ id ].step === self.current;
			} );

			return (
				! this.isLast() &&
				fields.length > 0 &&
				fields.every( function ( id ) {
					const field = self.config.fields[ id ];
					const single =
						'yesno' === field.type ||
						( 'choice' === field.type && ! field.multiple );

					return single && values( self.read( id ) ).length > 0;
				} )
			);
		},

		/**
		 * Updates the progress bar and tells screen readers which step is
		 * shown.
		 *
		 * No step count is shown: the number of steps changes with the
		 * answers, and "Step 1 of 7" makes a two minute form look long. The
		 * review is not a step to fill in, so the bar is full when it opens.
		 */
		progress() {
			const self = this;

			// A single screen form has neither a bar nor steps to announce.
			if ( this.config.single || ! this.progressBar ) {
				return;
			}

			const steps = this.state.steps;
			const position = steps.indexOf( this.current ) + 1;
			const filled = steps.filter( function ( id ) {
				return ! self.stepConfig( id ).review;
			} );
			const reached = this.stepConfig( this.current ).review
				? filled.length
				: filled.indexOf( this.current ) + 1;
			const title = this.stepElement( this.current ).querySelector(
				'.primiko__step-title'
			).textContent;

			const announce = format(
				this.i18n.progress,
				position,
				steps.length,
				title
			);

			// Only a new step is announced, not every change in the form.
			if ( this.live.textContent !== announce ) {
				this.live.textContent = announce;
			}

			this.progressBar.style.width =
				( filled.length ? ( reached / filled.length ) * 100 : 100 ) +
				'%';
		},

		/**
		 * Settings of a step.
		 *
		 * @param {string} id Step identifier.
		 * @return {Object} Step settings.
		 */
		stepConfig( id ) {
			return (
				this.config.steps.find( function ( step ) {
					return step.id === id;
				} ) || {}
			);
		},

		/**
		 * Tells whether the current step is the last one.
		 *
		 * @return {boolean} Whether it is the last step.
		 */
		isLast() {
			const steps = this.state.steps;

			return steps[ steps.length - 1 ] === this.current;
		},

		/**
		 * Shows a step.
		 *
		 * @param {string}  id    Step identifier.
		 * @param {boolean} focus Whether to move the focus to its title.
		 */
		show( id, focus ) {
			const self = this;
			let top;

			this.current = id;
			this.config.steps.forEach( function ( item ) {
				self.stepElement( item.id ).hidden = item.id !== id;
			} );

			this.refresh();

			this.buttons.prev.hidden = this.state.steps[ 0 ] === id;
			this.buttons.next.hidden = this.isLast();
			this.buttons.submit.hidden = ! this.isLast();
			this.hideAlert();

			const step = this.stepElement( id );

			if ( step.querySelector( '[data-summary]' ) ) {
				this.summary( step.querySelector( '[data-summary]' ) );
			}

			if ( step.querySelector( '[data-captcha]' ) ) {
				this.renderCaptcha( step.querySelector( '[data-captcha]' ), 0 );
			}

			if ( focus ) {
				step.querySelector( '.primiko__step-title' ).focus( {
					preventScroll: true,
				} );
				top = this.root.getBoundingClientRect().top;

				if ( top < 0 || top > window.innerHeight / 2 ) {
					this.root.scrollIntoView( {
						behavior: 'smooth',
						block: 'start',
					} );
				}
			}
		},

		/**
		 * Goes to the previous or next visible step.
		 *
		 * @param {number} offset -1 or 1.
		 */
		move( offset ) {
			const steps = this.state.steps;
			const target = steps[ steps.indexOf( this.current ) + offset ];

			if ( target ) {
				this.show( target, true );
			}
		},

		/**
		 * Checks the current step, then goes to the next one.
		 */
		next() {
			if ( this.validate( this.current ) ) {
				this.move( 1 );
			}
		},

		/**
		 * Checks one field.
		 *
		 * @param {string} id Field identifier.
		 * @return {string} Error message, empty when valid.
		 */
		check( id ) {
			const field = this.config.fields[ id ];
			const value = this.read( id );
			const empty = 0 === values( value ).length;
			let number;

			if ( 'address' === field.type ) {
				if ( field.required && values( value ).length < 3 ) {
					return this.i18n.address;
				}
				return '';
			}

			if ( empty ) {
				if ( ! field.required ) {
					return '';
				}
				if ( 'consent' === field.type ) {
					return this.i18n.consent;
				}
				if ( 'photos' === field.type ) {
					return this.i18n.photosNeeded;
				}
				return -1 !==
					[ 'choice', 'select', 'yesno' ].indexOf( field.type )
					? this.i18n.requiredOne
					: this.i18n.required;
			}

			if (
				'email' === field.type &&
				! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( value )
			) {
				return this.i18n.email;
			}

			if (
				'tel' === field.type &&
				( ! /^[0-9+().\s-]{6,30}$/.test( value ) ||
					value.replace( /\D/g, '' ).length < 6 )
			) {
				return this.i18n.phone;
			}

			if ( 'number' === field.type ) {
				number = Number(
					value.replace( ',', '.' ).replace( /\s/g, '' )
				);

				if ( isNaN( number ) ) {
					return this.i18n.number;
				}
				if (
					( undefined !== field.min && number < field.min ) ||
					( undefined !== field.max && number > field.max )
				) {
					return format(
						this.i18n.range,
						undefined !== field.min ? field.min : '-',
						undefined !== field.max ? field.max : '-'
					);
				}
			}

			return '';
		},

		/**
		 * Checks the visible fields of a step and shows the errors.
		 *
		 * @param {string} stepId Step identifier.
		 * @return {boolean} Whether the step is valid.
		 */
		validate( stepId ) {
			const self = this;
			const errors = {};

			this.refresh();

			this.state.fields.forEach( function ( id ) {
				if ( self.config.fields[ id ].step !== stepId ) {
					return;
				}

				const message = self.check( id );

				if ( message ) {
					errors[ id ] = message;
				} else {
					self.clearError( id );
				}
			} );

			// "Phone or email": one of the two is needed to answer.
			if (
				this.config.either &&
				-1 !== this.state.fields.indexOf( 'phone' ) &&
				stepId === this.config.fields.phone.step &&
				! errors.phone &&
				! errors.email &&
				! values( this.read( 'phone' ) ).length &&
				! values( this.read( 'email' ) ).length
			) {
				errors.phone = this.i18n.contactEither;
			}

			return this.showErrors( errors );
		},

		/**
		 * Marks fields as invalid and moves the focus to the first one.
		 *
		 * @param {Object} errors Messages keyed by field identifier.
		 * @return {boolean} Whether there was no error.
		 */
		showErrors( errors ) {
			const self = this;
			const ids = Object.keys( errors );

			ids.forEach( function ( id ) {
				self.setError( id, errors[ id ] );
			} );

			if ( ! ids.length ) {
				this.hideAlert();
				return true;
			}

			this.showAlert( this.i18n.checkFields );
			const first = this.fieldElement( ids[ 0 ] ).querySelector(
				'input:not([type="hidden"]), textarea, select'
			);

			if ( first ) {
				first.focus();
			}

			return false;
		},

		/**
		 * Shows the error message of a field.
		 *
		 * @param {string} id      Field identifier.
		 * @param {string} message Error message.
		 */
		setError( id, message ) {
			const element = this.fieldElement( id );
			const error = element.querySelector( '.primiko__error' );

			error.textContent = message;
			error.hidden = false;
			element.classList.add( 'has-error' );

			element
				.querySelectorAll( 'input, textarea, select' )
				.forEach( function ( input ) {
					const described = (
						input.getAttribute( 'aria-describedby' ) || ''
					)
						.split( ' ' )
						.filter( Boolean );

					input.setAttribute( 'aria-invalid', 'true' );

					if ( -1 === described.indexOf( error.id ) ) {
						described.push( error.id );
						input.setAttribute(
							'aria-describedby',
							described.join( ' ' )
						);
					}
				} );
		},

		/**
		 * Removes the error message of a field.
		 *
		 * @param {string} id Field identifier.
		 */
		clearError( id ) {
			const element = this.fieldElement( id );
			const error = element.querySelector( '.primiko__error' );

			error.textContent = '';
			error.hidden = true;
			element.classList.remove( 'has-error' );

			element
				.querySelectorAll( '[aria-invalid]' )
				.forEach( function ( input ) {
					const described = (
						input.getAttribute( 'aria-describedby' ) || ''
					)
						.split( ' ' )
						.filter( function ( item ) {
							return item && item !== error.id;
						} );

					input.removeAttribute( 'aria-invalid' );

					if ( described.length ) {
						input.setAttribute(
							'aria-describedby',
							described.join( ' ' )
						);
					} else {
						input.removeAttribute( 'aria-describedby' );
					}
				} );
		},

		/**
		 * Shows the message at the top of the form.
		 *
		 * @param {string} message Message.
		 */
		showAlert( message ) {
			this.alert.textContent = message;
			this.alert.hidden = false;
		},

		/**
		 * Hides the message at the top of the form.
		 */
		hideAlert() {
			this.alert.textContent = '';
			this.alert.hidden = true;
		},

		/**
		 * Returns an answer as text, for the review.
		 *
		 * @param {string} id Field identifier.
		 * @return {string} Answer as text.
		 */
		display( id ) {
			const field = this.config.fields[ id ];
			const value = this.read( id );
			const i18n = this.i18n;

			switch ( field.type ) {
				case 'choice':
				case 'select':
					return ( Array.isArray( value ) ? value : [ value ] )
						.filter( Boolean )
						.map( function ( one ) {
							return field.options[ one ] || one;
						} )
						.join( ', ' );
				case 'yesno':
					return { yes: i18n.yes, no: i18n.no }[ value ] || '';
				case 'address':
					return [
						value.street,
						[ value.postcode, value.city ]
							.filter( Boolean )
							.join( ' ' ),
					]
						.filter( Boolean )
						.join( ', ' );
				case 'photos':
					return value
						? format( i18n.photosSummary[ value ], value )
						: '';
				case 'number':
					return value && field.unit
						? value + ' ' + field.unit
						: value;
				default:
					return value;
			}
		},

		/**
		 * Builds the review of the answers, step by step, with a link back to
		 * each step.
		 *
		 * @param {HTMLElement} container Review container.
		 */
		summary( container ) {
			const self = this;

			container.textContent = '';

			this.config.steps.forEach( function ( step ) {
				if (
					step.review ||
					-1 === self.state.steps.indexOf( step.id )
				) {
					return;
				}

				const fields = self.state.fields.filter( function ( id ) {
					return self.config.fields[ id ].step === step.id;
				} );

				if ( ! fields.length ) {
					return;
				}

				const section = document.createElement( 'section' );
				section.className = 'primiko__summary-step';
				const heading = document.createElement( 'div' );
				heading.className = 'primiko__summary-head';
				const title = document.createElement(
					'h' + Math.min( 6, ( self.config.heading || 2 ) + 1 )
				);
				title.textContent = step.title;
				const edit = document.createElement( 'button' );
				edit.type = 'button';
				edit.className = 'primiko__link';
				edit.textContent = self.i18n.edit;
				edit.setAttribute(
					'aria-label',
					format( self.i18n.editStep, step.title )
				);
				edit.addEventListener( 'click', function () {
					self.show( step.id, true );
				} );
				heading.appendChild( title );
				heading.appendChild( edit );
				section.appendChild( heading );

				// Only the answers given: a list of empty optional questions is
				// noise. A step left empty keeps its heading and edit link.
				const answered = fields.filter( function ( id ) {
					return '' !== self.display( id );
				} );
				const list = document.createElement(
					answered.length ? 'dl' : 'p'
				);

				if ( ! answered.length ) {
					list.className = 'is-empty';
					list.textContent = self.i18n.notSpecified;
				}

				answered.forEach( function ( id ) {
					const term = document.createElement( 'dt' );
					const detail = document.createElement( 'dd' );

					term.textContent = self.config.fields[ id ].label;
					detail.textContent = self.display( id );
					list.appendChild( term );
					list.appendChild( detail );
				} );

				section.appendChild( list );
				container.appendChild( section );
			} );
		},

		/**
		 * Wires a photo picker.
		 *
		 * @param {HTMLElement} picker Picker element.
		 */
		setupPhotos( picker ) {
			const self = this;
			const id = picker
				.closest( '[data-field]' )
				.getAttribute( 'data-field' );
			const input = picker.querySelector( 'input[type="file"]' );
			const max = parseInt( picker.getAttribute( 'data-max' ), 10 );

			this.photos[ id ] = [];
			this.renderPhotos( id );

			input.addEventListener( 'change', function () {
				let files = Array.prototype.slice.call( input.files );
				const room = max - self.photos[ id ].length;
				const tooMany = files.length > room;

				input.value = '';
				files = files.slice( 0, Math.max( 0, room ) );

				if ( ! files.length ) {
					if ( tooMany ) {
						self.setError( id, format( self.i18n.photosMax, max ) );
					}
					return;
				}

				self.pending++;
				picker.querySelector( '.primiko__photo-count' ).textContent =
					self.i18n.photoWorking;

				Promise.all(
					files.map( function ( file ) {
						return resize( file, self.config.photoSize );
					} )
				).then( function ( blobs ) {
					let failed = false;

					blobs.forEach( function ( blob ) {
						if ( blob ) {
							self.photos[ id ].push( {
								blob,
								url: URL.createObjectURL( blob ),
							} );
						} else {
							failed = true;
						}
					} );

					self.pending--;
					self.renderPhotos( id );
					self.refresh();

					if ( failed ) {
						self.setError( id, self.i18n.photoBad );
					} else if ( tooMany ) {
						self.setError( id, format( self.i18n.photosMax, max ) );
					} else {
						self.clearError( id );
					}
				} );
			} );
		},

		/**
		 * Shows the thumbnails of the chosen photos.
		 *
		 * @param {string} id Field identifier.
		 */
		renderPhotos( id ) {
			const self = this;
			const picker =
				this.fieldElement( id ).querySelector( '.primiko__photos' );
			const list = picker.querySelector( '.primiko__previews' );
			const max = parseInt( picker.getAttribute( 'data-max' ), 10 );

			list.textContent = '';

			this.photos[ id ].forEach( function ( photo, index ) {
				const item = document.createElement( 'li' );
				const image = document.createElement( 'img' );
				const remove = document.createElement( 'button' );

				image.src = photo.url;
				image.alt = '';
				remove.type = 'button';
				remove.className = 'primiko__photo-remove';
				// Static SVG from the plugin settings, not visitor input.
				remove.innerHTML = self.config.icons.close;
				remove.setAttribute(
					'aria-label',
					format( self.i18n.photoRemove, index + 1 )
				);
				remove.addEventListener( 'click', function () {
					URL.revokeObjectURL( photo.url );
					self.photos[ id ].splice( index, 1 );
					self.renderPhotos( id );
					self.refresh();
					picker.querySelector( 'input[type="file"]' ).focus();
				} );

				item.appendChild( image );
				item.appendChild( remove );
				list.appendChild( item );
			} );

			picker.querySelector( '.primiko__photo-count' ).textContent =
				format(
					this.i18n.photoCounts[ this.photos[ id ].length ],
					this.photos[ id ].length,
					max
				);
			picker.querySelector( 'input[type="file"]' ).disabled =
				this.photos[ id ].length >= max;
		},

		/**
		 * Checks every visible step, then sends the request.
		 */
		send() {
			const self = this;
			const steps = this.state.steps;

			if ( this.pending || this.buttons.submit.disabled ) {
				return;
			}

			const invalid = steps.filter( function ( id ) {
				return ! self.validate( id );
			} )[ 0 ];

			if ( invalid ) {
				if ( invalid !== this.current ) {
					this.show( invalid, false );
					this.validate( invalid );
				}
				return;
			}

			const captcha = this.captchaToken();

			if ( null === captcha ) {
				this.showAlert( this.i18n.captchaWait );
				return;
			}

			this.busy( true );

			Promise.all( [
				this.token ? Promise.resolve( this.token ) : this.fetchToken(),
				captcha,
			] )
				.then( function ( results ) {
					self.captchaValue = results[ 1 ];
					return self.afterMinDelay().then( function () {
						return self.post( false );
					} );
				} )
				.catch( function () {
					self.busy( false );
					self.showAlert( self.i18n.sendFailed );
				} );
		},

		/**
		 * Shows the Turnstile widget in the review step, once its script has
		 * loaded. reCAPTCHA v3 has no widget: it runs when the form is sent.
		 *
		 * @param {HTMLElement} container Widget container.
		 * @param {number}      tries     Attempts so far, while the script loads.
		 */
		renderCaptcha( container, tries ) {
			const self = this;
			const captcha = this.config.captcha;

			if (
				! captcha ||
				'turnstile' !== captcha.provider ||
				this.captchaWidget !== undefined
			) {
				return;
			}

			if ( ! window.turnstile ) {
				if ( tries < 40 ) {
					window.setTimeout( function () {
						self.renderCaptcha( container, tries + 1 );
					}, 250 );
				}
				return;
			}

			this.captchaWidget = window.turnstile.render( container, {
				sitekey: captcha.siteKey,
				language: document.documentElement.lang || 'auto',
				callback( token ) {
					self.turnstileToken = token;
				},
				'expired-callback'() {
					self.turnstileToken = null;
				},
				'error-callback'() {
					self.turnstileToken = null;
				},
			} );
		},

		/**
		 * Answer of the captcha to send with the request.
		 *
		 * @return {Promise<string>|null} Token, or null when the visitor must
		 *                                still complete the Turnstile check.
		 */
		captchaToken() {
			const captcha = this.config.captcha;

			if ( ! captcha ) {
				return Promise.resolve( '' );
			}

			if ( 'turnstile' === captcha.provider ) {
				// Script blocked or not loaded: the server decides.
				if ( ! window.turnstile ) {
					return Promise.resolve( '' );
				}

				return this.turnstileToken
					? Promise.resolve( this.turnstileToken )
					: null;
			}

			return new Promise( function ( resolve ) {
				if ( ! window.grecaptcha ) {
					resolve( '' );
					return;
				}

				window.grecaptcha.ready( function () {
					window.grecaptcha
						.execute( captcha.siteKey, { action: 'submit' } )
						.then( resolve, function () {
							resolve( '' );
						} );
				} );
			} );
		},

		/**
		 * Asks for a new Turnstile check after a refusal.
		 */
		resetCaptcha() {
			this.turnstileToken = null;

			if ( window.turnstile && this.captchaWidget !== undefined ) {
				window.turnstile.reset( this.captchaWidget );
			}
		},

		/**
		 * Posts the answers and the photos.
		 *
		 * @param {boolean} retried Whether a new token was already asked for.
		 * @return {Promise} Settles once the answer is handled.
		 */
		post( retried ) {
			const self = this;
			const data = new FormData();
			const answers = {};

			this.state.fields.forEach( function ( id ) {
				if ( 'photos' === self.config.fields[ id ].type ) {
					self.photos[ id ].forEach( function ( photo, index ) {
						data.append(
							'photos[]',
							photo.blob,
							'photo-' + ( index + 1 ) + '.jpg'
						);
					} );
				} else {
					answers[ id ] = self.read( id );
				}
			} );

			data.append( 'form', this.config.form );
			data.append( 'token', this.token || '' );
			data.append(
				'website',
				this.form.querySelector( '[name="website"]' ).value
			);
			data.append( 'answers', JSON.stringify( answers ) );
			data.append( 'captcha', this.captchaValue || '' );

			return fetch( this.config.submitUrl, {
				method: 'POST',
				body: data,
				credentials: 'omit',
			} )
				.then( function ( response ) {
					return response.json().then( function ( body ) {
						return { ok: response.ok, body };
					} );
				} )
				.then( function ( result ) {
					const code = result.body && result.body.code;

					if ( result.ok ) {
						self.finish( result.body.message );
						return;
					}

					if (
						! retried &&
						( 'primiko_token_expired' === code ||
							'primiko_token_invalid' === code )
					) {
						return self
							.fetchToken()
							.then( function () {
								return self.afterMinDelay();
							} )
							.then( function () {
								return self.post( true );
							} );
					}

					self.busy( false );

					if ( 'primiko_captcha' === code ) {
						self.resetCaptcha();
					}

					if (
						'primiko_invalid' === code &&
						result.body.data &&
						result.body.data.fields
					) {
						self.serverErrors( result.body.data.fields );
						return;
					}

					self.showAlert(
						( result.body && result.body.message ) ||
							self.i18n.sendFailed
					);
				} );
		},

		/**
		 * Shows the errors found by the server, on the step of the first one.
		 *
		 * @param {Object} errors Messages keyed by field identifier.
		 */
		serverErrors( errors ) {
			const first = Object.keys( errors ).filter( function ( id ) {
				return this.config.fields[ id ];
			}, this )[ 0 ];

			if ( ! first ) {
				this.showAlert( this.i18n.sendFailed );
				return;
			}

			this.show( this.config.fields[ first ].step, false );
			this.showErrors( errors );
		},

		/**
		 * Marks the form as sending, or not.
		 *
		 * @param {boolean} busy Whether the request is being sent.
		 */
		busy( busy ) {
			const button = this.buttons.submit;

			if ( busy ) {
				button.setAttribute( 'data-label', button.textContent );
				button.textContent = this.i18n.sending;
			} else if ( button.getAttribute( 'data-label' ) ) {
				button.textContent = button.getAttribute( 'data-label' );
			}

			button.disabled = busy;
			this.buttons.prev.disabled = busy;
			this.form.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
		},

		/**
		 * Replaces the form with the confirmation message.
		 *
		 * @param {string} message Confirmation from the server.
		 */
		finish( message ) {
			const self = this;

			Object.keys( this.photos ).forEach( function ( id ) {
				self.photos[ id ].forEach( function ( photo ) {
					URL.revokeObjectURL( photo.url );
				} );
			} );

			const text = document.createElement( 'p' );

			text.style.margin = '0';
			text.textContent = message;
			this.done.innerHTML = this.config.icons.check;
			this.done.appendChild( text );
			this.form.hidden = true;
			this.live.textContent = '';
			this.done.hidden = false;
			this.done.focus();
			this.root.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		},
	};

	function init() {
		document
			.querySelectorAll( '.primiko[data-primiko]' )
			.forEach( function ( root ) {
				if ( ! root.classList.contains( 'is-ready' ) ) {
					new QuoteForm( root ); // eslint-disable-line no-new
				}
			} );
	}

	/**
	 * Waits for the stylesheet, which loads without holding the page: the
	 * form is only shown once it is styled. Gives up after three seconds.
	 *
	 * @param {Function} callback Called once the stylesheet applies.
	 */
	function whenStyled( callback ) {
		const sample = document.querySelector( '.primiko[data-primiko]' );
		const link = document.getElementById( 'primiko-form-css' );
		let done = false;

		const finish = function () {
			if ( ! done ) {
				done = true;
				callback();
			}
		};

		// The stylesheet makes the wrapper a size container.
		if (
			! sample ||
			! link ||
			'inline-size' === window.getComputedStyle( sample ).containerType
		) {
			finish();
			return;
		}

		link.addEventListener( 'load', finish );
		link.addEventListener( 'error', finish );
		window.setTimeout( finish, 3000 );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			whenStyled( init );
		} );
	} else {
		whenStyled( init );
	}
} )();
