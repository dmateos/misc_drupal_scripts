#!/usr/bin/env php
<?php
/* Daniel Mateos, 17/02/2012
 * Merges users from the specified remote database into this bootstraps install.
 * This needs to be ran in a certain order or we will overwrite entries we dont want too.
 */
global $remote_sites;
$remote_sites = array(
	"site1" => array(
		"dbhost" => "",
		"dbuser" => "",
		"dbpass" => "",
		"dbname" => "",
		"dbpass_type" => "d7hash",
	),

	"site2" => array(
		"dbhost" => "",
		"dbuser" => "",
		"dbpass" => "",
		"dbname" => "",
		"dbpass_type" => "d7hash",
	),
);

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
define("DRUPAL_ROOT", dirname(realpath(__FILE__)));
require_once('includes/bootstrap.inc');
require_once('includes/password.inc');
error_reporting(E_ALL);
drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);

if($argv[1] == "import") {
	$dbdetails = $remote_sites[$argv[2]];
	if(!empty($dbdetails)) {
		$users = get_remote_users($dbdetails["dbhost"], $dbdetails["dbname"], $dbdetails["dbuser"], $dbdetails["dbpass"]);
		if($users) {
			import_remote_users($dbdetails["dbname"], $users, $dbdetails["dbpass_type"]);
		}
	}
	else {
		die("could not find any users to import\n");
	}
}
else if($argv[1] == "merge") {
	merge_name_and_mail();
    merge_mail();
	merge_names();
}
else {
	die("usage: php usermerge.php import <database> OR usermerge.php merge\n");
}

//Gets users from the remote database specified.
function get_remote_users($host, $database, $user, $pass) {
	$db = new mysqli($host, $user, $pass, $database);
	if($db->connect_errno) {
		printf("mysql connetion error: %s\n", $db->connect_errno);
		return NULL;
	}

	$result = $db->query("SELECT * FROM users WHERE uid > 1 AND status = 1");
	if($result) {
		$rows = array();
		while($row = $result->fetch_assoc()) {
			$rows[] = $row;
		}
		$result->close();
		$db->close();
		printf("grabbed %d users from remote db %s\n", count($rows), $database);
		return $rows;
	} else {
		$db->close();
		printf("no users found in $database\n");
		return NULL;
	}
}

//Turn the user grabbed remotley into something d7 can def understand
function remote_to_local_map($user, $pwdtype = NULL) {
	$pass = "";
	if($pwdtype == "d7hash") {
		$pass = 'U' . user_hash_password($user['pass'], 11); //http://drupal.org/node/1175562
	} else {
		$pass = $user["pass"];
	}

	$mapping = array(
		"uid" => db_next_id(db_query("SELECT MAX(uid) FROM {users}")->fetchField()), //563 users.module
		"name" => $user["name"],
		"pass" => $pass,
		"mail" => $user["mail"],
		"theme" => $user["theme"],
		"signature" => "",
		"signature_format" => $user["signature_format"],
		"created" => $user["created"],
		"access" => $user["access"],
		"login" => $user["login"],
		"status" => 1,
		"timezone" => 0,
		"language" => "und",
		"picture" => 0,
		"init" => 0,
		"data" => "",
	);
	return $mapping;
}

