<?
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
define("DRUPAL_ROOT", dirname(realpath(__FILE__)));
require_once('includes/bootstrap.inc');
require_once('includes/password.inc');
error_reporting(E_ALL);
drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);

include_once("include.php");

function update_orgs_with_abn($nid = null) {
	if($nid == null)
		$query = db_query("SELECT nid FROM {node} WHERE type = 'orgnisation'");
	else
		$query = db_query("SELECT nid FROM {node} WHERE type = 'orgnisation' AND nid = :nid",
						 array(":nid" => $nid));

	$count = 1;
	foreach($query as $result) {
		$node = node_load($result->nid, null, true);

		if(empty($node->field_abn['en'][0]['value']))
			continue;

		$abn = str_replace(" ", "", $node->field_abn['en'][0]['value']); //some orgs got spaces in their abn.

		printf("Looking at node %s with abn %s (%d/%d)\n", $node->nid, $abn, $count, $query->rowCount());

		//skip if this has already been done. 
		if($node->field_abr_processed['und'][0]['value'] == 1) {
			printf("This node is already done\n");
			continue;
		}

		if(!empty($abn) && is_numeric($abn)) {
			if(($data = abr_by_abn($node->field_abn['en'][0]['value'])) == null) {
				printf("Issue talking to the ABR\n");
				continue;
			}
			$data_parsed = abr_by_abn_get_relevent_data($data);

			$i = 0;
			foreach($data_parsed["Trading Names"] as $tnames) {
				printf("Adding a Trading Name %s\n", ucwords(strtolower(($tnames->organisationName))));

				if(!check_active($tnames)) {
					//printf("This org is marked for expiry, skipping\n");
					continue;
				}
				$node->field_tradingname['en'][$i++]['value'] = ucwords(strtolower(($tnames->organisationName)));
			}

			$i = 0;
			foreach($data_parsed['DGR Status'] as $dgrstatus) {
				printf("Adding DGR Status\n");

				if(!check_active($dgrstatus)) {
					//printf("This field is marked for expiry, skipping\n");
					continue;
				}
				$node->field_dgr_status['und'][$i++]['value'] = 1;
			}

			$i = 0;
			foreach($data_parsed["DGR Funds"] as $dgrfund) {
				printf("Adding a DGR fund %s\n", $dgrfund->dgrFundName->organisationName);

				if(!check_active($dgrfund)) {
					//printf("This field is marked for expiry, skipping\n");
					continue;
				}
				$node->field_dgr_funds['und'][$i++]['value'] = ucwords(strtolower($dgrfund->dgrFundName->organisationName)); //new
			}

			$i = 0;
			foreach($data_parsed["Entity Type"] as $etype) {
				printf("Adding a Entity Type %s\n", $etype->entityDescription);

				if(!check_active($etype)) {
					//printf("This field is marked for expiry, skipping\n");
					continue;
				}
				$term = get_or_make_term($etype->entityDescription, "organisation_type");
				$node->field_organisationtype['en'][$i++]['tid'] = $term->tid;
			}

			$i = 0;
			foreach($data_parsed["Charity Type"] as $ctype) {
				printf("Adding a Charity Type %s\n", $ctype->charityTypeDescription);

				if(!check_active($ctype)) {
					//printf("This field is marked for expiry, skipping\n");
					continue;
				}
				$term = get_or_make_term($ctype->charityTypeDescription, "charity_type"); //new
				$node->field_charity_type['und'][$i++]['tid'] = $term->tid;
			}

			$i = 0;
			foreach($data_parsed["Tax Concession"] as $tcon) {
				printf("Adding a Tax Consession %s\n", $tcon->endorsementType);

				if(!check_active($tcon)) {
					//printf("This field is marked for expiry, skipping\n");
					continue;
				}
				$term = get_or_make_term($tcon->endorsementType, "tax_status");
				$node->field_taxstatus['en'][$i++]['tid'] = $term->tid;
			}

			$node->field_abr_processed['und'][0]['value'] = true;
			node_save($node);
			printf("Saved data for node %s\n\n", $node->nid);
			$count++;
		}
	}
}

define("ABRSCORE", 100);

function update_abns_where_none($nid = null) {
	if($nid == null)
		$query = db_query("SELECT nid FROM {node} WHERE type = 'orgnisation'");
	else
		$query = db_query("SELECT nid FROM {node} WHERE type = 'orgnisation' AND nid = :nid",
				 array(":nid" => $nid));

	$count = 0;
	foreach($query as $result) {
		$node = node_load($result->nid, null, true);

		if(!empty($node->field_abn['en'][0]['value']))
			continue;

		if(!empty($node->field_potential_abn['und'][0]['value']))
			continue;

		$term = taxonomy_term_load($node->field_postalsuburbstate['en'][0]['tid']);
		if($term) { 
			$pcode = explode(" ", $term->name);
			$pcode = !empty($pcode[2]) ? $pcode[2] : null;
		}

		if($pcode && is_numeric($pcode)) {
			$data = abr_by_name($node->title, null, $pcode);
		} else {
			$data = abr_by_name($node->title);
		}
		if($data == null) { //we can set a score here and postcode.
			printf("Could not talk to the abr");
			continue;
		}
		$data = abr_by_name_get_relevent_data($data);
		
		if(empty($data["ABN"])) {
			continue;
		}

		$dname = !empty($data['Name']) ? $data["Name"] : $data['Trading Name'];
		$dscore = !empty($data['Name Score']) ? $data["Name Score"] : $data["Trading Name Score"];

		printf("Node ID: %s\nNode title: %s\nABR title: %s\nFound abn: %s\nScore %s\n", 
			$node->nid, $node->title, $dname, $data["ABN"], $dscore);
		if($pcode && is_numeric($pcode))
			printf("Postcode %s was found\n", $pcode);

		if($dscore >= ABRSCORE) {
			printf("Score is $dscore, so we are auto updating this\n");
			$node->field_abn['en'][0]['value'] = $data["ABN"]; //do we need to save the active status?
			node_save($node);
			printf("Saved data for node %s\n\n", $node->nid);
			update_orgs_with_abn($node->nid);
		} else {
			printf("Score is $dscore, so we are putting this as a potential\n");
			$node->field_potential_abn['und'][0]['value'] = $data["ABN"]; //do we need to save the active status?
			$node->field_potential_abn_score['und'][0]['value'] = $dscore;
			node_save($node);
			printf("Saved potential data for node $s\n\n", $node->nid);
		}
	}
}

if($argv[1] == "exsisting-abn") {
	printf("Running Stage 1, ABR details update based on ABN\n");
	if($argc == 3) {
		update_orgs_with_abn($argv[2]);
	} else {
		update_orgs_with_abn();
	}
} else if($argv[1] == "name-to-abn") {
	printf("Running Stage 2, Finding orgs with name but no ABN and populating\n");
	if($argc == 3) {
		update_abns_where_none($argv[2]);
	} else {
		update_abns_where_none();
	}
} else {
	printf("Please use either exsisting-abn or name-to-abn %s\n", $argv[1]);
}

printf("Done\n");

?>
