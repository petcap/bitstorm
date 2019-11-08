<?php

 /*
  * Bitstorm - A small and fast Bittorrent tracker
  * Written by Peter Caprioli, 2008
  */

 /*************************
 ** Configuration start **
 *************************/

//Enable debugging?
//This allows anyone to see the entire peer database by appending ?debug to the announce URL
define('__DEBUGGING_ON', false);

//How often should clients pull from the server? (Seconds)
define('__INTERVAL', 300);

//What's the minimum interval a client may pull the server? (Seconds)
//Some bittorrent clients does not obey this
define('__INTERVAL_MIN', 60);

//How long should we wait for a client to re-announce after the last
//announce expires? (Seconds)
define('__CLIENT_TIMEOUT', 60);

//Skip sending the peer id if client does not want it?
//Hint: Should be set to true
define('__NO_PEER_ID', true);

//Should seeders not see each others?
//Hint: Should be set to true
define('__NO_SEED_P2P', true);

//Where should we save the peer database
//On Linux, you should use /dev/shm as it is very fast.
//On Windows, you will need to change this value to some
//other valid path such as C:/Peers.txt
define('__LOCATION_PEERS', '/dev/shm/Bittorrent.Peers');

//In case someone tries to access the tracker using a browser,
//redirect to this URL or file
define('__REDIR_BROWSER', '/');

 /***********************
 ** Configuration end **
 ***********************/

//Send response as text
header('Content-type: text/plain');

//Require TLS for all connections
//if (!isset($_SERVER['HTTPS'])) {
//	die(track("This tracker requires HTTPS"));
//}

//Bencode data, returns a bencoded dictionary
//You may go ahead and enter custom keys in the dictionary in
//this function if you'd like.
function track($list, $interval=60, $min_ival=0) {
	if (is_string($list)) { //Did we get a string? Return an error to the client
		return 'd14:failure reason'.strlen($list).':'.$list.'e';
	}
	$p = ''; //Peer directory
	$c = $i = 0; //Complete and Incomplete clients
	foreach($list as $d) { //Runs for each client
		if ($d[7]) { //Are we seeding?
			$c++; //Seeding, add to complete list
			if (__NO_SEED_P2P && is_seed()) { //Seeds should not see each others
				continue;
			}
		} else {
			$i++; //Not seeding, add to incomplete list
		}
		//Do some bencoding

		$pid = '';

		if (!isset($_GET['no_peer_id']) && __NO_PEER_ID) { //Shall we include the peer id
			$pid = '7:peer id'.strlen($d[1]).':'.$d[1];
		}

		$p .= 'd2:ip'.strlen($d[0]).':'.$d[0].$pid.'4:porti'.$d[2].'ee';
	}
	//Add some other paramters in the dictionary and merge with peer list
	$r = 'd8:intervali'.$interval.'e12:min intervali'.$min_ival.'e8:completei'.$c.'e10:incompletei'.$i.'e5:peersl'.$p.'ee';
	return $r;
}

//Find out if we are seeding or not. Assume not if unknown.
function is_seed() {
	if (!isset($_GET['left'])) {
		return false;
	}
	if ($_GET['left'] == 0) {
		return true;
	}
	return false;
}

/*
* Database functions, store and load array from disk
* Very primitive, but does support file locking so we should never get any
* collisions
*/

//Save database to file
function db_save($data) {
	$b = serialize($data);
	$h = @fopen(__LOCATION_PEERS, 'w');
	if (!$h) { return false; }
	if (!@flock($h, LOCK_EX)) { return false; }
	@fwrite($h, $b);
	@fclose($h);
	return true;
}

//Load database from file
function db_open() {
	$p = '';
	$m = '';
	$h = @fopen(__LOCATION_PEERS, 'r');
	if (!$h) { return false; }
	if (!@flock($h, LOCK_SH)) { return false; }
	while (!@feof($h)) {
		$p .= @fread($h, 512);
	}
	@fclose($h);
	return unserialize($p);
}

//Check if DB file exists, otherwise create it
function db_exists($create_empty=false) {
	if (file_exists(__LOCATION_PEERS)) {
		return true;
	}
	if ($create_empty) {
		if (!db_save(array())) {
			return false;
		}
		return true;
	}
	return false;
}