//Imports remote users into the local db chucking conflicts into user_conflicts and tagging them
//based on how it conflicts.
function import_remote_users($remotedbname, $remoteusers, $pwdtype) {
	$current_count = db_query("SELECT count(uid) FROM {users} WHERE uid > 1")->fetchField();
	printf("importing remote users, there is %d users already in the db\n", $current_count);
	$conflictcount = 0;
	$conflictmail = 0;
	$conflictname = 0;
	$conflictboth = 0;

	//lets roll back if anything messes up
	$transaction = db_transaction();

	foreach($remoteusers as $ruser) {
		$conflict = db_query("SELECT * FROM {users} WHERE (name = :name OR mail = :mail) AND uid > 1",
			array(":name" => $ruser["name"], ":mail" => $ruser["mail"]))->fetchAssoc();

		try {
			if(!$conflict) {
				// Everything is cool, add this user to the main database.
				$usermap = remote_to_local_map($ruser, $pwdtype);
				db_insert("users")->fields($usermap)->execute();

				//Also save to our resolved table, this requires remapping the args a bit.
				$usermap['imported_from'] = $remotedbname;
				$usermap["orig_uid"] = $ruser['uid'];
				db_insert("user_resolved")->fields($usermap)->execute();

				printf("imported %s %s %s with new uid %d\n", $ruser['uid'], $ruser['name'], $ruser['mail'], $usermap['uid']);
			}
			else {
				// Conflict, add to the user conflicts database.
				//print("conflict $remotedbname: " .$user["name"] ." (" .$user["mail"] .") ");
				//print("hub: " . $conflicts["name"] . " (" .$conflicts["mail"] .")\n");
				$conflictcount++;
				$conflict_type = "unknown";

					if((strtolower($ruser["name"]) == strtolower($conflict["name"]))
					&& (strtolower($ruser["mail"]) == strtolower($conflict["mail"]))) {
						$conflict_type = "name AND mail";
						$conflictboth++;
					}
					else if(strtolower($ruser["mail"]) == strtolower($conflict["mail"])) {
						$conflict_type = "mail";
						$conflictmail++;
					}
					else if(strtolower($ruser["name"]) == strtolower($conflict["name"])) {
						$conflict_type = "name";
						$conflictname++;
					}
					else {
						$transaction->rollback();
						die("conflict with no conflict? db rolled back\n");
					}

				$usermap = remote_to_local_map($ruser, $pwdtype);
				$usermap['uid'] = $conflict['uid'];
				$usermap["imported_from"] = $remotedbname;
				$usermap["orig_uid"] = $ruser["uid"];
				$usermap["conflict_on"] = $conflict_type;
				try {
					db_insert("user_conflicts")->fields($usermap)->execute();
					printf("conflict detected between local user %s %s %s and remote user %s %s %s\n",
						$conflict["uid"], $conflict["name"], $conflict["mail"],
						$ruser["uid"], $ruser["name"], $ruser["mail"]);
				}
				//Double conflict, the user is already in the conflict table.
				//Hopefully does not happen.
				catch(PDOException $e) {
					$usermap["double_conflict"] = 1;
					$nid = db_update("user_conflicts")->fields($usermap)->condition("name", $ruser["name"])->execute();
					printf("double conflict %s\n", $e->getMessage());
				}
			}
		}
		catch(Exception $e) {
			$transaction->rollback();
			die("unexpected eror while importing users " . $e->getMessage() . "\n");
		}
	}
	$current_count = db_query("SELECT count(uid) FROM {users} WHERE uid > 1")->fetchField();
	printf("there is %d local users in the db\n", $current_count);
	printf("%d remote users not merged, name: %d, mail: %d, both: %d\n", $conflictcount, $conflictname, $conflictmail, $conflictboth);
}

//Here we merge all account conflicts that match on name AND mail
//by updating the password to the conflicting one.
function merge_name_and_mail() {
	global $dbdetails;

	print("merging users that match on name AND mail\n");
	$conflict_users = db_query("SELECT * FROM {user_conflicts} WHERE conflict_on = 'name AND mail'");

	$transaction = db_transaction();
	$cantmerge = 0;

	foreach($conflict_users as $cuser) {
		try {
			$count = db_update('users')
						->condition('name', $cuser->name)
						->condition('mail', $cuser->mail)
						->fields(array('pass' => $cuser->pass))
						->execute();
			$deleted = db_delete("user_conflicts")
						->condition('name', $cuser->name)
						->condition('mail', $cuser->mail)
						->execute();
			$usr = get_object_vars($cuser);
			unset($usr['id']);
			$resolved = db_insert("user_resolved")
						->fields($usr)
						->execute();

			if($count == 1 && $deleted == 1) {
				printf("updated user matching %s and %s (pass)\n", $cuser->name, $cuser->mail);
			} else {
				$transaction->rollback();
				die("could not update user " . $cuser->name . " " . $cuser->mail . "\n");
			}
		} catch(PDOException $e) {
			$cantmerge++;
			printf("%s %s\n", $cuser->name, $cuser->mail);
			printf("%s\n", $e->getMessage());
		}
	}
	printf("could not merge %d users on name AND mail conflict\n", $cantmerge);
}

