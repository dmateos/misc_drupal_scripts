<?
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
define('DRUPAL_ROOT', dirname(realpath(__FILE__)));
require_once DRUPAL_ROOT . '/includes/bootstrap.inc';
drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);

$file = fopen("data.csv", "r");
$file_out = fopen("data-out.csv", "w");

while(($data = fgetcsv($file, 1000, ",")) != FALSE) {
	$data_new = array();
	$abn = $data[1];
	$query = db_query("SELECT * FROM node 
		LEFT JOIN field_data_field_abn on node.nid = field_data_field_abn.entity_id 
		LEFT JOIN field_data_field_email on node.nid = field_data_field_email.entity_id
		LEFT JOIN users on users.uid = node.uid
		WHERE field_data_field_abn.field_abn_value = :abn", array(":abn" => $abn))->fetchAssoc();

	$data_new[] = !empty($query['field_email_value']) ? $query['field_email_value'] : "nill"; 
	$data_new[] = !empty($query['mail']) ? $query['mail'] : "nill";
	fputcsv($file_out, $data_new);
	printf("$data[0] $data[1]\n");
}

fclose($file);
fclose($file_out);

?>
