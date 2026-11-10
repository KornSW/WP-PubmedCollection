<?php

/**
 * Plugin Name: PubMed-Collection
 * Update URI: https://raw.githubusercontent.com/KornSW/WP-PubmedCollection/master/doc/kornsw-pubmedcollection.update.json
 * Plugin URI: https://github.com/KornSW/WP-PubmedCollection
 * Description: Includes PubMed publication lists via the official NCBI E-utilities API (cached, with admin alarm mail). Collections are managed under "Beiträge > PubMed (KornSW)": [include_pubmed collection="64421242"]
 * Version: 1.3.0
 * Author: KornSW
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL-3.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

  
/*************** SELF-UPDATE ***************/
define( 'KSWPUBMEDCOLLEC9EBD_SELF_UPDATE_DIAGNOSTICS', false );
require_once __DIR__ . '/self-update.php';
kswpubmedcollec9ebd_bootstrap( __FILE__ );
/*******************************************/

define('KORNSW_PMC_FILE', __FILE__);
define('KORNSW_PMC_DIR', plugin_dir_path(__FILE__));
define('KORNSW_PMC_URL', plugin_dir_url(__FILE__));
define('KORNSW_PMC_VERSION', get_file_data(__FILE__, ['Version' => 'Version'])['Version']);

spl_autoload_register(function (string $className): void {
  $namespacePrefix = 'KornSW\\PubmedCollection\\';
  if (strncmp($className, $namespacePrefix, strlen($namespacePrefix)) !== 0) {
    return;
  }
  $relativeClassName = substr($className, strlen($namespacePrefix));
  $fileName = KORNSW_PMC_DIR . 'src/' . str_replace('\\', '/', $relativeClassName) . '.php';
  if (is_readable($fileName)) {
    require_once $fileName;
  }
});

add_action('plugins_loaded', [\KornSW\PubmedCollection\Plugin::class, 'boot']);
