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
include_once 'cetlib.php';

if (	(count($argv) < 5) or
	(!preg_match('/^[1-9][0-9]{0,9}$/', $argv[2])) or
	(!preg_match('/^[A-Z][A-Z0-9\.]*$/i', $argv[3])) or
	(!preg_match('/^\d+$/', $argv[4]))
   )
{

	// Malformed command line

	print "
$argv[0] usage:

	$argv[0] <source url> <frame page number> <presentation filename> <ip_id>

	Note that <frame page number> is the first page number to be used.
	On files which span more than 26 frames (i.e. a-z), the next page
	number in sequences will be used, starting again at 'a' and so on.
	Thus specifying (e.g.) 100100 as the starting frame page number
	may mean that on a maximum 999 frame encode (per the spec)
	frames 100100a to 100137k may be overwritten.

	In relation to the 'a' page, the utility will put the intro
        data for the download at character 0 of the last line.

	All of the routes for those pages will be removed. On each
	'z' frame, a '0' route to the next page will be added, complying
	with the specification.

	The 'presentation filename' will be shifted to uppercase and 
	presented on the first frame as the filename for the downloaded
	file.

	The source url may be a local file.
	
	ip_id is the ip_id to be inserted into the frame database,
	so that it must be from the information_provider table in
	the database.

";
	exit();
}

$base_page = $argv[2];
$presentation_name = strtoupper($argv[3]);

$page_count = 0;

$filepos = 0;

$rendered = array();

// Get the file

if (!($tmphandle = tmpfile()))
{
	print "Couldn't get a temporary filename. Quitting.\n";
	exit();
}

// Copy the file to the temp file

if (!($infile = fopen($argv[1], "r")))
{
	print "Couldn't open input file. Quitting.\n";
	exit();
}

while (!feof($infile))
{
	$data = fread($infile, 8192);
	fwrite($tmphandle, $data);
}

fclose($infile);

fseek($tmphandle, 0);
$statinfo = fstat($tmphandle);

while (!feof($tmphandle) && ($page_count < 999))
{
	$l = 880 - 7 - 5; // |A|Gx|I at the start and |Znnn at the end
/*
	if ($page_count == 0)
		$l -= (5 + strlen($presentation_name)); // 5 = |Lnnn
*/

	$data = cet_convert ($tmphandle, $statinfo['size'], $filepos, $l);

	$rendered[$page_count+1] = $data['converted'];

	printf("Frame $page_count - Started at %04X (%d) - Converted %04X (%d)\n", $filepos, $filepos, $data['processed'], $data['processed']);

	$filepos += $data['processed'];
	
	$page_count++;

}

//print "First character of \$rendered[0] is...".ord($rendered[0][0])." - ".substr($rendered[0], 0, 5)." ... \n\n";

$first_page_header = $presentation_name."|L".sprintf("%03d", $page_count);

$rendered[0] = $first_page_header; // No data on frame 0 - we are just inserting the string into the existing frame

if (!($stmt = mysqli_prepare($dbh, "INSERT INTO frame(frame_pageno, frame_subframeid, frame_content, frame_flags, ip_id) values (?, ?, to_base64(?), ?, ?)")))
{
	print "Statement preparation failed: ".mysqli_error($dbh);
	exit(0);
}

$empty_string = "";
$p = $base_page;
$sf = 'a';
$pagedata = '';

mysqli_stmt_bind_param($stmt, "isssi", $p, $sf, $pagedata, $empty_string, $argv[4]);

dbq_starttransaction();

for ($count = 0; $count < count($rendered); $count++) 
{
	$p = intval($count / 26) + $base_page;
	$sf = chr(($count % 26) + ord('a')); 

	$rendered[$count] = "|G".$sf."|I".$rendered[$count];

	$checksum = 0;

	for ($cc = 0; $cc < strlen($rendered[$count]); $cc++)
		$checksum ^= ord($rendered[$count][$cc]);

	$pagedata = $rendered[$count] = sprintf("|A%s|Z%03d", $rendered[$count], $checksum);

	$r = dbq("select frame_id from frame where frame_pageno=$p and frame_subframeid='".$sf."' and !find_in_set('unpublished', frame_flags)");

	$frame_ids = array();

	if ($r['result'])
	{
		while ($finfo = @mysqli_fetch_assoc($r['result']))
			$frame_ids[] = $finfo['frame_id'];
		@mysqli_free_result($r['result']);
	}

/*
	else
	{
		print "Cannot find frame IDs for frame ".($count + 1).". Quitting.\n";
		//dbq_rollback();
		exit();
	}
*/

	if (count($frame_ids) > 0 and ($count > 0)) // Don't delete the 'a' frame...
	{
		dbq("delete from frame where frame_id in (".implode(',',$frame_ids).")");
		dbq("delete from frame_key where frame_id in (".implode(',',$frame_ids).")");	
	}

	if ($count > 0) // not first frame
	{
		mysqli_stmt_execute($stmt);

		if (mysqli_errno($dbh))
		{
			print "Insert failed on frame $p$sf: ".@mysqli_error($dbh);
			dbq_rollback();
			exit();
		}

		$fid = mysqli_insert_id($dbh);
	}
	else
	{
		if (count($frame_ids) == 0)
		{
			print "Failed to find $base_page"."a\n";
			exit(0);
		}

		// Just insert rendered[0] at position character (21*40) in the frame
		$frame_data = str_pad ("", 880);
		$r = dbq("select from_base64(frame_content) as frame_content from frame where frame_id=".$frame_ids[0]);
		if ($r['result'])
		{
			$d = @mysqli_fetch_assoc($r['result']);
			@mysqli_free_result($r['result']);
			$frame_data = $d['frame_content'];
			$frame_data = str_pad($frame_data, 880, " ");
		
			for ($incount = 0; $incount < strlen($rendered[0]); $incount++)
				$frame_data[840+$incount] = $rendered[0][$incount];

			if (!($ustmt = mysqli_prepare($dbh, "UPDATE frame set frame_content=to_base64(?) where frame_id = ?")))
			{
				print "Update statement preparation failed: ".mysqli_error($dbh);
				exit(0);
			}

			mysqli_stmt_bind_param($ustmt, "si", $frame_data, $frame_ids[0]);

			mysqli_stmt_execute($ustmt);
		}
		else
		{
			if (mysqli_errno($dbh))
			{
				print "Retrieve failed on frame $p$sf: ".@mysqli_error($dbh);
				dbq_rollback();
				exit();
			}
		}
			
	}

	//print "$p$sf: ".$rendered[$count]."\n\n";
	//print $query."\n\n";

	if (($sf == 'z')  && ($count < count($rendered))) // Insert a 0 route to next page
	{
		$query = "
			insert into frame_key
				(frame_id, frame_keypress, frame_key_action, frame_key_metadata1, frame_key_flags) 
			values ($fid, '0', 'Page', ".($p + 1).", '')";
		dbq($query);
	}

}

dbq_commit();

fclose($tmphandle);


?>
