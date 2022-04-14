<?php

/*
  (c) 2022 Chris Royle
    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <https://www.gnu.org/licenses/>.

*/

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
