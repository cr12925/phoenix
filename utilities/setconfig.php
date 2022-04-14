<?php

include_once 'conf.php';
include_once 'ip_lib.php';

if (count($argv) < 3) 
{

	// Malformed command line

	print "
$argv[0] usage:

	$argv[0] <variable-name> <value>

";
	exit();
}

$varname = strtolower($argv[1]);
$varvalue = $argv[2];

$r = dbq("replace into config(config_var, config_val) values(?, ?)", "ss", $varname, $varvalue);
if ($r['success']) printf("Success.\n"); else printf("Failed.\n");

?>
