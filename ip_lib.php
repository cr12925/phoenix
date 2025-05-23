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

// Library providing information provider functions

// Validate a string put in by user on a response frame which has to be validated
function validate_rf_data($ip, $frame_id, $fieldname, $string)
{

	$collect_data = ip_function($ip, "VALIDATE", $frame_id, array(	"frame_id" => $frame_id, 
						     	"fieldname" => $fieldname, 
							"data" => $string), $userdata->user_id.userid_checkdigit($userdata->user_id));

	// We'll check it here. Eventually.

	return true;

}

// Actually call the IP over http/https and see what the answer is.

// NB frame_id is actually pageno.subframeid here.

function ip_function($ip, $function, $frame_id, $data_array)
{

	global $userdata;

	debug ("ip_function($ip, $function, $frame_id, ...) called");

	// Is this a msg send? If so, force use of system SUBMIT

	$force_system = 0;
	$r = dbq("select mb_id, mb_flags from msgboard where frame_pageno_send = ?", "s", $frame_id);
	if ($r['success'])
	{
		if ($r['numrows'] == 1)
			$force_system = 1;
		dbq_free($r);
		//@mysqli_free_result($r['result']);
	}

	$r = dbq("select ip_use_sysip from information_provider where ip_id = ?", "i", $ip);
	if ($r['success'])
	{
		if ($r['numrows'] == 1)
		{
			$row = @mysqli_fetch_assoc($r['result']);
			$force_system = $row['ip_use_sysip'];
		}
		dbq_free($r);
	}

	if ($force_system) debug ("Forcing use of system IP call");
	// If it's the system IP (1) then we check to see if there is a defined function sysip_NNN and call that instead of HTTP
	
	if (($force_system or $ip == 1 or ($function == "LOGIN" or $function == "CHPW")) && function_exists("sysip_".$function)) // We always process LOGIN locally against the local database. If an IP wants to use their own login function, they need to specify a different function on the frame.
	{
		$function_name = "sysip_".$function;
		debug ("Calling internal function ".$function_name);
		$result = $function_name($frame_id, $data_array, $userdata->user_id.userid_checkdigit($userdata->user_id));
	}
	else if ($ip == 1)
	{	debug ("Calling internal IP function SUBMIT");
		$result = sysip_SUBMIT($frame_id, $data_array, $userdata->user_id.userid_checkdigit($userdata->user_id));
	}
	else
	{
		debug ("IP $ip Function $function called");
		// Look up the IP's URL
		$query = "select ip_url, ip_location, ip_key from information_provider where ip_id = ?";
		$r = dbq($query, "i", $ip);
		if ($r['result'])
		{
			$row = @mysqli_fetch_assoc($r['result']);
			dbq_free($r);
			//@mysqli_free_result($r['result']);
			
			try {
				debug ("SOAP request to ".$row['ip_url']);
				$client = new SoapClient(NULL, array("uri" => $row['ip_url'], "location" => $row['ip_location']));
				$result = $client->$function($row['ip_key'], $frame_id, $data_array, $userdata->user_id.userid_checkdigit($userdata->user_id));
				if ($result == array(false))
					$result = array(IPR_BADDATA);
			}
			catch (SoapFault $e)
			{
				debug ($e);
				global $dbh;
				log_event("SoapFault", $ip, @mysqli_escape_string($dbh, $e));
				$result = array(IPR_CALLFAILURE);
			}
		}
		else
			$result = array(IPR_CALLFAILURE);
	}

	return ($result);

}

function ip_dynamic($ip, $user_id, $frame_pageno, $subframe_id)
{

	global $dbh; 

	if ($ip == 1)
		$data = sysip_DYNAMIC($user_id, $frame_pageno, $subframe_id);
	else
		$data = ip_function($ip, 'DYNAMIC', $frame_pageno.$subframe_id, array());


	// Now convert area *name* into area_id

	$query = "
select	area_id
from	area
where	area_name=?
and	ip_id=?";

	$r = dbq($query, "si", $data['area_name'], $ip);

	if ($r['result'])
	{
		if ($r['numrows'] == 1)
		{
			$row = @mysqli_fetch_assoc($r['result']);
			$data['area_id'] = $row['area_id'];
			debug ("Area name provided (".$data['area_name'].") matched to this IP's area ID ".$row['area_id']);
		}
		else
		{
			debug ("Area name provided (".$data['area_name'].") did not match this IP's area ID");
			$data['area_id'] = 1; // Public area if this area isn't ours
		}

		dbq_free($r);
		//@mysqli_free_result($r['result']);
		
	}
	else
	{	
		global $dbh;
		debug ("Query error finding area ID for name provided ".$data['area_name'].' '.@mysqli_error($dbh));
		$data['area_id'] = -1; // Non-existent area because our query failed so protect the IP
	}

	unset($data['area_name']);

	return $data;
}