//Default announce time
$interval = __INTERVAL;

//Minimal announce time (does not apply to short announces)
$interval_min = __INTERVAL_MIN;

//Did we get any parameters at all?
//Client is  probably a web browser, do a redirect
if (empty($_GET)) {
	header('Location: '.__REDIR_BROWSER);
	die();
}

//Create database if it does not exist
db_exists(true) or die(track('Unable to create database'));
$d = db_open();

//Do we want to debug? (Should not be used by default)
if (isset($_GET['debug']) && __DEBUGGING_ON) {
	echo 'Connected peers:'.count($d)."\n\n";
	print_r($d);
	die();
}

//Did we get a failure from the database?
if ($d === false) {
	die(track('Database failure'));
}

//Do some input validation
function valdata($g, $must_be_20_chars=false) {
	if (!isset($_GET[$g])) {
		die(track('Missing one or more arguments'));
	}
	if (!is_string($_GET[$g])) {
		die(track('Invalid types on one or more arguments'));
	}
	if ($must_be_20_chars && strlen($_GET[$g]) != 20) {
		die(track('Invalid length on '.$g.' argument'));
	}
	if (strlen($_GET[$g]) > 128) { //128 chars should really be enough
		die(track('Argument '.$g.' is too large to handle'));
	}
}

//Inputs that are needed, do not continue without these
valdata('peer_id', true);
valdata('port');
valdata('info_hash', true);

//Use the tracker key extension. Makes it much harder to steal a session.
if (!isset($_GET['key'])) {
	$_GET['key'] = '';
}
valdata('key');

//Do we have a valid client port?
if (!ctype_digit($_GET['port']) || $_GET['port'] < 1 || $_GET['port'] > 65535) {
	die(track('Invalid client port'));
}

//Array key, unique for each client and torrent
$sum = sha1($_GET['peer_id'].$_GET['info_hash']);

//Make sure we've got a user agent to avoid errors
//Used for debugging
if (!isset($_SERVER['HTTP_USER_AGENT'])) {
	$_SERVER['HTTP_USER_AGENT'] = ''; //Must always be set
}

//When should we remove the client?
$expire = time()+$interval;

//Have this client registered itself before? Check that it uses the same key
if (isset($d[$sum])) {
	if ($d[$sum][6] !== $_GET['key']) {
		sleep(3); //Anti brute force
		die(track('Access denied, authentication failed'));
	}
}

//Add/update the client in our global list of clients, with some information
$d[$sum] = array($_SERVER['REMOTE_ADDR'], $_GET['peer_id'], $_GET['port'], $expire, $_GET['info_hash'], $_SERVER['HTTP_USER_AGENT'], $_GET['key'], is_seed());

//No point in saving the user agent, unless we are debugging
if (!__DEBUGGING_ON) {
	unset($d[$sum][5]);
} elseif (!empty($_GET)) { //We are debugging, add GET parameters to database
	$d[$sum]['get_parm'] = $_GET;
}

//Did the client stop the torrent?
//We dont care about other events
if (isset($_GET['event']) && $_GET['event'] === 'stopped') {
	unset($d[$sum]);
	db_save($d);
	die(track(array())); //The docs says its OK to return whatever we want when the client stops downloading,
                       //however, some clients will complain about the tracker not working, hence we return
                       //an empty bencoded peer list
}

//Check if any client timed out
foreach($d as $k => $data) {
	if (time() > $data[3] + __CLIENT_TIMEOUT) { //Give the client some extra time before timeout
		unset($d[$k]); //Client has gone away, remove it
	}
}

//Save the client list
db_save($d);

//Compare info_hash to the rest of our clients and remove anyone who does not have the correct torrent
foreach($d as $id => $info) {
	if ($info[4] !== $_GET['info_hash']) {
		unset($d[$id]);
	}
}

//Remove self from list, no point in having ourselfes in the client dictionary
unset($d[$sum]);

//Balance out the interval
$interval += rand(0, 10);

//Bencode the dictionary and send it back
die(track($d, $interval, $interval_min));
?>
