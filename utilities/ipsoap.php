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
