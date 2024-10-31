<?php

/**
 * Plugin Name: PDF Importer for Gravity
 * Plugin URI: http://smartforms.rednao.com/getit
 * Description: Import a pdf and fill it with your entry information.
 * Author: RedNao
 * Author URI: http://rednao.com
 * Version: 1.3.66
 * Text Domain: rednaopdfimporter
 * Domain Path: /languages/
 * License: GPLv3
 * License URI: http://www.gnu.org/licenses/gpl-3.0
 * Slug: pdf-importer-for-gravity
 */

use rnpdfimporter\core\Integration\Adapters\Gravity\Loader\GravitySubLoader;
use rnpdfimporter\core\Integration\Adapters\WPForm\Loader\WPFormSubLoader;
use rnpdfimporter\core\Loader;
require_once dirname(__FILE__).'/AutoLoad.php';

new GravitySubLoader('rnpdfimportergravity','rednaopdfimpgravity',27,13,basename(__FILE__));