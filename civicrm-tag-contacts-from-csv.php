<?
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
define('DRUPAL_ROOT', dirname(realpath(__FILE__)));
require_once './includes/bootstrap.inc';
drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);

function load_org($object_id) {
    civicrm_initialize();
    require_once("api/v2/Contact.php");

    $params = array(
        "contact_id" => $object_id,
    );
    return civicrm_contact_get($params);
}

function assign_contact_to_tag($cid, $tid) {
    civicrm_initialize();
    require_once "api/Tag.php";
    require_once "api/v2/EntityTag.php";

    #	$org = load_org($cid);
    $org = organization_load($cid);
    print_r($org);

    if($org && !$org["is_error"]) {
        $params = array(
            "tag_id" => $tid,
            "contact_id" => $cid,
        );
        $entity_tag = civicrm_entity_tag_add($params);

        if(!civicrm_error($entity_tag)) {
            return true;
        } else {
            printf("civi error\n");
        }
    }
    return false;
}

function read_csv($file) {
    $fh = fopen($file, "r");
    $lines = array();
    while(($data = fgetcsv($fh)) != false) {
        $lines[] = $data;
    }
    fclose($fh);
    return $lines;
}

$csv = read_csv("tags.csv");

foreach($csv as $c) {
    printf("$c[0] is getting tag $c[2]\n");
    if(is_numeric($c[0]) && assign_contact_to_tag($c[0], $c[2])) {
        printf("done\n");
    } else {
        printf("nope\n");
    }
}

?>