// Internal IP functions
	
function sysip_SUBMIT($frame, $data, $userid)
{

	global $userdata;

	preg_match('/^([1-9][0-9]*)([a-z])$/', $frame, $matches);
	$pageno = $matches[1];
	$subframeid = $matches[2];

	$ret = array (IPR_TRYAGAIN, $frame);

	// See if this is a message post
	$r = dbq("select mb_id, mb_flags from msgboard where frame_pageno_send = ?", "s", $frame);
	if ($r['success'])
	{
		if ($r['numrows'] == 1)
		{
			$mb_data = @mysqli_fetch_assoc($r['result']);
			dbq_free($r);
			//@mysqli_free_result($r['result']);
			$mb_flags = $mb_data['mb_flags'];

			if (isset($data['USER']))
				$user_id = $data['USER'];
			else if (preg_match('/personal/', $mb_flags))
				$user_id = false;
			else // Non-personal board and no USER given - broadcast
				$user_id = null;

			if ($user_id !== false)
			{
				if (isset($data['SUBJECT']) and isset($data['TEXT']))
				{
					if (msg_post($frame, $user_id, $data['SUBJECT'], $data['TEXT']))
					{
						show_prompt ($userdata->conn, "Message sent.");
						$ret = array(IPR_GOTOFRAME, $userdata->frame_previous);
					}
					else	{
						show_error ($userdata->conn, "Message sending error.");
						$ret = array(IPR_TRYAGAIN, $frame);
					}
				}
				else
				{
					show_error ($userdata->conn, "Bad message - no subj or text.");
					$ret = array(IPR_GOTOFRAME, $userdata->frame_previous);
				}
			}
			else
			{
				show_error($userdata->conn, "Bad recipient provided.");
				$ret = array(IPR_GOTOFRAME, $userdata->frame_previous);
			}

		}

	}

	switch (strtolower($frame))
	{
		case "170a": 
		{
			// Populate local state
			$userdata->nr_stn_srch = strtoupper($data['STN']);
			$userdata->nr_aord = strtoupper($data['ARRDEP']);
			$userdata->nr_filter_stn = null;
			if (!preg_match('/^\s*$/', $data['FILTER']))
				$userdata->nr_filter_stn = strtoupper($data['FILTER']);
			$ret = array(IPR_GOTOFRAME, "171"); break;
		}
	}

	return $ret;
}

function verify_userid($i)
{

        $checkdigit = substr($i, -1);
        $userid = substr($i, 0, strlen($i)-1);

        $verifydigit = 0;

        for ($count = 0; $count < strlen($userid); $count++)
                $verifydigit += $userid[$count];
        $verifydigit %= 10;

	if ($verifydigit != $checkdigit)
		return false;
	else
		return $userid;

}

