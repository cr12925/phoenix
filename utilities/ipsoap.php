<?php

include_once 'conf.php';
include_once 'ip_lib.php';

if (count($argv) < 3) 
{

	// Malformed command line

	print "
$argv[0] usage:

	$argv[0] <IP user ID> <SOAP URL>

";
	exit();
}

$userid = $argv[1];
$url = $argv[2];

// Check User exists

if	($real_userid = verify_userid($userid))
{

	// Now see if there is an IP with that user ID

	$r = dbq("select * from information_provider where user_id = ?", "i", $real_userid);
	if ($r['success'])
	{
		if ($r['numrows'] == 1)
		{
			$data = @mysqli_fetch_assoc($r['result']);
			dbq("update information_provider set ip_url = ? where ip_id = ?", "si",
				$url, $data['ip_id']);
		}
		else	printf("Cannot find an IP with that user ID.\n");
		@mysqli_free_result($r['result']);
	}
	else	printf("Database problem whilst checking information provider table.\n");	


}
else
	printf("Cannot find user ID %d\n", $userid);


?>
