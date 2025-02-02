const Page = require( 'wdio-mediawiki/Page' ),
	Api = require( 'wdio-mediawiki/Api' ),
	Util = require( 'wdio-mediawiki/Util' );

class HistoryPage extends Page {
	get heading() { return browser.element( '#firstHeading' ); }
	get headingText() { return browser.getText( '#firstHeading' ); }
	get comment() { return browser.element( '#pagehistory .comment' ); }
	get rollback() { return browser.element( '.mw-rollback-link' ); }
	get rollbackLink() { return browser.element( '.mw-rollback-link a' ); }
	get rollbackConfirmable() { return browser.element( '.mw-rollback-link .jquery-confirmable-text' ); }
	get rollbackConfirmableYes() { return browser.element( '.mw-rollback-link .jquery-confirmable-button-yes' ); }
	get rollbackConfirmableNo() { return browser.element( '.mw-rollback-link .jquery-confirmable-button-no' ); }
	get rollbackNonJsConfirmable() { return browser.element( '.mw-htmlform .oo-ui-fieldsetLayout-header .oo-ui-labelElement-label' ); }
	get rollbackNonJsConfirmableYes() { return browser.element( '.mw-htmlform .mw-htmlform-submit-buttons button' ); }

	open( title ) {
		super.openTitle( title, { action: 'history' } );
	}

	toggleRollbackConfirmationSetting( enable ) {
		Util.waitForModuleState( 'mediawiki.api', 'ready', 5000 );
		return browser.execute( function ( enable ) {
			return new mw.Api().saveOption(
				'showrollbackconfirmation',
				enable ? '1' : '0'
			);
		}, enable );
	}

	vandalizePage( name, content ) {
		const vandalUsername = 'Evil_' + browser.options.username;

		browser.call( function () {
			return Api.edit( name, content );
		} );

		browser.call( function () {
			return Api.createAccount(
				vandalUsername, browser.options.password
			);
		} );

		browser.call( function () {
			return Api.edit(
				name,
				'Vandalized: ' + content,
				vandalUsername
			);
		} );
	}
}

module.exports = new HistoryPage();