// System Login
function sysip_LOGIN($frame_id, $data)
{
	global $userdata, $config;

	debug ("sysip_LOGIN() called");

	$userid = intval($data['USERID']);
	$password = $data['PASSWORD'];
	$userid = verify_userid($userid);
	if (!$userid)
	{
		show_error($userdata->conn, "Bad username / password.");
		sleep(2);
		return (array(IPR_TRYAGAIN, $config['startpage']));
	}

	if ($userdata->user_id) // Already logged in
	{
		show_error($userdata->conn, "LOGIN() unavailable whilst logged in");
		sleep(2);
		return (array(IPR_UNKNOWNFUNCTION, $userdata->homepage));
	}

	debug ("Attempted login by user ID $userid");
	$r = dbq("select *, date_format(user_last_login, '%a %d/%m/%y %H:%i') as ull from user where user_id=?", "i", $userid);
	if ($r['result'])
	{
		if ($row = @mysqli_fetch_assoc($r['result']))
		{
			//printf ("\n\n***Hash: ".$row['user_pw']." / Pw: ".$data['PASSWORD']." / result: ".(password_verify($data['PASSWORD'], $row['user_pw']) ? "Correct" : "Incorrect")."\n\n");

			if (password_verify($data['PASSWORD'], $row['user_pw']))
				{
				// Successful login
				$userdata->user_id = $row['user_id'];
				if ($row['user_homepage'])
					$userdata->homepage = $row['user_homepage'];
				else
					$userdata->homepage = $config['homepage'];
				$userdata->user_name = $row['user_realname'];
				if (!$userdata->previous_login_time = $row['ull'])
					$userdata->previous_login_time = "Never";
				dbq_starttransaction();
				dbq("update user set user_last_login=now(), user_idle_since=NOW(), user_last_logoff=null, user_last_node=?", "i", $userdata->node_id);
				dbq_commit();
				dbq_free($r);
				//@mysqli_free_result($r['result']);
	
				log_event("Login", $userdata->user_id, "Success");
				// See if we are an IP
				$userdata->ip_base = $userdata->ip_base_len = null;
				$s = dbq("select ip_base, ip_id from information_provider where user_id=?", "i", $userdata->user_id);
				if ($s['result'])
				{
					if ($row = @mysqli_fetch_assoc($s['result']))
					{
						$userdata->ip_base = $row['ip_base'];
						$userdata->ip_id = $row['ip_id'];
					}
					dbq_free($s);
					//@mysqli_free_result($s['result']);
				}

				/* Load list of other IPs we are effectively owner for */

				$userdata->secondary_ip_id = array();
				$s = dbq("select ip_id from ip_user where user_id=?", "i", $userdata->user_id);
				if ($s['result'])
				{
					while ($row = @mysqli_fetch_assoc($s['result']))
					{
						$userdata->secondary_ip_id[$row['ip_id']] = $row['ip_id'];
					}
					dbq_free($s);
				}

				return (array(IPR_GOTOFRAME, $userdata->homepage));
			}

		}

		log_event("Login", 0, "Failure");
		// If we get here, there wasn't a row - so probably wrong username / password
		dbq_free($r);
		//@mysqli_free_result($r['result']);
		show_error($userdata->conn, "Bad username / password.");
		sleep(2);
		return (array(IPR_TRYAGAIN, $config['startpage']));
		
	}
		
	show_error($userdata->conn, "User database error. Disconnecting.");
	sleep(2);
	logoff();
}

// RSS

if (file_exists('Feed.php'))
	require_once("Feed.php");

function get_url_body($url, $id)
{

	$file = file_get_contents($url);
	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	$dom->loadHTML($file);
	$bodies = $dom->getElementsById($id);
	if ($bodies)
		return $dom->saveXML($bodies);
	else	return false;

}

function phoenix_getrss($url)
{
	if (substr($url, 0, 5) == "ATOM:")
		return Feed::loadAtom(substr($url, 5, strlen($url)-5));
	else
		return Feed::loadRss($url);
}

