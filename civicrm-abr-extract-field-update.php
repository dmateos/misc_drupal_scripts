<?
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
chdir("..");
define('DRUPAL_ROOT', dirname(realpath(__FILE__)));
require_once('includes/bootstrap.inc');
require_once("abr.php");
drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);

/* Civi wont store stuff with () etc so lets convert them. */
global $tax_lookup;
$tax_lookup = array(
	"Deductible Gift Recipient (DGR)" => "DGR",
	"FBT Exemption" => "FBT Exemption",
	"FBT Rebate" => "FBT Rebate",
	"GST Concession" => "GST Concession",
	"Income Tax Exempt (ITE)" => "ITE",
	"Income Tax Exemption" => "ITE",
	"Public Benevolent Institution (PBI)" => "PBI",
	"Self Assess" => "Self Assess",
	"No Tax Status" => "No Tax Status",
);


function run() {
	global $tax_lookup;
	$query = db_query("SELECT abn,entity_id FROM {civicrm_value_1_registered_identifiers} where abn = \"13903061955\"");
	civicrm_initialize();
	require_once('api/UFGroup.php');
	require_once('api/v2/Contact.php');
	require_once('api/v2/Group.php');

	$count = 0;
	while($row = db_fetch_array($query)) {
		$abn = str_replace(" ", "", $row['abn']);
		$cid = $row['entity_id'];

		printf("processing abn: $abn count: $count id: $cid\n");

		/* We skip these coz theyre shitting out civicrm when we save. */
		if($cid == 19369 || $cid == 19599) { // :(
			continue;
		}

		if(!$data = abr_by_abn($abn)) {
			printf("Issue talking to the abr\n");
			continue;
		}
		
		/* Lets check if this is active, if not we mark it as Cancelled. */	
		$active = "Invalid";
		foreach($data['response']->businessEntity200506->entityStatus as $i) {
			if($i->entityStatusCode == "Cancelled") {
				$active = "Cancelled";
				printf("org is not active :(\n");
			}
			else if($i=>entityStatusCode == "Active") {
				$active = "Active";
				printf("org is active\n");
			}
		}

		/* Parse the data a bit more using old helper function from some other code. */
		$data_parsed = abr_by_abn_get_relevent_data($data);
		
		/* Grab the tax types and charity types and store them in arrays. */
		$tax_type = array();
		foreach($data_parsed["Tax Concession"] as $tcon) {
			/* Here we map to sane values civi will beable to store. */
			$tax_type[] = $tax_lookup[(string)$tcon->endorsementType];
			printf("Found tax status %s\n", $tcon->endorsementType);
		}

		$char_type = array();
		foreach($data_parsed["Charity Type"] as $ctype) {
			$char_type[] = (string)$ctype->charityTypeDescription;
			printf("Found charity taype %s\n", $ctype->charityTypeDescription);
		}

		$params = array('contact_id' => $cid);
		$org = civicrm_contact_get($params);

		$params = array(
			'contact_id' => $org['contact_id'],
			'contact_type' => 'Organization',
			'organization_name' => $org['organization_name'],  //apparently this is a civi bug thats gunnna fuck us
			'custom_113' => $active, //abn status
		);

		if(!empty($tax_type)) {
			$params['custom_111'] = $tax_type;
		}
		if(!empty($char_type)) {
			$params['custom_112'] = $char_type[0];
		}

		if($active == "Active") {
			if(!empty($tax_type) || !empty($char_type)) {
				print_r($params);
				$save = civicrm_contact_add($params);
				if($save->is_error == 0) {
					printf("saved details for org %s\n\n", $org['organization_name']);
				}
				else {
					printf("error");
					exit(1);
				}
			} else {
				$q = db_fetch_array(db_query("select organization_qualification from civicrm_value_1_qualification_status where entity_id = " . $cid));

				if($q["organization_qualification"] == "Qualified") {
					$params['custom_111'] = array("Self Assess");
				}

				print_r($params);
				printf("no data for %s\n", $org['organisation_name']);
				$save = civicrm_contact_add($params);
				if($save->is_error == 0) {
					printf("saved details for org %s\n\n", $org['organization_name']);
				}
				else {
					printf("error");
					exit(1);
				}		
			}
		}
		$count++;
	}
}

function dump_abr() {
	global $tax_lookup;
	$query = db_query("SELECT abn,entity_id FROM {civicrm_value_1_registered_identifiers}");

	$count = 0;
	$tax_type = array();
	$char_type = array();
	while($row = db_fetch_array($query)) {
		$abn = $row['abn'];
		if(!$data = abr_by_abn($abn)) {
			printf("Issue talking to the abr\n");	
			continue;
		}	
		$data_parsed = abr_by_abn_get_relevent_data($data);
		foreach($data_parsed["Tax Concession"] as $tcon) {
			$tax_type[(string)$tcon->endorsementType]++;
		}

		foreach($data_parsed["Charity Type"] as $ctype) {
			$char_type[(string)$ctype->charityTypeDescription]++;
		}
		printf("tax types\n");
		print_r($tax_type);
		printf("------------------\n");
		printf("char types\n");
		print_r($char_type);
		printf("$count\n");
		$count++;
	}
}

run();
//dump_abr();
?>