//Here we merge the accounts that match on MAIL only by updating
//the name and password in users to the conflicting one.
function merge_mail() {
	print("merging users that match on MAIL\n");
	$conflict_users = db_query("SELECT * FROM {user_conflicts} WHERE conflict_on = 'mail'");

	$transaction = db_transaction();
	$cantmerge = 0;

	foreach($conflict_users as $cuser) {
		try {
			$udetails = db_query("SELECT * FROM {users} WHERE mail = :mail", array(":mail" => $cuser->mail))->fetchAssoc();
			$count = db_update('users')
						->condition('mail', $cuser->mail)
						->fields(array('pass' => $cuser->pass, 'name' => $cuser->name))
						->execute();
			$deleted = db_delete("user_conflicts")
						->condition("mail", $cuser->mail)
						->execute();
			$usr = get_object_vars($cuser);
			unset($usr["id"]);
			$resolved = db_insert("user_resolved")
						->fields($usr)
						->execute();

			if($count == 1 && $deleted == 1) {
				printf("updated user matching %s and %s (name and pass)\n", $udetails["name"], $udetails['mail']);
				printf("to new name: %s from conflict user with mail %s\n", $cuser->name, $cuser->mail);
			} else {
				$transaction->rollback();
				die("could not update user "  . $cuser->name . " " . $cuser->mail . "\n");
			}
		}
		//This is rare but does happen, on accounts with certain name/mail combinations
		//already exsisting in each db. (3 out of 15k isnt so bad)
		catch(PDOException $e) {
			$cantmerge++;
			printf("%s %s\n", $cuser->name, $cuser->mail);
			print_r(get_object_vars($e));
		}
	}
	printf("could not merge %d users on mail conflict\n", $cantmerge);
}

function merge_names() {
	print("merging users that match on NAME\n");
	$conflict_users = db_query("SELECT * FROM {user_conflicts} WHERE conflict_on = 'name'");

	$transaction = db_transaction();
	$cantmerge = 0;

	foreach($conflict_users as $cuser) {
		try {
			//change the user already in the db.
			$udetails = db_query("SELECT * FROM {users} WHERE name = :name", array(":name" => $cuser->name))->fetchAssoc();
			$count = db_update('users')
						->condition('name', $cuser->name)
						->fields(array('name' => $udetails['mail']))
						->execute();

			if($count) {
				$udetails['uid'] = db_next_id(db_query("SELECT MAX(uid) FROM {user_name_changes}")->fetchField());
				db_insert("user_name_changes")
						->fields($udetails)
						->execute();
			}

			$usr = get_object_vars($cuser);
			$usr["uid"] = db_next_id(db_query("SELECT MAX(uid) FROM {users}")->fetchField());
			$usr2 = $usr;
			unset($usr["imported_from"]);
			unset($usr["orig_uid"]);
			unset($usr["id"]);
			unset($usr["conflict_on"]);
			unset($usr["double_conflict"]);
			db_insert("users")
						->fields($usr) //wont work prob.
						->execute();
			$deleted = db_delete("user_conflicts")
						->condition('name', $cuser->name)
						->condition('mail', $cuser->mail)
						->execute();
			$resolved = db_insert("user_resolved")
						->fields($usr2)
						->execute();
			if($deleted == 1) {
				printf("updated user matching %s and %s (name) to their email\n", $udetails["name"], $udetails['mail']);
				printf("inserted user %s %s in their place\n", $cuser->name, $cuser->mail);
			} else {
				//$transaction->rollback();
				//die("could not update user " . $cuser->name . " " . $cuser->mail . "\n");
				$cantmerge++;
				printf("%s %s\n", $cuser->name, $cuser->mail);
			}
		}
		catch(PDOException $e) {
			$cantmerge++;
			printf("%s %s\n", $cuser->name, $cuser->mail);
			printf("%s\n", $e->getMessage());
		}
	}
	printf("could not merge %d users on name conflict\n", $cantmerge);
}
?>
