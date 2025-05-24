<?php

// Define a list of scripts each with a unique upper-case reference. 
// The script can then be called by invoking a system IP call SCRIPT_XXX where XXX
// is the unique reference.
// The definition (see example) requires a list of frame variable which must be
// present, and the command line of the script (with parameters). Phoenix will
// substitute _VARIABLENAME_ for the value of the variable

/* Example
$sys_scripts = array (
	"MYSCRIPT" => array (
		"vars" => "USERNAME, PASSWORD, HOST",
		"scriptpath" => "/usr/bin/whatever -u _USERNAME_ -p _PASSWORD_ _HOST_"
	)
);
 */

$sys_scripts = array ();
