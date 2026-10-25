/**
 * focusField.js: a variation fix target opens the Variations tab (§6.6, R5).
 */

import { focusField } from '../../assets/js/editor/utils/focusField';

const MARKUP = `
	<div id="woocommerce-product-data">
		<ul class="product_data_tabs">
			<li class="general_options general_tab"><a href="#general_product_data">General</a></li>
			<li class="variations_options variations_tab show_if_variable"><a href="#variable_product_options">Variations</a></li>
		</ul>
		<div id="general_product_data"></div>
		<div id="variable_product_options">
			<div class="woocommerce_variations"></div>
		</div>
	</div>
`;

describe( 'focusField', () => {
	beforeEach( () => {
		document.body.innerHTML = MARKUP;
		window.Element.prototype.scrollIntoView = jest.fn();
	} );

	it( 'opens the variations tab for panel variations', () => {
		const variations = document.querySelector( '.variations_options > a' );
		const general = document.querySelector( '.general_options > a' );
		const variationsClick = jest.fn( ( event ) => event.preventDefault() );
		const generalClick = jest.fn( ( event ) => event.preventDefault() );

		variations.addEventListener( 'click', variationsClick );
		general.addEventListener( 'click', generalClick );

		expect(
			focusField(
				{ selector: '#variable_product_options', panel: 'variations' },
				document,
				{}
			)
		).toBe( true );

		expect( variationsClick ).toHaveBeenCalledTimes( 1 );
		expect( generalClick ).not.toHaveBeenCalled();
	} );
} );
