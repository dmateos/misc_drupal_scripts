<?
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
chdir("..");
define('DRUPAL_ROOT', dirname(realpath(__FILE__)));
require_once('includes/bootstrap.inc');
require_once("abr.php");
drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);

function run() {

	civicrm_initialize();
	require_once('api/UFGroup.php');
	require_once('api/v2/Contact.php');
	require_once('api/v2/Group.php');

	$cid = 320;

	$params = array(
		'contact_id' => $cid,
		'return.custom_111' => 1,
		'return.custom_112' => 1,
		'return.custom_113' => 1,
	);

	$org = civicrm_contact_get($params);

	print_r($org);
}

run();
?>
