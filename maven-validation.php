<?php

/**
 * Description of maven-validation
 *
 * @author mustela
 */
class MavenValidation {

	public function __construct () {
		;
	}

	public static function isMavenMissing () {

		$result = class_exists( '\Maven\Settings\MavenRegistry' );

		if ( !$result ) {
			$exists = in_array( 'maven/maven.php', (array) get_option( 'active_plugins', array() ) );
			if ( $exists ) {
				$result = require_once( ABSPATH . "wp-content/plugins/maven/maven.php" );
			} else {
				self::addMenu();
			}
		}

		return !$result;
	}

	public static function addMenu () {
		add_action( 'admin_menu', '\MavenValidation::init' );
	}

	public static function init () {
		add_menu_page( 'Maven Missing', 'Maven Missing', 'manage_options', 'maven-missing', '\MavenValidation::showHelp' );
	}

	public static function showHelp () {
		?>

		<div class="wrap">
			<div id="icon-index" class="icon32"><br></div><h2>Maven</h2>

			<div id="welcome-panel" class="welcome-panel">
				<div class="welcome-panel-content">
					<h3>Welcome to Maven!</h3>
					<p class="about-description">Remember, you need to have Maven plugin, in order to have Maven Authorize.net</p>
					<!--					<div class="welcome-panel-column-container">
											<div class="welcome-panel-column">
												<h4>Download / Activate Maven</h4>
												<a class="button button-primary button-hero hide-if-customize" href="">Install Maven</a>
											</div>
											
										</div>-->
				</div>
			</div>



		</div>

		<?php
	}

}