function phoenix_display_rss($url, $page_base, $page_no, $subframeid, $start_row, $end_row, $start_item, $key_colour, $text_colour)
{

	global $userdata;
	$rss = phoenix_getrss($url);

	$frame_data = array(	'frame_content' => "Bad frame",
				'frame_next' => 0,
				'frame_routes' => array(),
				'frame_response' => array(),
				'area_name' => 'PUBLIC' );

	$framedata = ""; // Note, no underscore - this is the text of the frame; frame_data is the array with everything in it.

	if ($rss)
	{
		// Do we want the stories index?
		if ($page_base == $page_no)
		{
			// If so, which stories index sub-frame?
			$start_story = 4 * (ord($subframeid) - ord('a'));
			$key = "1";
	
			$framedata = sprintf("%-40s%80s", chr(141).chr(132).chr(157).chr(131).text_center(strip_tags(html_entity_decode($rss->title)),32)."  ".chr(156)." ", "");
			for ($counter = $start_story; $counter < $start_story+4; $counter++)
			{
				$item = $rss->item[$counter];
				if ($item)
				{
					$lines = text_to_lines($item->title, 37);
					for ($lcount = 0; $lcount < 3; $lcount++)
					{
						if (isset($lines[$lcount]))
						{
							//$this_line = sprintf(chr(130).($lcount == 0 ? $key : ' ').chr(134)."%-37s", iconv("UTF-8", "ISO-8859-1//IGNORE", $lines[$lcount]));
							// At the moment, story extraction & rendering doesn't work
							$this_line = sprintf(chr(130).(' ').chr(134)."%-37s", iconv("UTF-8", "ISO-8859-1//IGNORE", $lines[$lcount]));
						}
						else	$this_line = sprintf("%40s", "");
						$framedata .= $this_line;
					}
					$framedata .= sprintf("%40s", "");
					//$frame_data['frame_routes'][strval($key)] = array( 'Page',
							//intval(strval($page_base).sprintf("%02d",(ord($subframeid)-ord('a'))).strval($key)),
							//"" );
					$key++;
				}
				else
					break;
			}
			$bottom_string = chr(130)."0:".chr(131)."Headlines".chr(130)."9:".chr(131)."Main index.";
			if ($counter != sizeof($rss->item))
				$bottom_string .= chr(135)."_:".chr(131)."More";
			$framedata .= sprintf("%-40s%-40s", "", $bottom_string);
			$frame_data['frame_routes']['0'] = array('Page', substr(strval($page_no), 0, 2)."0", "");
			$frame_data['frame_routes']['9'] = array('Page', 1, "");
		}
		else
		{
			// Story render
			// Story number is determined by the last three digits of the frame number, and
			// Then the start line is determined by the sub-frame ID
			// So, e.g., 101001c is:
			//		3rd page (c)
			//		of story 1 (digit before the subframe ID)
			//		on page 00 (i.e. first page) 
			//		of the items at page 101

			$subpagenumber = ord($subframeid) - ord('a');
			$storypage = substr(strval($page_no), -3, 2);
			$storynumber = substr(strval($page_no), -1, 1);
			$storynumber_index = ($storypage * 4) + $storynumber - 1; // -1 because the index starts at 0

			$item = $rss->item[$storynumber_index];

			$title = $item->title;
// EDIT HERE
			$html = strip_tags(html_entity_decode(get_url_body($item->link, "nothing")));
			
			$title_lines = text_to_lines($title, 39);
			$html_lines = text_to_lines($html, 39);

			$framedata = sprintf("%-40s", chr(132).chr(157).chr(131).text_center(strip_tags(html_entity_decode($rss->title)),34)." ".chr(156)." ");
			// Add in the news title
			for ($count = 0; $count < 4; $count++)
					$framedata .= sprintf("%-40s", (isset($title_lines[$count]) ? chr(134).$title_lines[$count] : ""));
			$framedata .= sprintf ("%-40s", text_center($item->pubDate, 40));
			$framedata .= sprintf ("%-40s", ""); // Blank line to story
			$start_line = $subpagenumber * 14;
			if (sizeof($html_lines) < $start_line) // We are off the end of the story
				$framedata .= sprintf("%-40s", chr(129)."No more story content available.");
			else
				for ($count = $start_line; $count < $start_line + 14; $count++)
					$framedata .= sprintf("%-40s", $text_colour.(isset($html_lines[$count]) ? $html_lines[$count] : ""));
			
			if (($start_line + 14) < sizeof($html_lines))
				$add_hash = chr(130)."_:".chr(131)."More";
			else
				$add_hash = "";

			$framedata .= sprintf("%-40s%-40s", "", $key_colour."0:".chr(131)."Headlines,".chr(130)."9:".chr(131)."Home".$add_hash);

			$frame_data['frame_routes']['0'] = array('Page', substr(strval($page_no), 0, 3), "");
			$frame_data['frame_routes']['9'] = array('Page', 1, "");

		}
		

		$framedata = substr($framedata, 0, 880);
		if (strlen($framedata) < 880)
		{
			$shortfall = 880 - strlen($framedata);
			$framedata .= sprintf("%".$shortfall."s", "");
		}

		$frame_data['frame_content'] = $framedata;
	}	

	return $frame_data;

}

