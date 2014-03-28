<?
$abr_base = "http://abr.business.gov.au/ABRXMLSearch/AbrXmlSearch.asmx";
$abr_abn_args = "/SearchByABNv200506?searchString=ABNSEARCHSTRING&includeHistoricalDetails=Y&authenticationGuid=86014b24-41e1-4dd5-ab16-f2cd2cfe4019";
$abr_name_args = "/ABRSearchByNameAdvancedSimpleProtocol?name=ABNSEARCHSTRING&postcode=POSTCODE&legalName=&tradingName=&NSW=&SA=&ACT=&VIC=&WA=&NT=&QLD=&TAS=&authenticationGuid=86014b24-41e1-4dd5-ab16-f2cd2cfe4019&searchWidth=&minimumScore=MINSCORE";

$GLOBALS["ABR_ABN_URL"] = $abr_base . $abr_abn_args;
$GLOBALS["ABR_NAME_URL"] = $abr_base . $abr_name_args;

function abr_by_abn($abn) {
	$c = curl_init();
	$newurl = preg_replace("/ABNSEARCHSTRING/", str_replace(" ", "", $abn), $GLOBALS["ABR_ABN_URL"]);
	curl_setopt($c, CURLOPT_URL, $newurl);
	curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($c, CURLOPT_CONNECTTIMEOUT, 10);

	if(($xml = curl_exec($c)) && !curl_errno($c)) {
		$data = new SimpleXMLElement($xml);
		$data = get_object_vars($data);
	} else {
		return NULL;
	}
	curl_close($c);
	return $data;
}

function abr_by_name($name, $minscore = 0, $postcode = 0) {
	$c = curl_init();
	$name = urlencode($name);
	$newurl = preg_replace("/ABNSEARCHSTRING/", str_replace(" ", "", $name), $GLOBALS["ABR_NAME_URL"]);
	$newurl = preg_replace("/MINSCORE/", str_replace(" ", "", $minscore), $newurl);
	if($postcode)
		$newurl = preg_replace("/POSTCODE/", str_replace(" ", "", $postcode), $newurl);
	else 
		$newurl = preg_replace("/POSTCODE/", str_replace(" ", "", ""), $newurl);

	curl_setopt($c, CURLOPT_URL, $newurl);
	curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($c, CURLOPT_CONNECTTIMEOUT, 10);

	if(($xml = curl_exec($c)) && !curl_errno($c)) {
		$data = new SimpleXMLElement($xml);
		$data = get_object_vars($data);
	} else {
		return NULL;
	}
	curl_close($c);
	return $data;
}

function abr_by_abn_get_relevent_data($data) {
	$beobj = $data['response']->businessEntity200506;

	$parsed = array(
		"ABN" => $beobj->ABN->identifierValue,
		"Trading Names" => $beobj->otherTradingName,
		"DGR Status" => $beobj->dgrEndorsement,
		"DGR Funds" => $beobj->dgrFund,
		"Entity Type" => $beobj->entityType,
		"Charity Type" => $beobj->charityType,
		"Tax Concession" => $beobj->taxConcessionCharityEndorsement,
	);
	return $parsed;
}

function abr_by_name_get_relevent_data($data) {
	$obj = $data['response']->searchResultsList->searchResultsRecord;

	$parsed = array(
		"ABN" => $obj->ABN->identifierValue,
		"ABN Active" => $obj->ABN->identifierStatus,
		"Name" => $obj->mainName->organisationName,
		"Trading Name" => $obj->mainTradingName->organisationName,
		"Name Score" => $obj->mainName->score,
		"Trading Name Score" => $obj->mainTradingName->score,
	);
	return $parsed;
}

function get_or_make_term($name, $vocab) {
	$t = taxonomy_get_term_by_name($name, $vocab);

	if(!empty($t)) {
		return array_shift($t);
	}

	$vid = taxonomy_vocabulary_machine_name_load($vocab)->vid;
	$term = new stdClass();
	$term->name = $name;
	$term->vid = $vid;
	taxonomy_term_save($term);
	printf("Created new taxonomy term %s in %s\n", $name, $vocab);
	return $term;
}

function check_active($org) {
	//Weird how sometimes it has 0001-01-01, signifies no current end also.
	if($org->effectiveTo == "" || $org->effectiveTo == "0001-01-01")
		return true;
   	return false;
}

function get_input($msg) {
	fwrite(STDOUT, "$msg: ");
	$varin = trim(fgets(STDIN));
	return $varin;
}
?>