function sysip_DYNAMIC($user_id, $pageno, $subframeid)
{

	global $userdata;

	debug ("sysip_DYNAMIC($user_id, $pageno, $subframeid) called");

	$frame_data = array(	'frame_content' => "Bad frame",
				'frame_next' => 0,
				'frame_routes' => array(),
				'frame_response' => array(),
				'area_name' => 'PUBLIC' );

	if ($pageno == 26) // FDLIST page
	{
		$range_start = (ord($subframeid) - ord('a'))*20;
		$range_end = $range_start+20;
		$r = dbq("
SELECT 	dyn_start,
	dyn_end
FROM	dynamic,
	information_provider
WHERE	dyn_start rlike ip_base_regex
AND	information_provider.ip_id = ?
ORDER BY	dyn_start	ASC
LIMIT	?, ?", 	"iii", $userdata->ip_id, $range_start, $range_end);

		$frame_data['frame_content'] = sprintf("%-40s%-40s%-40s", viewdata_to_chr(VTBLU).viewdata_to_chr(VBKGNEW).viewdata_to_chr(VTWHT)."Current dynamic frame ranges", "", viewdata_to_chr(VTCYN)."     Start          End"); 

		if ($r['success'])
		{
			while ($data = @mysqli_fetch_assoc($r['result']))
				$frame_data['frame_content'] .= sprintf("%-40s", sprintf(" %10s   %10s", $data['dyn_start'], $data['dyn_end']));
			dbq_free($r);
			//@mysqli_free_result($r['result']);	
		}
		$frame_data['frame_content'] = base64_encode($frame_data['frame_content']);
		return $frame_data;
	}

	if ($pageno == 171) // National Rail Departure / Arrival Boards
	{
		if (isset($userdata->nr_client) && isset($userdata->nr_stn_srch)) // Only populate if we have some data, otherwise just let the routine reply 'Bad frame' as above.
		{
			$frame_data['frame_content'] = get_nr_board($userdata->nr_stn_srch,
							$userdata->nr_aord,
							((!preg_match('/^\s*$/', $userdata->nr_filter_stn)) ? $userdata->nr_filter_stn : null)
				);
			//$frame_data['frame_content'] = base64_encode($frame_data['frame_content']);
			$frame_data['frame_routes']['0'] = array('Page', 0, "");
			$frame_data['frame_routes']['9'] = array('Page', 170, "");
							
		}

	}

	/* TNMOC FUDGE */

	if ($pageno == 6854110) // National Rail Departures from Bletchley
	{
		$frame_data['frame_content'] = get_nr_board("BLY", "D", null);
		$frame_data['frame_routes']['0'] = array('Page', 0, "");
		$frame_data['frame_routes']['9'] = array('Page', 171, "");
	}

	if (strlen(strval($pageno)) >= 6)
		$page_base = intval(
				substr(			
					strval($pageno), 
					0, 
					strlen(strval($pageno))-3
				)
			);
	else
		$page_base = intval(substr(strval($pageno), 0, 3));

	$url = null;

	$feeds = array (
		'bbc' => "http://feeds.bbci.co.uk/news/",
		'weather' => "https://weather-broker-cdn.api.bbci.co.uk/en/forecast/rss/3day/",
		'stardot' => "ATOM:https://stardot.org.uk/forums/app.php/feed/forum/", 
		'ispreview' => "https://www.ispreview.co.uk/index.php/feed"
		);
	{	// BBC News & weather page
		switch ($page_base)
		{
			case 101: $url=$feeds['bbc']."uk/rss.xml"; break;
			case 102: $url=$feeds['bbc']."england/rss.xml"; break;
			case 103: $url=$feeds['bbc']."world/europe/rss.xml"; break;
			case 104: $url=$feeds['bbc']."wales/rss.xml"; break;
			case 111: $url="https://weather-broker-cdn.api.bbci.co.uk/en/forecast/rss/3day/2644688"; break; // Leeds
			case 112: $url="https://weather-broker-cdn.api.bbci.co.uk/en/forecast/rss/3day/2643743"; break; // London
			case 113: $url="https://weather-broker-cdn.api.bbci.co.uk/en/forecast/rss/3day/2650225"; break; // Edinburgh
			case 114: $url="https://weather-broker-cdn.api.bbci.co.uk/en/forecast/rss/3day/5128581"; break; // New York
			case 115: $url="https://weather-broker-cdn.api.bbci.co.uk/en/forecast/rss/3day/2172517"; break; // Canberra
			case 116: $url="https://weather-broker-cdn.api.bbci.co.uk/en/forecast/rss/3day/2988507"; break; // Paris
			// StarDot Forums
			case 151: $url=$feeds['stardot']."14?t=15155"; break;
			case 1521: $url="https://stardot.org.uk/forums/feed.php?f=1"; break;
			case 1522: $url="https://stardot.org.uk/forums/feed.php?f=57"; break;
			case 1523: $url="https://stardot.org.uk/forums/feed.php?f=46"; break;
			case 1524: $url="https://stardot.org.uk/forums/feed.php?f=2"; break;
			case 1525: $url="https://stardot.org.uk/forums/feed.php?f=3"; break;
			case 1526: $url="https://stardot.org.uk/forums/feed.php?f=4"; break;
			case 1527: $url="https://stardot.org.uk/forums/feed.php?f=44"; break;
			case 160: $url=$feeds['ispreview']; break;

		}
		if ($url)
			$frame_data = phoenix_display_rss($url, $page_base, $pageno, $subframeid, 0, 0, 0, VTRED, VTYEL);
	}

	$frame_data['frame_content'] = base64_encode($frame_data['frame_content']);
	return $frame_data;

}

function sysip_CHPW($frame_id, $data)
{

	global $userdata;

	$oldpw = $data['CURRENT'];
	$newpw1 = $data['NEW'];
	$newpw2 = $data['SECOND'];

	if ($newpw1 != $newpw2)
	{
		show_error($userdata->conn, "New passwords do not match.");
		sleep(2);
		return (array(IPR_TRYAGAIN, $frame_id));
	}
	
	if (strlen($newpw1) < 7)
	{
		show_error($userdata->conn, "New password not long enough.");
		sleep(2);
		return (array(IPR_TRYAGAIN, $frame_id));
	}

	// Check old password

	$r = dbq("select user_pw from user where user_id=?", "i", $userdata->user_id);
	if ($r['success'])
	{
		if ($r['numrows'] == 1)
		{
			$data = @mysqli_fetch_assoc($r['result']);
			dbq_free($r);
			//@mysqli_free_result($r['result']);
			if(password_verify($oldpw, $data['user_pw'])) // Password correct
			{
				$r = dbq("update user set user_pw = ? where user_id = ?", "si",
					password_hash($newpw1, PASSWORD_DEFAULT),
					$userdata->user_id);
				if ($r['success'])
					show_prompt($userdata->conn, "Password changed.");
				else	
				{
					show_error($userdata->conn, "Database update failed.");
					return (array(IPR_TRYAGAIN, $frame_id));
				}
			}
			else
			{	log_event('Priv Violation', $userdata->user_id, "Attempt to change password with wrong current password");
				show_error($userdata->conn, "Wrong password.");
				return (array(IPR_TRYAGAIN, $frame_id));
			}
		}
		else	show_error($userdata->conn, "Cannot find your user data.");
	}
	else	show_error($userdata->conn, "Database error.");

	$userdata->frame_previous = $userdata->frame_current;
	eighties_delay(2);
	return array(IPR_GOTOFRAME, $userdata->frame_previous); 


}

function userid_checkdigit($userid)
{

	$checksum = 0;
	$public_id = strval($userid);
	for ($count = 0; $count < strlen($public_id); $count++)
		$checksum += intval($public_id[$count]);
	$checksum %= 10;

	return ($checksum);
}

function sysip_REGISTER($frame, $data)
{

	global $userdata;
	global $config;

	preg_match('/^([1-9][0-9]*)([a-z])$/', $frame, $matches);
	$pageno = $matches[1];
	$subframeid = $matches[2];

	$firstname = $data['FIRSTNAME'];
	$surname = $data['SURNAME'];
	$password1 = $data['PASSWORD'];
	$password2 = $data['PASSB'];

	$ret = array(IPR_GOTOFRAME, $config['startpage']);

	if ($password1 != $password2)
	{
		show_error ($userdata->conn, "Passwords do not match. Key 0.");
		$i = false;
		while ($i !== '0')
			$i = ser_input_insist("0", true);
		$ret = array(IPR_TRYAGAIN, $pageno);
	}
	else
	{
		$query = "insert into user(user_pw, user_realname) values (?, ?)";
		$r = dbq($query, "ss", password_hash($password1, PASSWORD_DEFAULT), $firstname." ".$surname);
		if (($r['success']) and (($r['affected'] == 1)))
		{
			$user_id = $r['insert_id'];
			if ($r['result'] !== false) { dbq_free($r); /* @mysqli_free_result($r['result']); */ }
			$public_id = strval($user_id).userid_checkdigit($user_id);
			show_prompt($userdata->conn, "ID: $public_id. Key 0.");
			$i = false;
			while ($i !== '0')
				$i = ser_input_insist("0", true);
		}
	}

	return $ret;
	//return sysip_LOGIN ($config['startpage'], array('USERID' => $public_id, 'PASSWORD' => $password1));
}

function sysip_VALIDATE($frame_id, $user_id, $fieldname, $data)
{

	return true; // Just for now

}

// Text centre - take a string and pad with spaces either end to make $len
function text_center($str, $len)
{
	$str = trim($str); // Take away any existing space
	$str = substr($str, 0, $len); // Make sure it's not longer than allowed
	$front_pad = intval(($len - strlen($str))/2);
	$end_pad = $len-strlen($str)-$front_pad;
	$str = sprintf("%".$front_pad."s%s%".$end_pad."s", "", $str, "");
	return ($str);
}
	
// Take a text string and word wrap it into lines of $len characters
function text_to_lines($str, $len)
{

	$lines = array("");
	$words = explode(' ', $str);

	foreach ($words as $word)
	{
		if (strlen($word) > $len)
			while (strlen($word) > $len)
			{
				$lines[] = substr($word, 0, $len);
				$word = substr($word, -1*(strlen($word)-$len)); // Split long words over lines
			}

		if (strlen($lines[count($lines)-1])+strlen($word)+1 > $len)
		{
			trim($lines[count($lines)-1]); // Remove trailing space
			$lines[] = "";
		}

		$lines[count($lines)-1] .= $word." ";
	}
			
	return ($lines);

}

// Messaging functions

function validate_userid($u)
{

	$ret = false;

	$id = substr($u,0,-1);
	if  (userid_checkdigit($id) != substr($u,-1))
		return $ret;

	$r = dbq("select user_id from user where user_id=?", "i", $id);
	$rows = $r['numrows'];
	dbq_free($r);
	//@mysqli_free_result($r['result']);
	if ($rows == 1)
		$ret = true;
		
	return $ret;

}

function msg_post ($sender_page, $msg_dest, $msg_subject, $msg_text)
{

	global $userdata;

	$ret = true;

	$r = dbq("select mb_id, mb_flags from msgboard where frame_pageno_send = ?", "s", $sender_page);
	if ($r['result'])
	{
		if ($r['numrows'] == 1)
		{
			$data = @mysqli_fetch_assoc($r['result']);
			dbq_free($r);
			//@mysqli_free_result($r['result']);
			$mb_id = $data['mb_id'];
			$msg_subject = trim(substr($msg_subject, 0, 30)); // Trim
			$msg_sender = $userdata->user_id;
			if (preg_match('/personal/', $data['mb_flags'])) // Need a particular destination
				if ($msg_dest != 0 and (validate_userid($msg_dest)))
					$msg_dest = substr($msg_dest, 0, -1); // Drop the checkdigit
				else if ($msg_dest == 0)
					$msg_dest = NULL;
				else $ret = false;
			else	$msg_dest = null;

			if ($ret) // Still ok;
			{
				$msg_node = $userdata->node_id;
				debug ("msg_post(): inserting text $msg_text");
				$r = dbq("insert into msg(msg_sender, msg_dest, msg_text, msg_date, msg_node, msg_subject, mb_id) values (?, ?, ?, NOW(), ?, ?, ?)", "iisisi", $msg_sender, $msg_dest, $msg_text, $msg_node, $msg_subject, $mb_id);
				
				if ((!$r['success']) or (!($r['affected'] == 1)))
				{
					debug("msg_post(): insert failed.");
					$ret = false;
				}
				else
				{
					dbq_free($r);
					//@mysqli_free_result($r['result']);
				}
			}
			else
				debug ("msg_post(): Failed to validate destination $msg_dest");
		}
		else
		{
			debug ("msg_post() failed: No msg board found on query.");
			$ret = false;
		}
	}
	else	
	{
		debug ("msg_post() failed: Query error locating msg board.");
		$ret = false;
	}

	return $ret;

}

function check_for_new_mail($mb_id = null, $unread = true) // Checks from our login time for unread personal messages. Returns number of unread.
{

	// Note that the userdata->last_new_msg_check variable is used by phoenix to work out when we bother calling this to
	// alert the user, *not* to decide when to check from

	// a msg-dest of NULL means broadcast.

	global $userdata;

	$msgs = 0;

	// If we only want messages on one particular board, $mb_id will be non-null
	$select_extra = "";
	$exclude_read = "";

	if ($mb_id)
		$select_extra = "AND	msg.mb_id = $mb_id ";

	if ($unread === false)
		$exclude_read = "AND msg_read.mr_flags != 'Deleted' ";
	

		$r = dbq("
select 	count(msg_id) as msgs
from 	msg
	left join msgboard on msg.mb_id = msgboard.mb_id
where 	(	msg.msg_dest = ?
		or
		msg.msg_dest IS NULL
	)
AND 	FIND_IN_SET('personal', msgboard.mb_flags)
$select_extra
AND NOT EXISTS (
			select 	msg_id
			from 	msg_read
			where	msg.msg_id = msg_read.msg_id
			$exclude_read
			and msg_read.user_id = ?
		)
", "ii", $userdata->user_id, $userdata->user_id);

	if ($r['result'])
	{
		$data = @mysqli_fetch_assoc($r['result']);
		dbq_free($r);
		//@mysqli_free_result($r['result']);
		$msgs = $data['msgs'];
		debug ("Msg check successful - $msgs new");
	}
		
	return $msgs;
}

function get_nr_board($station, $dir, $filterCrs = null)
{

	//debug ("get_nr_board($station, $dir, $filterType, $filterCrs)");
	$board = array();

	// Sanity check

	$station = strtoupper(trim($station));
	$dir = strtoupper(trim($dir));
	$filterCrs = strtoupper(trim($filterCrs));

	if (!preg_match('/^\s*$/', $filterCrs))
		$filterType = ($dir == 'A' ? 'from' : 'to');
	else	$filterType = null;

	if (!preg_match('/^[A-Z]{3}$/', $station)) debug("get_nr_board(): Stn $station did not match regex");
	if (!preg_match('/^[AD]$/', $dir)) debug("get_nr_board(): Direction $dir did not match regex");
	if (	(!preg_match('/^[A-Z]{3}$/', $station)) 
	||	(!preg_match('/^[AD]$/', $dir))
	)
	{
		$board[] = sprintf("%40s", "");
		$board[] = sprintf("%- 40s", " Bad station board request.");
	}
	else
	{
		if ($filterType !== null)
		{
			if	(!preg_match('/^[A-Z]{3}$/i', $filterCrs))
			{
				$board[] = sprintf("%40s", "");
				$board[] = sprintf("%- 40s", " Bad filter request - Ignored");
				$filterType = $filterCrs = null;
			}
		}

		$data = nr_get_board($station, $dir, 15, $filterType, $filterCrs);

		if ($data && isset($data->GetStationBoardResult->trainServices->service))
		{

			//$title = ($dir == 'A' ? "Arrivals at " : "Departures from ").$data->GetStationBoardResult->locationName;
			//$l = strlen($title);
			//if ($l < 32)
				//$title = str_repeat(' ', intval((34-$l)/2)).$title.str_repeat(' ', intval((34-$l)/2));
			//$board[] = substr(sprintf("%- 40s", viewdata_to_chr(VDHEIGHT).viewdata_to_chr(VTBLU).viewdata_to_chr(VBKGNEW).viewdata_to_chr(VTWHT).$title."  ".viewdata_to_chr(VBKGBLACK)), 0, 40);
			
	
			$l = VDHEIGHT.VTBLU.VBKGNEW.VTWHT.$data->GetStationBoardResult->locationName."  ".VBKGBLACK;
			$l = str_repeat(' ', intval((40-strlen($l))/2)).$l;
			$board[] = substr(sprintf("%- 40s", $l), 0, 40);
			$board[] = sprintf("%40s", "");
			$subtitle = ($dir == 'A' ? "Arrivals" : "Departures");
			$board[] = sprintf("%-40s", str_repeat(' ', intval((40-strlen($subtitle))/2)).$subtitle);
			$board[] = sprintf("%-40s", VTCYN."  Powered by National Rail Enquiries");
			$board[] = sprintf("%40s", "");
			$fmt_str = "%- 5s".viewdata_to_chr(VTCYN)."%- 20s".viewdata_to_chr(VTGRN)."% 3s".viewdata_to_chr(VTYEL)."%- 9s";
			if ($filterType !== null)
				$board[] = sprintf(viewdata_to_chr(VTMAG)."%- 39s", ($dir == 'A' ? "Arrivals from" : "Departures to")." ".$filterCrs);
			$board[] = sprintf($fmt_str, ($dir == "A" ? "Arr." : "Dep."), ($dir == 'A' ? "From" : "To"), "Plt", ($dir == 'A' ? "Departs" : "Expected"));

			$trains = $data->GetStationBoardResult->trainServices->service;

			foreach ($trains as $t)
			{
				if (count($board) < 22) // 22 because we may have a 2nd line on any given row
				{
					$board[] = sprintf( 	$fmt_str, 
								($dir == "A" ? $t->sta : $t->std),
								($dir == "A" ? substr($t->origin->location->locationName, 0, 20) : substr($t->destination->location->locationName, 0, 20)),
								(isset($t->platform) ? $t->platform : "-"),
								($dir == "A" ? $t->eta : $t->etd)
							);

					if (isset($t->isCancelled) && $t->isCancelled)
					{
						$outStr = render_wrap_str($t->cancelReason, 39, 0);
						foreach ($outStr['wrapped'] as $o)
							$board[] = sprintf("%- 40s", VTRED.$o);
					}
					else if (isset($t->delayReason))
					{
						$outStr = render_wrap_str($t->delayReason, 39, 0);
						foreach ($outStr['wrapped'] as $o)
							$board[] = sprintf("%- 40s", VTYEL.$o);
					}
				}

			}

		}
		else
		{
			$board[] = sprintf("%40s", "");
			$board[] = sprintf("%- 40s", " No information found.");
		}
			

	}	

	$board = implode('', $board);

	return $board;

}

?>
