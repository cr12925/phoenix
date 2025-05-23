#!/usr/bin/php
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

$hostname = gethostname();

include_once "conf.php";


$options = getopt("n:a:dhfc", array("node:", "as-host:", "debug", "help", "no-fork", "console"));

if (isset($options['h']) or isset($options['help']))
{
	fprintf (STDERR, "%s:

	-n <nodespec>	(--node) Run only on specified node numbers (see node table)
			Default if not specified: all nodes
	-a <hostname>	(--as-host) Run as if our local hostname was <hostname>
	-c		(--console) Run on stdin/stdout. Must specify -n with single node.
	-d		(--debug) Turn on debugging to standard output (implies -f)
	-h		(--help) Display this help message and exit\n\n
	<nodespec>	(N | N-N)+ (individual node ids, or a range)\n\n",
	$argv[0]);

	exit();

}

$debug_flag = false;
$no_fork = false;
if (isset($options['d']) or isset($options['debug'])) {
      $debug_flag = true;
	$no_fork = true;
}

if ($debug_flag)
	printf ("System hostname: %s\n", $hostname);

$final_nodes = array();

if (isset($options['a']) or isset($options['as-host']))
	if (!isset($options['a']))
		$hostname=$options['as-host'];
	else	$hostname=$options['a'];

$queryadd = "not";
$likestring = '^/';

if (isset($options['c']) or isset($options['console'])) // Only want ports beginning '/'
{
	$queryadd = "";
	$likestring = posix_ttyname(STDIN);
}
	
$r = dbq("select * from node where ? rlike node_host and node_port $queryadd rlike '$likestring' order by node_id asc", "s", $hostname);
if ($r['result'])
{
	while ($data = @mysqli_fetch_assoc($r['result']))
		$final_nodes[$data['node_id']] = $data['node_port'];
	//@mysqli_free_result($r['result']);
	dbq_free($r);
}
else
{
	print "Cannot load list of nodes (".mysqli_error($dbh)."). Quitting.\n";
	exit();
}

if (isset($options['n']) or isset($options['node']))
{
	if (!isset($options['n']))
		$options['n'] = $options['node'];
	debug ("Found node option - node ".$options['n']);
	
	// Parse node list
	$particular_nodes = explode(',', $options['n']);
	// Split each array element if it has the format A-B (range)
	$final_nodes_tmp = array();
	foreach ($particular_nodes as $pn)
	{
		if (preg_match('/^\d+$/', $pn) && array_key_exists($pn, $final_nodes))
			$final_nodes_tmp[$pn] = $final_nodes[$pn];
		else if (preg_match('/^(\d+)\-(\d+)$/', $pn, $matches))
		{
			$start = $matches[1]; $end = $matches[2];
			for ($count = $start; $count <= $end; $count++) 
				if (array_key_exists($count, $final_nodes))
					$final_nodes_tmp[$count] = $final_nodes[$count];
		}

	}
	$final_nodes = $final_nodes_tmp;
}

if (sizeof($final_nodes) == 0) 
{
	print "No nodes identified to run.\n\n";
	exit();
}

if (sizeof($final_nodes) > 1 && (isset($options['c']) or isset($options['console'])))
{
	print "Set to console operation, but more than one node selected. Try -n ...?\n";
	exit();
}

foreach ($final_nodes as $node_id => $node_port)
{
	
	if (sizeof($final_nodes) > 1) // Need to fork
		$pid = pcntl_fork();
	else	$pid = 0; // Pretend we are the child
	
	if ($pid && ($pid == -1))
	{
		print "pcntl_fork() failed on creating node $node_id\n. Quitting.\n";
		exit();
	}

	if (!$pid) // Child
	{ 
		open_db(); // Re-open DB
		$r = dbq("select * from node where node_id = ?", "d", $node_id);
		if ($r['numrows'] == 0)
		{
			//@mysqli_free_result($r['result']);
			dbq_free($r);
			print "Cannot find node $node_id in database. Quitting.\n";
			exit();
		}
		else
		{
			$data = @mysqli_fetch_assoc($r['result']);
			//@mysqli_free_result($r['result']);
			dbq_free($r);
			break; // Escape the loop
		}
	}

}

if ($pid) // We were parent on exit from the loop
{
	$status = 0;
	pcntl_wait($pid, $status);
	exit();
}

$port_data = $data;
$portpres = ($port_data['node_portpres'] ? $port_data['node_portpres'] : $port_data['node_port']);
$port = $port_data['node_port'];

// Initialise socket - if we are not operating on console
$sock = false;
if (!(isset($options['c']) or isset($options['console'])))
{
	
	if (preg_match('/^\//', $port)) // Device - die.
		exit();
	
	while (!$sock) // Wait for socket to be free
	{
		#$sock = @socket_create_listen($port);
		#if (!$sock) sleep(1);
		if (($sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) == false) 
		{
			print "Cannot create socket: ".socket_strerror(socket_last_error())."\n"; exit();
		}
		if (!socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1))
		{
			print "Cannot set socket option: ".socket_strerror(socket_last_error())."\n"; exit();
		}
		if (socket_bind($sock, '0.0.0.0', $port) == false) 
		{
			print "Cannot bind socket: ".socket_strerror(socket_last_error())."\n"; exit();
		}
		if (!socket_listen($sock, 5))
		{
			print "Cannot listen on socket: ".socket_strerror(socket_last_error())."\n"; exit();
		}
	}
}
else
{
	$sock = fopen("/dev/tty", "r+");
}

// Get configuration
$config = array();
$r = dbq("select config_var, config_val from config");
while ($row = @mysqli_fetch_assoc($r['result']))
{
	$config[$row['config_var']] = $row['config_val'];
}
//@mysqli_free_result($r['result']);
dbq_free($r);

// Clear down all nodes
//dbq("update node set user_id=null where node_port = ?", "i", $port);
debug("Listening on port ".$port);

// Instance user information
$user_id = false;

include_once ("editor.php");
include_once ("response.php"); // Response frame handler
include_once ("ip_lib.php"); // IP communications library

// send_frame_title_userdata
// Do what send_frame_title does but from the userdata object
function send_frame_title_userdata ()
{
		global $userdata;

		ser_output_conn(VCLS.VCURSOROFF);
		$frame_no_colour = VTYEL;
		if ($userdata->preview)
			$frame_no_colour = VTRED;
		if ($userdata->editing) // The if for editing comes second because preview is always on if editing
			$frame_no_colour = VTBLU;

		$frame_ip_header = $userdata->frame_data["ip_header"];
		$frame_pageno = $userdata->frame_current;
		//if (isset($userdata->frame_data['frame_displaynumber']))
			//$frame_pageno = $userdata->frame_data['frame_displaynumber'];
		//else
			//$frame_pageno = $userdata->frame_data["frame_pageno"].$userdata->frame_data["frame_subframeid"];
		$frame_time = date("H:i");

		if ((!$userdata->preview) && (!$userdata->editing))
		{
			if (preg_match('/hide_ip/', $userdata->frame_data['frame_flags'])) // I.e. we are not hiding the IP
				$frame_ip_header="";
			if (preg_match('/hide_time/', $userdata->frame_data['frame_flags'])) // I.e. not hiding the time
				$frame_time="";
			if (preg_match('/hide_frame_id/', $userdata->frame_data['frame_flags'])) // I.e. not hiding the frame ID
				$frame_pageno="";
		}

		ser_output_conn(sprintf("%- 19s".$frame_no_colour."%10s    ".VTWHT."%5s", $frame_ip_header, $frame_pageno, $frame_time));

		// Some 80's style computation delay
		eighties_delay(0.7);

}
// transplant data
// Put substituted variables into a line of frame data
function transplant_data($myline, $pos, $str, $padlen, $centre = false)
{

	$str = trim($str);
	$str = substr($str, 0, $padlen); // Truncate if necessary

	if ($centre)
		$skip = intval(($padlen - strlen($str))/2);
	else	$skip = 0;

	$str = str_repeat(' ', $skip).$str.str_repeat(' ', ($padlen - strlen($str) - $skip));

	if ($pos + strlen($str) > 39)
		$str = substr($str, 0, 39-$pos); // Make sure we don't go over the line

/*	if ($centre)
	{
		$skip = intval(($padlen - strlen($str))/2);
		if ($skip > 0)
		{
			for ($counter = $pos; $counter <= $pos+$skip; $counter++)
				$myline[$counter] = chr(32);
			$pos = $pos + $skip;
		}
	}
	else	$skip = 0;
*/	
	for ($counter = 0; $counter < strlen($str); $counter++)
		$myline[$pos+$counter] = $str[$counter];

/*
	if (($skip + strlen($str)) < $padlen)
		for ($counter = $pos+strlen($str)+$skip; $counter <= $pos+$padlen; $counter++)
			if (($counter) <= 39)
				$myline[$counter] = chr(32);
*/
	return $myline;

}

function send_frame_userdata($startbyte = 0, $allow_keys = true)
{

	global $userdata;

	// Deliver here
	// Split into 40-character lines

	$frame_data = $userdata->frame_data["frame_content"];

	// Calculate valid routes
	$keys = "".implode("", array_keys($userdata->frame_routes));

	$keys = VDKEYSTAR.VDKEYENTER.$keys;

	if ($userdata->is_msg_reading)
		$keys = $keys."udUD0"; // Allows unread, delete, return to index

	$ret = array(TX_OK, $frame_data, 880);

	$framelines = intval(strlen($frame_data) / 40);
	if (strlen($frame_data) > 0)
		$framelines++;

	$userdata->frame_displayed = 0;

	if ($startbyte == 0)
	{
		$start_row = 1;
		$start_char = 0;
	}
	else
	{
		$start_row = intval($startbyte/40)+1;
		$start_char = ($startbyte % 40);
		goto_xy($userdata->conn, $start_char, $start_row);
	}

	if ($userdata->tx_baud == $userdata->rx_baud)
		$baud_rate = $userdata->tx_baud." baud";
	else
		$baud_rate = $userdata->tx_baud."/".$userdata->rx_baud;

	$framevars = array(
		"NODENAME" => array($userdata->node_name, 15),
		"SPEED" => array($baud_rate, 9),
		"USERID" => array($userdata->user_id.userid_checkdigit($userdata->user_id), 7),
		"REMOTEADDR" => array($userdata->remote_host, 38),
		"REMOTEIP" => array($userdata->remote_addr, 15),
		"RPRT" => array(trim(substr($userdata->remote_port,-5)), 5),
		"USERNAME" => array($userdata->user_name ? $userdata->user_name : "", 25),
		"LOGINTIME" => array(date("D d/m/Y H:i", $userdata->login_time), 20),
		"LASTLOGIN" => array($userdata->previous_login_time, 19)
	);

	if ($userdata->is_msg_reading) // Populate @SENDER with source name and render the rest of the data, too
	{
		$framevars["SENDER"] = array($userdata->msg_data['user_realname'], 25);
		$framevars["MSGDATE"] = array($userdata->msg_data['sent_time'], 19);
		// Insert recipient, subject and msg text
		// Look for recipient, subject and text in the response fields list
		$recip_index = $subject_index = $text_index = null;
		foreach ($userdata->frame_response as $v)
		{
			switch ($v['fr_fieldname'])
			{
				case 'USER':
					$recip_index['start'] = $v['fr_start'] % 40;
					$recip_index['length'] = $v['fr_end']  - $v['fr_start'] + 1;
					$recip_index['frameline'] = intval($v['fr_end'] / 40) + 1;
					break;
				case 'SUBJECT':
					$subject_index['start'] = $v['fr_start'] % 40;
					$subject_index['length'] = $v['fr_end']  - $v['fr_start'] + 1;
					$subject_index['frameline'] = intval($v['fr_end'] / 40) + 1;
					break;
				case 'TEXT':
					$text_index['start_x'] = $v['fr_start'] % 40;
					$text_index['start_y'] = intval($v['fr_start'] / 40) + 1;
					$text_index['end_x'] = $v['fr_end'] % 40;
					$text_index['lines'] = intval($v['fr_end'] / 40) + 1 - $text_index['start_y'] + 1;
					$text_index['linelength'] = ($text_index['end_x'] % 40) - ($text_index['start_x'] % 40) + 1;
					break;
			}
		}

	}
	else // in case we are on a sending screen, set it to our name
	{
		$framevars["SENDER"] = $framevars["USERNAME"];
		$framevars["MSGDATE"] = array("Now", 19);
	}

	if ($userdata->is_msg_index)
	{
		// Populate up @n, @FROMn, @SUBJECTn, @DATEn here
		for ($c = 0; $c < 5; $c++)
		{
			$displaynum = $c+1;
			if (isset($userdata->msg_index_data[$c]))
			{
				$framevars[$displaynum] = array(sprintf("%01d", $c+1), 2);
				$framevars["FROM".$displaynum] = array($userdata->msg_index_data[$c][0], 20); // Sender
				$framevars["SUBJECT".$displaynum] = array($userdata->msg_index_data[$c][1], 29); // Subject	
				$framevars["DATE".$displaynum] = array($userdata->msg_index_data[$c][2].($userdata->msg_index_data[$c][3] == 'New' ? '*' : ' '), 6); // Date - flash for new
			}
			else
			{
				$framevars[$displaynum] = array("",2); // Blank out the numeric index variable
				$framevars["FROM".$displaynum] = array("", 20); // Sender
				$framevars["DATE".$displaynum] = array("", 6); // Date
				$framevars["SUBJECT".$displaynum] = array("", 29); // Subject	
			}

		}

	}
	else	debug ("Not a message index frame - did not populate the index fields");
	
	for ($frameline = $start_row; $frameline <= ($framelines+1); $frameline++)
	{
		// In case we have no straggling data, don't try and display if it doesn't exist
		if ((40*($frameline-1)) <= strlen($frame_data))
		{
			$myline = substr($frame_data, (40*($frameline-1)), 40);
			if (!$userdata->editing && preg_match('/framevars/', $userdata->frame_data["frame_flags"])) // Substitute frame variables
			{
				foreach ($framevars as $k => $v)
				{
					//if (($pos = strpos($myline, "@".$k)) or ($pos = strpos($myline, "\\".$k)))
						//debug ("Transplanting $v[0] at $pos padded to $v[1]");
					if ($pos = strpos($myline, "@".$k))
						$myline = transplant_data($myline, $pos, $v[0], $v[1]);
					else if ($pos = strpos($myline, "\\".$k))
						$myline = transplant_data($myline, $pos, $v[0], $v[1], true);
				}
			}

			// Transplant in any message reading stuff

			if ($userdata->is_msg_reading && ($frameline == $recip_index['frameline']))
				$myline = transplant_data($myline, $recip_index['start'], $userdata->msg_data['recip'], $recip_index['length']);
	
			if ($userdata->is_msg_reading && ($frameline == $subject_index['frameline']))
				$myline = transplant_data($myline, $subject_index['start'], $userdata->msg_data['msg_subject'], $subject_index['length']);
	
			if ($userdata->is_msg_reading && ($frameline >= $text_index['start_y']) && 
				($frameline < ($text_index['start_y'] + count($userdata->msg_data['wrapped_display']))))
				$myline = transplant_data($myline, $text_index['start_x'], $userdata->msg_data['wrapped_display'][$frameline - $text_index['start_y']], $text_index['linelength']);
			$myline = rtrim($myline);
		
			if (($startbyte != 0) && ($frameline == $start_row))
				if (strlen($myline) >= $start_char) // Start char is within the start line - i.e. it isn't in some space at the end
					$myline = substr($myline, $start_char); // Balance of string to be output
				else // Nothing to display - set to empty
					$myline = "";
			
			if (strlen($myline) == 0)
				$ending="\n";
			else if (strlen($myline) < 40) // If equal to 40, the cursor will wrap to the next line.
				$ending = "\r\n";
			else	$ending = "";

			$out_ret = ser_output_conn_keys ($myline.$ending, $allow_keys ? $keys : false); // Need to add keys when we've got them

			if (($out_ret[0] == TX_HANGUP) or ($out_ret[0] == TX_OK_KEY))
			{
				$ret = array($out_ret[0], $out_ret[1], (($frameline-1)*40+($out_ret[2]-1)));
				break;
			}
		}

	}

	$userdata->frame_displayed = $ret[2];

	if (!$userdata->editing && preg_match('/disconnect/', $userdata->frame_data['frame_flags'])) // Disconnect on transmission complete
		$ret[0] = TX_DISCONNECT;

	return $ret;

}

// Load frame currently specified in $userdata
function load_frame_userdata()
{

	global $userdata;
	//return load_frame_data($userdata->frame_data["frame_pageno"], $userdata->frame_data["frame_subframeid"], $userdata->preview);
	return load_frame_data(substr($userdata->frame_current, 0, -1), substr($userdata->frame_current, -1), $userdata->preview);

}

// is_dynamic
// Returns an array of useful stuff if the page number (i.e. all subframes) is dynamic

function is_dynamic ($page_no)
{

	global $userdata;

	debug ("Checking if $page_no is dynamic");
	$ret = false;
	#$query = "select dyn_start, dyn_end from dynamic where dyn_start <= ".$page_no." and dyn_end >= ".$page_no;
	$r = dbq("select dyn_start, dyn_end from dynamic where dyn_start <= ? and dyn_end >= ?", "ii", $page_no, $page_no);
	if ($r['result'])
	{
		if ($r['numrows'] == 1)
		{
			debug ("Page $page_no is dynamic.");
			$ret = true;
		}
		//@mysqli_free_result($r['result']);
		dbq_free($r);
	}

	return $ret;

}

function load_dynamic_ip_info($page_no)
{

	global $userdata;

	$ret = null;

	//$query = "select ip_id, ip_header, LENGTH(ip_base) as ip_base_len from information_provider where left(?, IF(ip_base=0, 0, LENGTH(ip_base))) = if(ip_base=0, '', ip_base) AND LENGTH(?) >= LENGTH(ip_base) order by ip_base_len DESC LIMIT 1";
	//$r = dbq($query, "ss", strval($page_no), strval($page_no));
	$query = "select ip_id, ip_header, LENGTH(ip_base) as ip_base_len,user_id from information_provider where ? RLIKE concat(ip_base_regex, '.*') order by ip_base_len DESC LIMIT 1";
	$r = dbq($query, "s", strval($page_no));
	if ($r['result'])
	{
		$row = @mysqli_fetch_assoc($r['result']);
		//@mysqli_free_result($r['result']);
		dbq_free($r);
		$ret = $row;
	}
		else	debug ("Loading IP info for dynamic page $page_no failed");

	return ($ret);

}

// find_msgs($mb_id)
// Clears out and re-creates the temporary table containing current active messages on a particular board

function find_msgs($mb_id)
{

	global $userdata;

	if (is_array($mb_id))
		$mb_id = implode(',', $mb_id);

		$r = dbq("
CREATE TEMPORARY TABLE	msg_temp
SELECT		msg.*, 
		date_format(msg.msg_date, '%a %e %b %y %H:%i') as sent_time,
                user.user_realname,
                msg_read.mr_flags,
                user_recip.user_realname as recip,
                IF(msg_read.mr_flags IS NULL, 'New', 'Read') as msg_new
FROM            msg left join msg_read on msg.msg_id = msg_read.msg_id,
                user,
		user as user_recip
WHERE           msg.mb_id IN ($mb_id)
                AND msg.msg_dest = ?
                AND msg.msg_sender = user.user_id
		AND msg.msg_dest = user_recip.user_id
",		"i", $userdata->user_id);

                // And then broadcast messages that are read, new, but not deleted

                $r = dbq("
INSERT INTO 	msg_temp 
SELECT 		msg.*, 
		date_format(msg.msg_date, '%a %e %b %y %H:%i') as sent_time, 
		user.user_realname, 
		msg_read.mr_flags,
		'All' as recip, 
		IF(msg_read.mr_flags IS NULL, 'New', 'Read') as msg_new
FROM		msg left join msg_read on msg.msg_id = msg_read.msg_id AND msg_read.user_id = ?, 
		user
WHERE		msg.mb_id IN ($mb_id)
		AND msg.msg_dest IS NULL
                AND msg.msg_sender = user.user_id
                        ", "i", $userdata->user_id);

	$r = dbq("select count(*) as number from msg_temp");
	if ($r['success'])
	{
		$d = @mysqli_fetch_assoc($r['result']);
		$n = $d['number'];
		//@mysqli_free_result($r['result']);
		dbq_free($r);
		debug ("find_msgs $mb_id found $n messages");
	}
	$r = dbq("delete from msg_temp where mr_flags = 'Deleted'"); // This is inefficient and ought to be fixable in the queries above. Suggestions on a postcard to anyone but me.

}

// is_msg_index($frame_pageno, $frame_subframeid)
// Returns:
//      false if not an index page
//      false if it is an index page but insufficient msgs on the board
//      no. of available msgs if it is an index page and there are sufficient msgs
// Returns true if this is a message index page which appears at least once in msgboard
function is_msg_index($frame_pageno, $frame_subframeid)
{

	global $userdata;

        $r = dbq("
SELECT  mb_id
FROM    msgboard
WHERE   frame_pageno_list = ?
        ", "i", $frame_pageno);

        if ($r['success'])
        {
                if ($r['numrows'] >= 1)
                {
			$mb_id = array();
                        while ($d = @mysqli_fetch_assoc($r['result']))
				array_push($mb_id, $d['mb_id']);
                        $ret = true;
                        debug ("is_msg_index($frame_pageno, $frame_subframeid) found message board (".implode(',',$mb_id).")");
                }
                else    $ret = false;
                //@mysqli_free_result($r['result']);
		dbq_free($r);
        }
        else    $ret = false;

        // Now see if there are sufficient messages for this frame to "exist" virtually
        // If not, return false - and the system will just produce a frame not found error.

        if ($ret === true)
        {
                $min_msgs = ((ord($frame_subframeid) - ord('a')) * 5) + 1;

		find_msgs($mb_id);

                //$actual_msgs = check_for_new_mail($mb_id, false); // Second param means we get all undeleted messages, not just new ones
		$r = dbq("select count(*) as c from msg_temp"); // The temporary table with the stuff in it we want
		if (!$r['success'])
			$actual_msgs = 0;
		else
		{
			$d = @mysqli_fetch_assoc($r['result']);
			$actual_msgs = $d['c'];
			//@mysqli_free_result($r['result']);
			dbq_free($r);
		}

                if (($actual_msgs < $min_msgs) and ($frame_subframeid != 'a')) // Insufficient messages and not on the 'a' frame
                {
                        debug ("is_msg_index($frame_pageno, $frame_subframeid) discovered only $actual_msgs msgs. Min is $min_msgs. Returned false.");
                        $ret = false;
                }
                else
		{
                        $ret = $actual_msgs;

			// Populate the array of dates, times, etc.

			$userdata->msg_index_data = array();
			$r = dbq("select *, if(date(msg_date) = date(now()), date_format(msg_date, '%H:%i'), date_format(msg_date, '%d/%m')) as msg_stamp from msg_temp order by msg_date asc limit ?, 5", "i", $min_msgs -1); // LIMIT index is ordered from 0, whereas min_msgs is an actual count
			if ($r['success'])
			{
				while ($d = @mysqli_fetch_assoc($r['result']))
					array_push($userdata->msg_index_data, array($d['user_realname'], $d['msg_subject'], $d['msg_stamp'], $d['msg_new']));
				//@mysqli_free_result($r['result']);
				dbq_free($r);
				debug ("is_msg_index() populated ".count($userdata->msg_index_data)." messages for index.");
			}

			
		}


		dbq("drop table msg_temp");

        }


        return $ret;

}

// is_msg_reading_page($frame_pageno)
// If the page number specified is a reading page for a given message board
// (i.e. nn001, nn003, etc. where nn is the base reading page in msgboard)
// return the corresponding sending page number for the board.
// This enables the frame loader to load the material from the sending page
// When messages are read.

function is_msg_reading_page($frame_pageno, $frame_subframeid = 'a')
{
	$r = dbq("
SELECT	frame_pageno_send, frame_pageno_list, mb_id
FROM	msgboard
WHERE	LEFT(?, LENGTH(frame_pageno_list)) = frame_pageno_list
	AND
	LENGTH(?) > LENGTH(frame_pageno_list)
ORDER BY	LENGTH(frame_pageno_list) DESC
", "ss", $frame_pageno, $frame_pageno);

	$ret = false;

	if ($r['success'])
	{
		if ($r['numrows'] < 1)
			$ret = false;
		else
		{
			$mb_id = array();
			while ($d = @mysqli_fetch_assoc($r['result']))
			{
				array_push($mb_id,$d['mb_id']);
				$frame_pageno_list = $d['frame_pageno_list']; // They should all be the same!
			}
		}
		//@mysqli_free_result($r['result']);
		dbq_free($r);
	}
	else	$ret = false;

	if (isset($mb_id) and count($mb_id) > 0) // Load the message
	{

		find_msgs($mb_id);

		global $userdata;

		$msg_number = intval(substr($frame_pageno, -3));
		debug ("is_msg_reading_page: Looking for msg_number ".$msg_number);
		$r = dbq("SELECT msg_temp.*, msgboard.frame_pageno_send from msg_temp, msgboard where msg_temp.mb_id = msgboard.mb_id order by msg_date asc limit ?, 1", "i", ($msg_number - 1));
		debug ("is_msg_reading_page: Select from temporary table - number of rows: ".$r['numrows']);

		if (!$r['success'])
			$ret = false;
		else
		{
			//$subpage_number = substr($frame_pageno, strlen($list_page)); // to end of string
			//$msg_number = intval($subpage_number);
			if ($r['numrows'] != 1)
				$ret = false;
			else
			{
				$userdata->msg_data = @mysqli_fetch_assoc($r['result']);
				// Get the sending page - we need to return it
		
				$ret = $userdata->msg_data['frame_pageno_send'];

				// Now word-wrap the message based on the width of the text field on the frame
				// NB always works to the published frame.
				$fr_r = dbq ("
					SELECT fr_start, fr_end
					FROM	frame left join frame_response on frame.frame_id = frame_response.frame_id
					WHERE	frame.frame_pageno = ?
					AND	frame.frame_subframeid = ?
					AND 	frame_response.fr_fieldname = 'TEXT'
					AND	!FIND_IN_SET('unpublished', frame.frame_flags)
				", "is", substr($ret, 0, -1), substr($ret, -1));

				if (!$fr_r['success'])
					$ret = false;
				else
				{
					$field_data = @mysqli_fetch_assoc($fr_r['result']);
					//@mysqli_free_result($fr_r['result']);
					dbq_free($fr_r);
			
					// Calculate rows & line length
					$rows_per_frame = intval($field_data['fr_end'] / 40) - intval($field_data['fr_start'] / 40) + 1;
					$linelength = ($field_data['fr_end'] % 40) - ($field_data['fr_start'] % 40) + 1;
		
					// Which frame number are we on?

					$frame_number = ord($frame_subframeid) - ord('a') + 1;

					// Render the message wordwrapped
					$t = render_wrap_str($userdata->msg_data['msg_text'], $linelength, 0);
					$userdata->msg_data['wrapped'] = $t['wrapped'];

					// Now see how many frame's worth we have got
					$total_frames = intval(count($userdata->msg_data['wrapped']) / $rows_per_frame);
					if ((count($userdata->msg_data['wrapped']) % $rows_per_frame) > 0)
						$total_frames++;

					debug ("is_msg_reading_page: Msg has total frames: $total_frames, total lines: ".count($userdata->msg_data['wrapped']).". Frame sought: $frame_subframeid ($frame_number)");
					if  ($total_frames < $frame_number) // Frame therefore doesn't exist - nothing to put on it
					{
						debug("is_msg_reading_page: This msg has only $total_frames frames' worth of data, but frame number $frame_number attempted. Returning false.");
						$ret = false;
					}
					else // Put the right set of lines into wrapped_display	
					{
						$c = ($frame_number -1) * $rows_per_frame;
						$c_end = $c + $rows_per_frame - 1;

						$userdata->msg_data['wrapped_display'] = array();
						while (($c <= $c_end) && ($c < count($userdata->msg_data['wrapped'])))
							$userdata->msg_data['wrapped_display'][] = $userdata->msg_data['wrapped'][$c++];		
						$userdata->msg_data['last_subframe'] = chr(ord('a')+$total_frames-1);
					}
					
				}
		
			}

			//@mysqli_free_result($r['result']);
			dbq_free($r);
		}
		
		dbq("DROP TABLE msg_temp");

	}

	return $ret;	
}

// Load frame data from SQL or retrieve from an IP
// Returns (assoc array of frame data, assoc array of routes, assoc array of fields)

function load_frame_data($frame_pageno, $frame_subframeid, $preview)
{

	global $userdata;
	$ret = array(false, false, false);

	$cpdata = page_get_priv ($frame_pageno, $userdata->user_id);
	$userdata->frame_priv = $cpdata[0];

	//debug ("page_get_priv($frame_pageno, $userdata->user_id) yielded priv: ".$cpdata[0].", area ID ".$cpdata[1].", area name ".$cpdata[2]);

	if ($cpdata[0] == PRIV_NONE)
		return $ret;

	//if (($userdata->preview or $userdata->editing) && !check_privs($frame_pageno.$frame_subframeid))
	if (($userdata->preview or $userdata->editing) && !($cpdata[0] & PRIV_OWNER))
	{
		log_event('Priv Violation', $userdata->user_id, "Attempt to preview/edit ".$frame_pageno.$frame_subframeid);
		$userdata->editing = $userdata->preview = false;
	}
	else
	{

	$userdata->frame_data = $userdata->frame_routes = $userdata->frame_response = null;

	// Clear response data
	$userdata->frame_response = $userdata->frame_routes = array();

	// If in preview mode, load the unpublished version

	// But not if it doesn't exist. The priv check is done above

	if (($userdata->preview && is_unpublished($frame_pageno.$frame_subframeid)) || $userdata->editing)
		$preview_extra = " and find_in_set('unpublished', frame.frame_flags)";
	else
		$preview_extra = " and !find_in_set('unpublished', frame.frame_flags)";
		
	debug("Loading frame [".$frame_pageno."] subframe [".$frame_subframeid."]");

	if (is_dynamic($frame_pageno) && (!$userdata->editing && !$userdata->preview))
	{

		if (!($dynamic_ip_data = load_dynamic_ip_info($frame_pageno)))
		{	debug ("Load dynamic frame data failed");
			$ret = array(false, false, false);
		}
		else
		{
			// Clear response variables
			$userdata->frame_response = array();
			$dynamic_data = ip_dynamic($dynamic_ip_data['ip_id'], $userdata->user_id, $frame_pageno, $frame_subframeid); 
			if ($dynamic_data == array(IPR_CALLFAILURE) || $dynamic_data == array(IPR_BADDATA))
				$ret = array(false, false, false);
			else
			{
				$frame_data_decoded = base64_decode($dynamic_data['frame_content']);
				if (strlen($frame_data_decoded != 880)) // Error / e.g. frame not found
					$ret = array(false, false, false);
				for ($count = 0; $count < strlen($frame_data_decoded); $count++)
					if (($frame_data_decoded[$count] == chr(127)) or ($frame_data_decoded[$count] < chr(32) or $frame_data_decoded[$count] > chr(159)))
						$frame_data_decoded[$count] = chr(32);
				$userdata->frame_data['frame_content'] = $frame_data_decoded;
				$userdata->frame_data['frame_pageno'] = $frame_pageno;
				$userdata->frame_data['frame_subframeid'] = $frame_subframeid;
				$userdata->frame_data['frame_flags'] = "login";
				$userdata->frame_data['frame_id'] = -1;
				$userdata->frame_data['frame_fr_ip_function'] = null;
				$userdata->frame_data['ip_id'] = $dynamic_ip_data['ip_id'];
				$userdata->frame_data['ip_header'] = $dynamic_ip_data['ip_header'];
				$userdata->frame_response = $dynamic_data['frame_response'];
				$userdata->frame_routes = $dynamic_data['frame_routes'];
				$userdata->frame_data['frame_next'] = $dynamic_data['frame_next'];

				$ret[0] = $userdata->frame_data;
				$ret[1] = $userdata->frame_routes;
				$ret[2] = $userdata->frame_response;
			}
		}
	}
	else
	{

	// Work out if this is a message index or message reading frame
	// What we do is this:
	// If we are being asked for nnnnn(b-z) where nnnnna is the message index frame (default 78a)
	// Then, since it is 5 message indexes per page, we work out whether (ord(b) (or whatever) less (ord(a)) * 5 < total number
	// of messages. So that that calculation for frame (a) will be "IS 0 < total". If it is, we do nothing and the frame won't
	// be found (which is right, because there will be no messages).

	// If it is less than the total, we spook the system into loading the "a" frame data and then we populate it
	// with the right set of indexes according to whether it was a, b, c, etc. by populating the variables.
	// Those variables are @1, @2, etc. for the message no., @SUBJECT1, @SUBJECT2 etc., @FROM1, @FROM2, ... 
	// and there is a special one for the bottom which will be blank if this is the last set of messages, and
	// "# for more" if it isn't.
	
	// We also populate the routes the similarly - by making 1 on frame A go to (e.g.) 78001, 1 on frame B go to 78006.
	
	// When one of those frames is sought, we make it load the base image from the corresponding sending frame - so that
	// if there is a special format for that frame (e.g. valentines frame), it can be loaded and the text put into the
	// format it was originally sent in. Obviously if someone changes the sending frame after someone has sent a message
	// then it may go wonky. The mb_id is stored with the message so that we can get the right one.

	$frame_pageno_to_load = $frame_pageno;
	$frame_subframeid_to_load = $frame_subframeid;

	//$msg_index = $msg_reading = false;

	$userdata->is_msg_index = $userdata->is_msg_reading = false;

	if (($actual_msgs = is_msg_index($frame_pageno, $frame_subframeid)) !== false)
	{
		$frame_subframeid_to_load = "a";
		//$msg_index = true;
		$userdata->is_msg_index = true;
	}

	if ($p = is_msg_reading_page($frame_pageno, $frame_subframeid))
	{

		// Note that p will have a subframe suffix on it 
		// Because the sending page may not be 'a'.
		// Contrast the index pages which must always start at 'a'.
		debug ("Message reading page detected - sending page is $p");
		$frame_pageno_to_load = substr($p,0,-1);
		$frame_subframeid_to_load = substr($p,-1);
		//$msg_reading = true;
		$userdata->is_msg_reading = true;
		$userdata->underlying_page = $frame_pageno_to_load.$frame_subframeid_to_load;
		// is_msg_reading_page() will have loaded the msg data into $userdata
	}
	

	//$query = "
	//select left(from_base64(frame_content),880) as frame_content, frame_id, left(ip_header,20) as ip_header, frame_flags, frame_id, frame_pageno, frame_subframeid, frame.ip_id, frame_next,frame_fr_ip_function, frame.area_id from frame, information_provider where frame_pageno=? and frame_subframeid=? and frame.ip_id = information_provider.ip_id".$preview_extra." LIMIT 1";
	$query = "
	select left(from_base64(frame_content),880) as frame_content, frame_id, frame_flags, frame_id, frame_pageno, frame_subframeid, frame.ip_id, frame_next,frame_fr_ip_function, frame.area_id from frame where frame_pageno=? and frame_subframeid=? ".$preview_extra." LIMIT 1";
	$r = dbq($query, "is", $frame_pageno_to_load, $frame_subframeid_to_load);
	debug ("Frame load query returned ".$r['numrows']." rows for frame ".$frame_pageno_to_load.$frame_subframeid_to_load);
	if ($r['numrows'] == 1)
	{
		debug("Frame $frame_pageno$frame_subframeid - SQL data loaded");
		$ret[0] = @mysqli_fetch_assoc($r['result']);
		$userdata->frame_data = $ret[0];
		//@mysqli_free_result($r['result']);
		dbq_free($r);

		// The ip_id in the framestore is not in fact accurate! We should get it by looking up whose IP this really is.
		$ip_info = load_dynamic_ip_info($frame_pageno_to_load);
		$userdata->frame_data['ip_header'] = $ip_info['ip_header'];
		$userdata->frame_data['ip_id'] = $ip_info['ip_id'];

		//debug("Frame content length ".strlen($userdata->frame_data['frame_content']).": ".substr($userdata->frame_data['frame_content'], 0, 30)."...");
		// Load routes

		$query = "
SELECT frame_keypress, frame_key_action, frame_key_metadata1, frame_key_metadata2, frame_next from frame, frame_key
WHERE 	frame.frame_id = frame_key.frame_id and frame_key.frame_id = ? ".$preview_extra;

		// note that frame_next will be the same in all rows. We just need it in case we trip off subframe 'z'

		$r = dbq($query, "i", $ret[0]['frame_id']);

		if ($r['result'])
		{

			while ($row = @mysqli_fetch_assoc($r['result']))
			{
				$frame_next = $row['frame_next'];
				$userdata->frame_data['frame_next'] = $frame_next;
				$routes = array(
					$row['frame_key_action'],
					$row['frame_key_metadata1'],
					$row['frame_key_metadata2'] );
				$userdata->frame_routes[$row['frame_keypress']] = $routes;
			}

			$ret[1] = $userdata->frame_routes;
			//@mysqli_free_result($r['result']);
			dbq_free($r);
		}
		else
		{
			show_error($userdata->conn, "System database error");
			logoff($conn);
		}

		// Overwrite the routes if this was a msg index or reading page.

		if ($userdata->is_msg_index)
		{
			$userdata->frame_routes = array();
			// We obtained 'actual msgs' up above.
			// And it must be more than the minimum.

			$start_msg_index = ((ord($frame_subframeid) - ord('a')) * 5) + 1;
				
			$end_msg_index = $start_msg_index + 4;
			if ($actual_msgs < $end_msg_index) $end_msg_index = $actual_msgs;

			$start_key = '1';
			for ($c = $start_msg_index; $c <= $end_msg_index; $c++)
				$userdata->frame_routes[$start_key++] = 
					array(
						$row['frame_key_action'] = 'Page',
						$row['frame_key_metadata1'] = $frame_pageno.sprintf("%03d", $c),
						$row['frame_key_metadata2'] = null
					);
		
		}

		if ($userdata->is_msg_reading)
		{
			$userdata->frame_routes = array();
			
			// 0 - back to index
			// Reply & Delete are dealt with by prompt at end of msg display
			// (Or will be, when I've written it.)

		}

		// Now load response fields
		if ($preview)
			$preview_extra = " and find_in_set('unpublished', fr_flags)";
		else
			$preview_extra = " and !find_in_set('unpublished', fr_flags)";

$query =	"select * from frame_response where frame_id=? ".$preview_extra." order by fr_start asc";

		$r = dbq($query, "i", $ret[0]['frame_id']);

		$userdata->frame_response = array ();

		if ($r['result'])
		{
			while ($row = @mysqli_fetch_assoc($r['result']))
				$userdata->frame_response[] = $row;
			//@mysqli_free_result($r['result']);
			//
			dbq_free($r);
		}
		else
		{
			show_error($conn, "System database error");
			logoff($conn);
		}
	}

	else	
		{
			//@mysqli_free_result($r['result']);
			dbq_free($r);
			debug ("Load frame data for $frame_pageno$frame_subframeid yielded nothing. $query -- $frame_pageno_to_load -- $frame_subframeid_to_load");
		}
	} // Load from database - Note the indentation is wrong
	

	}

	// Strip unorthodox characters from the frame data

	if ($userdata->frame_data !== null)
	{

		for ($count = 0; $count < strlen($userdata->frame_data['frame_content']); $count++)
			if ($userdata->frame_data['frame_content'][$count] < chr(32) or $userdata->frame_data['frame_content'][$count] > chr(191)) // 191 is 127+64, so the limit of escapable characters
				$userdata->frame_data['frame_content'][$count] = chr(32);

	}

	return ($ret);

}

// Process a star command.
function process_star($conn, $str, $frame, $prev, $ip_id)
{

	global $userdata, $config;

	// Introduce a little 1980s-style computational delay
	eighties_delay(1);

	$ret = -1;

	$str = strtolower(trim($str));
	ser_output_conn(VCURSOROFF);

	if ($str == '')
		$ret = $prev;
	else if (preg_match('/^fdelete\s+([1-9][0-9]{0,8})([a-z\*]?)$/', $str, $matches))
	{
		$cpdata = page_get_priv($matches[1], $userdata->user_id);
		//if (check_privs($matches[1].$matches[2]))
		if ($cpdata[0] & PRIV_OWNER)
		{
			show_prompt ($conn, "Delete ".$matches[1]."? <1> Confirm <2> Abort");
			$key = ser_input($conn, '12', true);
			if ($key == "2")
				show_error ($conn, "Delete frame aborted");
			else
			{
				// Collect frame_ids (we need them to delete routes & response entries)
				$query = "
select frame_id from frame where
frame_pageno = ".$matches[1];
				if ($matches[2] != "*")
					$query .= " and frame_subframeid ='".$matches[2]."'"; // NB delete gets rid of published & unpublished

				$r = dbq($query);
				
				if ($r['result'])
				{
					$frame_ids = array();
					while ($row = @mysqli_fetch_assoc($r['result']))
						$frame_ids[] = $row['frame_id'];
					//@mysqli_free_result($r['result']);
			//
					dbq_free($r);

					foreach ($frame_ids as $f)
					{
						dbq ("delete from frame where frame_id = ?", "i", $f);
						dbq ("delete from frame_key where frame_id = ?", "i", $f);
						dbq ("delete from frame_response where frame_id = ?", "i", $f);
					}
		
					show_error ($conn, "Frame set deleted.");
				}
				else
					show_error ($conn, "Unable to gather frame list. Sorry.");
			}
		}
		else
		{
			log_event('Priv Violation', $userdata->user_id, "Attempted *delete ".$matches[1].$matches[2]);
			show_error ($conn, "Unauthorised. Attempt logged.");
		}
	}
	else if (preg_match('/^fpreview$/', $str))
	{	// Preview unpublished frames
		if ($userdata->ip_id or sizeof($userdata->secondary_ip_id) > 0) // Is IP
		{
			$userdata->preview = 1;
			show_prompt ($conn, "Preview mode enabled");
		}
		else	{
			show_error ($conn, "Forbidden");
			log_event ('Priv Violation', $userdata->user_id, "Preview attempt but not an IP");
		}
		$ret = $frame; // Cause redisplay
	}
	else if (preg_match('/^fpreview off$/', $str))
	{	// Preview unpublished frames
		if ($userdata->ip_id or sizeof($userdata->secondary_ip_id) > 0) // Is IP
		{
			$userdata->preview = 0;
			show_prompt ($conn, "Preview mode disabled");
		}
		else	{
			show_error ($conn, "Forbidden");
			log_event ('Priv Violation', $userdata->user_id, "Preview off attempt but not an IP");
		}
		$ret = $frame; // Cause redisplay
	}
	else if (preg_match('/^frenum\s+([1-9][0-9]{0,9}[a-z])\s+([1-9][0-9]{0,9}[a-z])$/', $str, $matches))
	{
		// Renumber single frame
		$cpdata = page_get_priv(substr($matches[1], 0, -1), $userdata->user_id);
		$cpdata_dest = page_get_priv(substr($matches[2], 0, -1), $userdata->user_id);

		if (!(($cpdata[0] & PRIV_OWNER) && ($cpdata_dest[0] & PRIV_OWNER)))
		{
			show_error ($userdata->conn, "Unauthorised. Attempt logged.");
			log_event('Priv Violation', $userdata->user_id, "Attempted $str");
		}
		else
		{
			$r = dbq("delete from frame where frame_pageno = ? and frame_subframeid = ?", "is", substr($matches[2], 0, -1), substr($matches[2], -1));
			if ($r['success'])
			{
				// NB don't need to renumber frame_key / frame_response because they are indexed on frame_id, not frame_pageno etc.
				$r = dbq("update frame set frame_pageno = ?, frame_subframeid = ? where frame_pageno = ? and frame_subframeid = ?", 
					"isis",
					substr($matches[2], 0, -1),
					substr($matches[2], -1),
					substr($matches[1], 0, -1),
					substr($matches[1], -1)
				);
				if ($r['success'])
					show_prompt($userdata->conn, "Renumbered ".$matches[1]." to ".$matches[2]);
				else
					show_error($userdata->conn, "Unable to renumber.");
			}
			else		show_error($userdata->conn, "Unable to clear ".$matches[2]);
		}
	}
	else if (preg_match('/^frenum\s+([1-9][0-9]{0,9})\s+([1-9][0-9]{0,9})$/', $str, $matches))
	{
		// Renumber whole page - all sub-pages
		$cpdata = page_get_priv($matches[1], $userdata->user_id);
		$cpdata_dest = page_get_priv($matches[2], $userdata->user_id);

		if (!(($cpdata[0] & PRIV_OWNER) && ($cpdata_dest[0] & PRIV_OWNER)))
		{
			show_error ($userdata->conn, "Unauthorised. Attempt logged.");
			log_event('Priv Violation', $userdata->user_id, "Attempted $str");
		}
		else
		{
			$r = dbq("delete from frame where frame_pageno = ?", "i", $matches[2]);
			if ($r['success'])
			{
				$r = dbq("update frame set frame_pageno = ? where frame_pageno = ?", 
					"ii",
					$matches[2],
					$matches[1]
				);
				if ($r['success'])
					show_prompt($userdata->conn, "Renumbered ".$matches[1]." to ".$matches[2]);
				else
					show_error($userdata->conn, "Unable to renumber.");
			}
			else		show_error($userdata->conn, "Unable to clear frameset ".$matches[2]);
		}
	}
	else if (preg_match('/^fedit\s+([1-9][0-9]*[a-z])$/', $str, $matches))
	{
		//$ret = $frame = $userdata->frame_data['frame_pageno'].$userdata->frame_data['frame_subframeid'];
		$ret = $frame = $userdata->frame_current;
		$cpdata = page_get_priv(substr($matches[1],0, -1), $userdata->user_id);

		//if (!check_privs($matches[1]))
		if (!($cpdata[0] & PRIV_OWNER))
		{
			show_error($userdata->conn, "Unauthorised. Attempt logged.");
			log_event ('Priv Violation', $userdata->user_id, "Attempted *edit ".$matches[1]);
		}
		else if (is_dynamic(substr($matches[1], 0, strlen($matches[1])-1)))
		{
			show_error($userdata->conn, "Cannot edit dynamic frame.");
			log_event ('Priv Violation', $userdata->user_id, "Attempted *edit on dynamic frame ".$frame);
		}
		else
		{
			frame_edit($conn, $matches[1]);
		}
	}
	else if (preg_match('/^fedit$/', $str, $matches))
	{
		//$ret = $frame = $userdata->frame_data['frame_pageno'].$userdata->frame_data['frame_subframeid'];
		$ret = $frame = $userdata->frame_current;
		$cpdata = page_get_priv(substr($frame,0,-1), $userdata->user_id);
		//if (!check_privs($frame))
		if (!($cpdata[0] & PRIV_OWNER))
		{
			show_error("Unauthorised. Attempt logged.");
			log_event ('Priv Violation', $userdata->user_id, "Attempted *edit on frmae ".$frame);
		}
		else if (is_dynamic($userdata->frame_data['frame_pageno']))
		{
			show_error("Cannot edit dynamic frame.");
			log_event ('Priv Violation', $userdata->user_id, "Attempted *edit on dynamic frame ".$frame);
		}
		else
			frame_edit($conn, $frame);
	}
	else if (preg_match('/^fpublish\s+([1-9][0-9]*[a-z\*]?)$/', $str, $matches))
	{
		// Frame publish
		// NB need to publish routes & response fields also
		// Trailing * instead of subframe publishes all pages whose frame numbers begin with the given number
	}
	else if (preg_match('/^acreate\s+([a-z0-9]+)\s+([co])\s+([1-9][0-9]*[X]*)$/i', $str, $matches)) // *AREA CREATE [AREANAME] [CP] (Closed, Public)-- create new area
	{
		$operative_ip_id = frame_get_ip_id(strtoupper($matches[3]));

		#if ($userdata->ip_id) // Is an IP
		if ($userdata->ip_id == $operative_ip_id || (is_array($userdata->secondary_ip_id) && array_key_exists($userdata->secondary_ip_id[$operative_ip_id])))
		{
			$area_name = strtoupper($matches[1]);	
			$area_public = "Open";
			if (strtoupper($matches[2]) == "C") $area_public = "Closed";
			$area_pageno = strtoupper($matches[3]);
			// MUST CHECK IN HERE THAT PAGE MASK IS IN OUR IP RANGE
			$area_len = strlen($area_pageno);
			$area_leftx = strstr($area_pageno, 'X', true);
			$query = "select area_id from area where ip_id = ? and ? RLIKE area_pageno_regex order by LENGTH(area_pageno) DESC";
			$r = dbq($query, "is", $operative_ip_id, $area_pageno);
			if ($r['result'])
			{
				$rows = @mysqli_num_rows($r['result']);
				//@mysqli_free_result($r['result']);
					//
				dbq_free($r);
				if (($rows < 1) and ($userdata->ip_id != 1)) // Our area or superuser
				{
					show_error($userdata->conn, "Page is not yours. Area not created.");
					log_event('Misc', $userdata->user_id, "Out of IP Area create: ".$area_pageno);
				}	
				else
				{	
					$query = "insert into area(area_public, area_name, ip_id, area_pageno, area_pageno_regex) values (?, ?, ?, ?, ?)";
					$r = dbq($query, "ssiss", $area_public, strtoupper($area_name), $operative_ip_id, $area_pageno, ip_regex_to_object($area_pageno));
					if ($r['success'])
						show_prompt($userdata->conn, "Area ".$area_name." created.");
					else
					{
						show_error($userdata->conn, "Area creation failed.");
						log_event('Misc', $userdata->user_id, "Area creation failed: ".$area_name);
					}			
				}
			}
			
		}
		else
		{
			show_error ($userdata->conn, "Unauthorised. Attempt logged.");
			log_event ('Priv Violation', $userdata->user_id, "Attempted ".$str);
		}
	}
	else if (preg_match('/^fdynamic\s+([1-9][0-9]{1,9})\s+([1-9][0-9]{1,9})$/', $str, $matches))
	{
		// New or replace dynamic entry
		$dyn_start = $matches[1];
		$dyn_end = $matches[2];

		$ds_priv = page_get_priv($dyn_start, $userdata->user_id);
		$de_priv = page_get_priv($dyn_end, $userdata->user_id);

		if (!(($ds_priv[0] & PRIV_OWNER) and ($de_priv[0] & PRIV_OWNER)))
		{
			log_event('Priv', $userdata->user_id, "Attempted $str but not owner");
			show_error($userdata->conn, "Refused. Start or end page not yours.");
		}
		else
		{
			$r = dbq("replace into dynamic(dyn_start, dyn_end) values (?, ?)", "ii", $dyn_start, $dyn_end);
			if ($r['success'])
				show_prompt($userdata->conn, "Dynamic page $dyn_start - $dyn_end set.");
			else	show_error($userdata->conn, "Failed to set dynamic pages.");
		}
	}
	else if (preg_match('/^fdynamic\s+([1-9][0-9]{1,9})\s+DELETE$/i', $str, $matches))
	{
		// Delete existing dynamic entry
		$dyn_start = $matches[1];
		$ds_priv = page_get_priv($dyn_start, $userdata->user_id);
		if (!($ds_priv[0] & PRIV_OWNER))
		{
			log_event('Priv', $userdata->user_id, "Attempted $str but not owner");
			show_error($userdata->conn, "Refused. Start page not yours.");
		}
		else
		{
			// Get the old values so we can display them
			$r = dbq("select dyn_start, dyn_end from dynamic where dyn_start = ? limit 1", "i", $dyn_start);
			if ($r['success'])
			{
				if ($r['numrows'] == 1)
				{
					$data = @mysqli_fetch_assoc($r['result']);
					$dyn_end = $data['dyn_end'];
					dbq("delete from dynamic where dyn_start = ?", "i", $dyn_start);
					show_prompt($userdata->conn, "Dynamic page $dyn_start - $dyn_end deleted.");
				}
				else
					show_error($userdata->conn, "Dynamic frame entry not found.");
				//@mysqli_free_result($r['result']);
				//
				dbq_free($r);
			}
			else	show_error($userdata->conn, "Can't check if entry exists.");
		}

	}
	else if (preg_match('/^fcopy\s+([1-9][0-9]{0,9})\s+([1-9][0-9]{0,9})$/', $str, $matches) && ($userdata->ip_id == 1)) // Copy frameset
	{
		$src_page = $matches[1];
		$dst_page = $matches[2];

		$r = dbq("
			INSERT INTO frame (frame_pageno, frame_subframeid, frame_content, frame_flags, ip_id, frame_next, area_id, frame_fr_ip_function)
				SELECT	?, frame_subframeid, frame_content, frame_flags, ip_id, frame_next, area_id, frame_fr_ip_function
				FROM	frame
				WHERE	frame_pageno = ?", "ii", $matches[2], $matches[1]);

		if ($r['success'])
			show_prompt($userdata->conn, "Frame copied.");
		else	show_error($userdata->conn, "Frame copy failed.");

	}
	else if (preg_match('/^fflags\s+([1-9][0-9]{0,9}[a-z])\s+([PU])\s+([\+\-])(HIDEIP|HIDEFRAMEID|HIDETIME|DISCONNECT|LOGIN|NOLOGIN|FRAMEVARS)\s*$/i', $str, $matches) && ($userdata->ip_id == 1)) // Set frame flags
	{
		$frame = strtolower($matches[1]);
		$pubunpub = strtoupper($matches[2]);
		$addremove = $matches[3];
		$flag = strtolower($matches[4]);
		
		$realflag = $flag;
		switch ($flag)
		{
			case 'hideip':	$realflag = 'hide_ip'; break;
			case 'hideframeid': $realflag = 'hide_frame_id'; break;
			case 'hidetime': $realflag = 'hide_time'; break;
		}
		if ($pubunpub == "P")
			$frame_id = is_published($frame);
		else	$frame_id = is_unpublished($frame);
		
		if (!$frame_id)
			show_error($userdata->conn, "No such frame.");
		else
		{
			if ($addremove == '-') // Remove flag
			{
				$r = dbq("update frame set frame_flags =
						TRIM(BOTH ',' FROM REPLACE(CONCAT(',', frame_flags, ','), ?, ','))
					WHERE	frame_id = ?
					AND	FIND_IN_SET(?, frame_flags)
				", "sis", ','.$realflag.',', $frame_id, $realflag);
				if ($r['success'] and ($r['affected'] == 1))
					show_prompt($userdata->conn, "Flag ".$flag." unset on ".$frame);
				else	show_error($userdata->conn, "Error unsetting $flag on $frame");
			}
			else // Set flag
			{
				$r = dbq("update frame set frame_flags = concat(frame_flags, ?)
					WHERE	frame_id = ?", "si", ','.$realflag, $frame_id);
				if ($r['success'] and ($r['affected'] == 1))
					show_prompt($userdata->conn, "Flag ".$flag." set on ".$frame);
				else	show_error($userdata->conn, "Error setting $flag on $frame");
			}
			
		}

	}
	else if (preg_match('/^ipset\s+([0-9]{2,10})\s+([1-9][0-9]{0,9}[X]{0,4})$/i', $str, $matches) && ($userdata->ip_id == 1)) // The regex allows page specs which are too long there..
	{
		if ($real_userid = verify_userid($matches[1]))
		{
			$r = dbq("select user_id from user where user_id = ?", "i", $real_userid);
			if ($r['success'] && ($r['numrows'] == 1))
			{
				// User exists
				$ip_base_regex = ip_regex_to_object(strtoupper($matches[2]));
				$r2 = dbq("select * from information_provider where user_id = ?", "i", $real_userid);
				if ($r2['success'])
				{
					$data['ip_id'] = null;
					$data['ip_name'] = "";
					$data['ip_header'] = "";
					$data['ip_key'] = "";
					$data['ip_location'] = "";
					$allow_continue = true;
					if ($r2['numrows'] == 1) // Found
					{
						$data = @mysqli_fetch_assoc($r2['result']);
					}
					$check_overlap = load_dynamic_ip_info(strtoupper($matches[2])); // See if this overlaps with some other IP
					if (
						(($data['ip_id'] == null) && ($check_overlap['ip_id'] != 1)) or // New IP user and we overlap with something other than system IP space
						(($data['ip_id'] != null) and ($check_overlap['ip_id'] != $data['ip_id']) and ($check_overlap['ip_id'] != 1))
					   )
					{
						show_error($userdata->conn, "Space overlap with ".$check_overlap['user_id'].userid_checkdigit($check_overlap['user_id']));
						$allow_continue = false;
					}
				if ($allow_continue) // THIS SECTION WRONGLY INDENTED
				{
					$data['ip_name'] = trim($data['ip_name']);
					$data['ip_key'] = trim($data['ip_key']);
					$data['ip_location'] = trim($data['ip_location']);
					//@mysqli_free_result($r2['result']);
				//
					dbq_free($r2);
				
					show_prompt($userdata->conn, "Name: ".$data['ip_name']);
					ser_output_conn(VCURSORON);
					$input = ser_input_str_full(20,
								strlen($data['ip_name'])-1,
								VDKEYALPHANUMERIC.VDKEYSPACE.VNLEFT.VDKEYBACKSPACE,
								false,
								$data['ip_name'],
								false, true); // Last 'true' is "not in frame" - i.e. don't put the frame background up on a delete.
					ser_output_conn(VCURSOROFF);
					$data['ip_name'] = $input[1];
	
					show_prompt($userdata->conn, "Location: ".$data['ip_location']);
					ser_output_conn(VCURSORON);
					$input = ser_input_str_full(29,
								strlen($data['ip_location'])-1,
								VDKEYALPHANUMERIC.VDKEYSPACE.VNLEFT.VDKEYBACKSPACE,
								false,
								$data['ip_location'],
								false, true); // Last 'true' is "not in frame" - i.e. don't put the frame background up on a delete.
					ser_output_conn(VCURSOROFF);
					$data['ip_location'] = $input[1];

					show_prompt($userdata->conn, "SOAP key: ".$data['ip_key']);
					ser_output_conn(VCURSORON);
					$input = ser_input_str_full(29,
								strlen($data['ip_key'])-1,
								VDKEYALPHANUMERIC.VDKEYSPACE.VNLEFT.VDKEYBACKSPACE,
								false,
								$data['ip_key'],
								false, true); // Last 'true' is "not in frame" - i.e. don't put the frame background up on a delete.
					ser_output_conn(VCURSOROFF);
					$data['ip_key'] = $input[1];

					show_prompt($userdata->conn, "IP Header: |".sprintf("%-20s", $data['ip_header'])."|");
					ser_output_conn(chr(0x0d));
					for ($a = 0; $a < strlen($data['ip_header'])+13; $a++)
						ser_output_conn(VNRIGHT);
					ser_output_conn(VCURSORON);
					$input = ser_input_str_full(20,
								strlen($data['ip_key'])-1,
								VDKEYGENERAL.VNLEFT.VDKEYBACKSPACE,
								false,
								$data['ip_header'],
								false, true); // Last 'true' is "not in frame" - i.e. don't put the frame background up on a delete.
					ser_output_conn(VCURSOROFF);
					$data['ip_header'] = $input[1];

					show_prompt($userdata->conn, "Save <YN>?");
					$input = strtolower(ser_input_insist("YyNn", true));
			
					if ($input == "y")
					{
						if ($data['ip_id'])
							$r = dbq("replace into information_provider(ip_id, ip_name, ip_location, ip_header, ip_key, ip_base, ip_base_regex, user_id) values (?, ?, ?, ?, ?, ?, ?, ?)", "issssssi", 
								$data['ip_id'], $data['ip_name'], $data['ip_location'], $data['ip_header'], $data['ip_key'], strtoupper($matches[2]), $ip_base_regex, $real_userid);
						else	$r = dbq("insert into information_provider(ip_name, ip_location, ip_header, ip_key, ip_base, ip_base_regex, user_id) values (?, ?, ?, ?, ?, ?, ?)", "ssssssi",
								$data['ip_name'], $data['ip_location'], $data['ip_header'], $data['ip_key'], strtoupper($matches[2]), $ip_base_regex, $real_userid);

						if ($r['success'])
							show_prompt($userdata->conn, "Success. Set SOAP address on cmd line.");
						else
							show_error($userdata->conn, "Failed. Not saved/updated.");
						
					}
					else	show_prompt($userdata->conn, "Not saved.");
					
				}
				else	show_error($userdata->conn, "Failure in checking IP exists.");
				} // OUT OF PLACE INDENT END
			}
			else show_error($userdata->conn, "No such user. Cannot set IP.");
			//@mysqli_free_result($r['result']);
					//
			dbq_free($r);
		}
		else
			show_error($userdata->conn, "Bad user id.");
	}
	else if (preg_match('/^ipdelete\s+([0-9]{2,10})\s*$/i', $str, $matches) && ($userdata->ip_id == 1) && ($real_userid = verify_userid($matches[1])))
	{
		// Don't forget to ask for confirmation
		// Refuse to delete IP no. 1
		$r = dbq("select * from information_provider where user_id = ?", "i", $real_userid);
		if ($r['success'])
		{
			if ($r['numrows'] == 1)
			{
				$data = @mysqli_fetch_assoc($r['result']);
				if ($data['ip_id'] == 1)
					show_error($userdata->conn, "Cannot delete system IP.");
				else
				{
					show_prompt($userdata->conn, "Delete IP and all frames - Sure <YN>?");
					$input = strtolower(ser_input_insist("YyNn", true));
					if ($input == "y")
					{
						show_prompt($userdata->conn, "Really sure <YN>?");
						$input = strtolower(ser_input_insist("YyNn", true));
						if ($input == "y")
						{
							dbq("delete from frame where frame_pageno rlike ?", "s", $data['ip_base_regex'].".*");
							dbq("delete from information_provider where ip_id = ?", "i", $data['ip_id']);
							show_prompt($userdata->conn, "Deleted forever.");
						}
						else	show_error($userdata->conn, "Aborted.");

					}
					else
						show_error($userdata->conn, "Aborted.");
				}
			}
			else	show_error($userdata->conn, "IP not found.");
			//@mysqli_free_result($r['result']);	
			dbq_free($r);
		}
		else	show_error($userdata->conn, "IP not found.");
	}
	else if (preg_match('/^adelete\s+([A-Za-z0-9]+)$/i', $str, $matches)) // *AREA DELETE [AREANAME] -- delete existing area - all pages in this area will become public, all messages will be deleted
	{
		if ($userdata->ip_id) // Is an IP
		{
			$matches[1] = strtoupper($matches[1]);
			$aid = get_area_id_by_name($matches[1], $userdata->ip_id); // Restrict to our IP
			if (!$aid)
			{
				show_error($userdata->conn, "No such area or not your area.");
				log_event('Misc', $userdata->user_id, "Area delete failed: No such area / not this user: ".$matches[1]);
			}
			else
			{
				show_prompt($userdata->conn, strtoupper($matches[1])." frames may become public <YN>?");
				$key = ser_input($userdata->conn, "YNyn", true);
				if (strtoupper($key) == "N")
					show_error($userdata->conn, "Area delete aborted.");
				else
				{
					dbq("delete from area where area_id = ?", "i", $aid);
					show_prompt($userdata->conn, "Area deleted.");	
				}
			}
		}
		else
		{
			show_error ($userdata->conn, "Unauthorised. Attempt logged.");
			log_event ('Priv Violation', $userdata->user_id, "Attempted ".$str);
		}
	}
	else if (preg_match('/^access\s+([A-Za-z0-9]+)\s+([1-9][0-9]*)\s+([pr])\s+([mu])$/i', $str, $matches)) // *AREA USER [areaname] [userid] (PNR) (MU), being Positive, Negative, Remove [from area permissions] and [M]oderator, [U]ser
	{
		$area_name = strtoupper($matches[1]);
		$user_id = $matches[2];
		$invert = strtoupper($matches[3]);
		$user_type = strtoupper($matches[4]);

		if ($userdata->ip_id) // Is an IP
		{
			// First, verify user number
			
			$user_id = verify_userid($user_id);
	
			// Second, see if this is an area we own
			$r = dbq("select area_id from area where ip_id=? and area_name=?", "is", $userdata->ip_id, $area_name);
			if ($user_id and ($r['result']))
			{
				if (($userdata->ip_id != 1) and ($r['numrows'] < 1)) // Permit superuser
				{
					show_error($userdata->conn, "Area not found, or not yours.");
					log_event('Misc', $userdata->user_id, "Area user modify failed: ".$str);
				}
				else
				{
					$area_data = @mysqli_fetch_assoc($r['result']);
					$aid = $area_data['area_id'];
					dbq("delete from area_permission where area_id=? and user_id=?", "ii", $aid, $user_id);
					
					if ($invert == "R") // Remove permission entry
						show_prompt($userdata->conn, "Existing permission entry removed.");
					else
					{
						$permission = "User";
						if ($user_type == "M")
							$permission = "Moderator";
						$ap_invert = "Positive";
						if ($invert == "N")
							$ap_invert = "Negative";
						$s = dbq("insert into area_permission(ap_invert, ap_permission, user_id, area_id) values (?, ?, ?, ?)", "ssii", $ap_invert, $permission, $user_id, $aid);
						if ($s['affected'] < 1)
							show_error($userdata->conn, "Unable to insert area permission.");
						else	
							show_prompt($userdata->conn, "Permission changed for user ".$matches[2]);
					}
				}
				//@mysqli_free_result($r['result']);
				dbq_free($r);
			}
			else
				if (!$user_id)
					show_error($userdata->conn, "Invalid user ID.");
				else
					show_error($userdata->conn, "Command failed.");
		}
		else
		{
			show_error ($userdata->conn, "Unauthorised. Attempt logged.");
			log_event ('Priv Violation', $userdata->user_id, "Attempted ".$str);
		}
	}
	else if (preg_match('/^access\s+([A-Za-z0-9]+)\s+([oc])$/i', $str, $matches)) // *AREA ACCESS [AREANAME] O[PEN]|C[LOSED] -- set default access for area
	{
		$area_name = strtoupper($matches[1]);
		if ($userdata->ip_id) // Is an IP
		{
	                $r = dbq("select area_id from area where (?=1 or ip_id=?) and area_name=?", "iis", $userdata->ip_id, $userdata->ip_id, $area_name);
                        if ($r['result'])
                        {
                                if (($userdata->ip_id != 1) and ($r['numrows'] < 1)) // Permit superuser
                                {
                                        show_error($userdata->conn, "Area not found, or not yours.");
                                        log_event('Misc', $userdata->user_id, "Area access modify failed: ".$str);
                                }
                                else
                                {
                                        $area_data = @mysqli_fetch_assoc($r['result']);
                                        $aid = $area_data['area_id'];

					$area_type = strtoupper($matches[2]);
					$area_type_string = "Open";
					if ($area_type == "C") $area_type_string = "Closed";
                                        $s = dbq("update area set area_public=? where area_id=?", "si", $area_type_string, $aid);
                                                if ($s['affected'] < 1)
                                                        show_error($userdata->conn, "Unable to update area access type.");
                                                else
						{
							// Get rid of existing permissions
							dbq("delete from area_permission where area_id=?", "i", $aid);
                                                        show_prompt($userdata->conn, "Area access modified. Permissions removed.");
						}
                                }
                                //@mysqli_free_result($r['result']);
				dbq_free($r);
                        }
                        else
                                show_error($userdata->conn, "Command failed.");
	
		}
		else
		{
			show_error ($userdata->conn, "Unauthorised. Attempt logged.");
			log_event ('Priv Violation', $userdata->user_id, "Attempted ".$str);
		}
	}
	else if ($userdata->ip_id and preg_match('/^mboard\s+([a-z]{1,10})\s+(DELETE|([1-9][0-9]{0,9}[a-z])\s+([1-9][0-9]{0,9}))$/i', $str, $matches))
	{
		$boardname = strtoupper($matches[1]);
		if (isset($matches[3]))
		{
			$sending_page = strtolower($matches[3]);
			$reading_page = strtolower($matches[4]); // Might not exist
		}

		if ($matches[2] == 'delete')
		{
			show_prompt($userdata->conn, "Delete board ".$boardname.": sure <YN>?");
			$response = ser_input_insist("YN", true, true);

			if ($response == 'N')
				show_error($userdata->conn, "Delete board ".$boardname." aborted.");
			else
			{
				// First check it's one of ours!
				$r = dbq("select * from msgboard where mb_name = ?", "s", $boardname);
				if ($r['success'])
				{
					if ($r['numrows'] == 1)
					{
						$d = @mysqli_fetch_assoc($r['result']);
						$sending_page = $d['frame_pageno_send'];
						$reading_page = $d['frame_pageno_list'];
						$mb_id = $d['mb_id'];
			                        $sendpriv = page_get_priv($userdata->user_id, substr($sending_page, 0, -1));
       				                 $readpriv = page_get_priv($userdata->user_id, $reading_page);
						if (!(($sendpriv[0] & intval(PRIV_OWNER)) && ($readpriv[0] & intval(PRIV_OWNER))))
							show_error($userdata->conn, $boardname." not yours to delete");
						else
						{
							$d_success = false;
							dbq_starttransaction();
							$r2 = dbq("delete from msgboard where mb_id = ?", "i", $mb_id);
							if ($r2['success'])
							{
								$r3 = dbq("delete from msg where mb_id = ?", "i", $mb_id);
								if ($r3['success'])
									$d_success = true;
							}
							if (!$d_success)
							{
								dbq_rollback();
								show_error($userdata->conn, $boardname." delete failed.");
							}
							else
							{
								dbq_commit();
								show_prompt($userdata->conn, $boardname." deleted.");
							}
						}

					}
					else
						show_error($userdata->conn, $boardname." not found");
	
					//@mysqli_free_result($r['result']);
					dbq_free($r);
				}
				else
					show_error($userdata->conn, "Database error.");
			
			}	
			
		}
		else
		{	// Attempt to create / modify
			$sendpriv = page_get_priv($userdata->user_id, substr($sending_page, 0, -1));
			$readpriv = page_get_priv($userdata->user_id, $reading_page);

			if (!(($sendpriv[0] & PRIV_OWNER) && ($readpriv[0] & PRIV_OWNER))) // Why does this not work?
				show_error($userdata->conn, "Refused: page(s) not yours.");
			else
			{
				$r = dbq("replace into msgboard(frame_pageno_send, frame_pageno_list, mb_name, mb_flags) values (?, ?, ?, '')",
					"sis", $sending_page, $reading_page, $boardname);
				if ($r['success'])
					show_prompt($userdata->conn, $boardname." created/modified");
				else
					show_error($userdata->conn, "Refused: ".$boardname." - duplicate?");	
			}
	
		}
	}
	else if (preg_match('/^short\s+([a-zA-Z]+)\s+(DELETE|[1-9][0-9]*)$/i', $str, $matches)) // *SHORT [CODE] [PAGE] - Establish * command which goes to a particular page. If 'PAGE' is 'DELETE' then the command is removed (if it's ours). Can only establish/delete commands which point to pages within our IP range.
	{
		// Check for reserved commands
		$reserved_commands = array (
				'/^fedit/i', '/^fdynamic/i', '/^fstatic/i', 
				'/^fdelete/i', '/^fpreview/i', '/^fpublish/i',
				'/^fflags/i', '/^fcopy/i',
				'/^short/i',
				'/^access/i', '/^adelete/i', '/^acreate/i',
				'/^home/i',
				'/^mboard/i',
				'/^ipset/i',
				'/^ipdelete/i');

		$valid = true;
		foreach ($reserved_commands as $r)
			if (preg_match($r, $matches[1]))
				$valid = false;
			
		if ($valid and $userdata->ip_id) // Is an IP
		{
			$shortcode = strtoupper($matches[1]);
			$pageno = strtoupper($matches[2]);

			// Likewise must check the page being referred to (1) exists (published or unpublished), and (2) is one of our pages.	
			// Obv also cannot have duplicate short
	

			if ($pageno == "DELETE")
			{
				$query = "delete from short where short_name=? and ip_id=?";
				$r = dbq($query, "si", $shortcode, $userdata->ip_id);
				if ($r['affected'] == 0)
					show_error($userdata->conn, "Code not found or not yours.");
				else
					show_prompt($userdata->conn, $r['affected']." code(s) deleted.");
				//@mysqli_free_result($r['result']);
				dbq_free($r);

			}
			else
			{
				$cpdata = page_get_priv($pageno, $userdata->user_id);
				if ($cpdata[0] & PRIV_OWNER)
				{
					$query = "insert into short(short_name, ip_id, frame_pageno) values(?, ?, ?)";
					$r = dbq($query, "sii", $shortcode, $userdata->ip_id, $pageno);
					if ($r['affected'] == 1)
						show_prompt($userdata->conn, "New shortcode $shortcode created.");
					else
					{
						show_error($userdata->conn, "Shortcode creation failed.");
						log_event('Misc', $userdata->user_id, "Shortcode create failed: ".$str);
					}

				}
				else
				{
					show_error($userdata->conn, "Access denied. Not your page.");
					log_event ('Priv Violation', $userdata->user_id, "Attempted ".$str);
				}
	
			}
		}
		else
		{
			show_error ($userdata->conn, "Unauthorised/bad code. Attempt logged.");
			log_event ('Priv Violation', $userdata->user_id, "Attempted ".$str);
		}
	}
	else if (preg_match('/^90$/', $str) and $userdata->logoffpage != false)
		$ret = $userdata->logoffpage;
	else if (preg_match('/^[1-9][0-9]*$/', $str))
	{ // Frame number
		$ret = $str;
	}
	else if (preg_match('/^0$/', $str) or preg_match('/^home$/i', $str))
		$ret = $userdata->homepage;
	else if ($pg = is_short($str, $ip_id))
		$ret = $pg;
	else if (($str != "*") && ($str != "00") && ($str != '09')) // Redisplay
		show_error($conn,"Bad command");
	
	return $ret;	

}
	
function is_short($s, $ip_id) // See if the star command is a short code
{
	$rval = false;
	$r = dbq("select frame_pageno from short where short_name = ? and ip_id = ?", "si", $s, $ip_id);
	if ($r['result'])
	{
		if ($row = @mysqli_fetch_assoc($r['result']))
			$rval = $row['frame_pageno'];
		//@mysqli_free_result($r['result']);
		dbq_free($r);
	}

	return $rval;

}

function run_instance($conn)
{

	global $config;
	global $userdata;
	global $port, $portpres;
	global $port_data;
	

	if ($userdata->console)
	{
		$userdata->remote_host = "Dialup Port";
		$userdata->remote_addr = "-";
		$userdata->remote_port = $portpres;
	}
	else
	{
		socket_getpeername($conn, $raddr, $rport);
		if ($raddr == "127.0.0.1")
			$userdata->remote_host = "Websocket Multiplexor";
		else
			if ($t = gethostbyaddr($raddr))
				$userdata->remote_host = $t;
			else
				$usredata->remote_host = $raddr;

		$userdata->remote_addr = $raddr;
		$userdata->remote_port = $rport;
		debug ("Received connection from $raddr:$rport");
	}

	$userdata->login_time = time();
	$userdata->last_new_msg_check = 0; // Cause a new messages indication at login.

	if ($userdata->console)
		stream_set_blocking($conn, 0);
	else
		socket_set_nonblock($conn);
	// Find an available node to connect to
	// This needs wrapping in a transaction
	// Need a new database connection

	open_db();

	nr_init(); // National rail connection initialise

	ser_output_conn(VCURSOROFF);

	// Start transaction
	dbq_starttransaction();

	$r = dbq("select node_id, node_name, node_baud, node_homepage, node_startpage, node_logoffpage, node_ip_id from node where node_id=?", "i", $port_data['node_id']);
	if ($r['result'])
	{
		if ($r['numrows'] < 1)
		{
			//@mysqli_free_result($r['result']);
			dbq_free($r);
			ser_output_conn("No avaiable nodes. Please try later.\r\n");
			exit();
		}
		else
		{
			$d = @mysqli_fetch_assoc($r['result']);
			$userdata->node_id = $d['node_id'];
			$userdata->node_name = $d['node_name'];
			if ($d['node_baud'] == "1200/75")
			{
				$userdata->tx_baud=1200;
				$userdata->rx_baud=75;
			}
			else
				$userdata->tx_baud=$userdata->rx_baud=$d['node_baud'];

			// Adjust for 7E1 coding to characters per second
			$userdata->tx_rate = 1/($userdata->tx_baud/9);
			$userdata->rx_rate = 1/($userdata->rx_baud/9);
			
			$userdata->last_output = microtime(true);
			$userdata->last_input = microtime(true);
		
			if ($d['node_startpage']) // Overwrite main config
				$config['startpage'] = $d['node_startpage'];

			if ($d['node_homepage']) // Overwrite main config
			{
				$config['homepage'] = $d['node_homepage'];
				$userdata->homepage = $d['node_homepage'];
			}

			if ($d['node_logoffpage']) // Overwrite *90#
				$userdata->logoffpage = $d['node_logoffpage'];
			else	$userdata->logoffpage = false;
	
			$userdata->limit_ip_id = $d['node_ip_id'];
	
		}
		//@mysqli_free_result($r['result']);
		dbq_free($r);

		dbq_commit();
	}
	else
	{
		ser_output_conn("Node communication channel busy. Bye.\r\n");
		dbq_rollback();
		exit();
	}
	ser_output_conn(VCLS);
	show_prompt($conn, SYSBANNER); sleep(1);
	ser_output_conn(VCLS);
	show_prompt($conn, "*** (c) ".date("Y")." Chris Royle ***");
	// Soak up input stray characters

	log_event('Connect', 0, $userdata->remote_host." (".$userdata->remote_addr.":".$userdata->remote_port.") @ ".$userdata->tx_baud."/".$userdata->rx_baud);

	while (ser_input($conn)) { } // Clear buffer

	// Some 1980s hardware delay
	eighties_delay();
	
	move_to_frame($config['startpage']);
	$userdata->frame_previous = $config['startpage']."a";

	$run = true;
	$res_code = null;

	// Look up msg reading page
	$r = dbq("select msgboard.mb_id, frame_pageno_list from information_provider, msgboard where find_in_set('personal', mb_flags) and information_provider.ip_id=".($userdata->limit_ip_id ? $userdata->limit_ip_id : 1)." and information_provider.mb_id = msgboard.mb_id limit 1");
	if ($r['result'])
	{
		if ($r['numrows'] == 1)
		{
			$data = @mysqli_fetch_assoc($r['result']);
			$userdata->msg_reading_page = $data['frame_pageno_list'];
			$userdata->mb_id = $data['mb_id'];
		}
		//@mysqli_free_result($r['result']);
		dbq_free($r);
	}

	if (!($userdata->msg_reading_page))
	{
		ser_output_conn("Msg database failed. Disconnecting.\r\n");
		exit();
	}


	debug ("Initial page set to ".$userdata->frame_current);
	while ($run)
	{

		$allow_keys = true;

		if ($userdata->frame_current == 0)
			move_to_frame($userdata->homepage.'a');

		debug ("run loop - checking frame accessibility for ".$userdata->frame_current);
		if (!check_frame_accessible($userdata->frame_current))
		{
			debug ("check_frame_accessible() returned false");
			show_error($userdata->conn, "Frame not found");
			move_to_frame($userdata->frame_previous);
		}

		if ($userdata->frame_displaymode == FRAMEMODE_UPDATE) // Get a new copy - either from IP or SQL
		{
			$result = load_frame_userdata();
			if ((!$result[0]) or (($userdata->user_id != null) and ($userdata->ip_id != 1) and (preg_match('/nologin/', $userdata->frame_data["frame_flags"]))))
			{
				log_event('Misc', 0, "Cannot load frame ".$userdata->frame_data['frame_pageno'].$userdata->frame_data['frame_subframeid']." or requires user not to be logged in");
				show_error($userdata->conn, "Frame not found");
				move_to_frame($userdata->frame_previous);
				$result = load_frame_userdata();
				if (!($result[0]))
				{
					log_event('Misc', 0, "Cannot load previous frame ".$userdata->frame_previous);
					move_to_frame($userdata->homepage.'a');
					$result = load_frame_userdata();
					if (!$result[0])
					{
						show_error($userdata->conn, "Cannot revert to home page. Disconnecting.");
						log_event('Misc', 0, "Cannot revert to home page. Disconnecting.");
						logoff();
						exit();
					}
				}
			}
		}


		// If we are on a msg reading page, the transmission routine populates the message

		if ((sizeof($userdata->frame_response) > 0) && !($userdata->is_msg_reading)) // We allow interrupt keys if this is a reading page, because the frame will have response fields but we are not going to let the user type in them - we will populate with a message instead - see above
			$allow_keys = false; // Need whole frame to display if there are response fields

		if ($userdata->frame_displaymode == FRAMEMODE_UPDATE || $userdata->frame_displaymode == FRAMEMODE_REDISPLAY)
		{
			send_frame_title_userdata();
			$result = send_frame_userdata(0, $allow_keys);
		}
		else
		{
			// Continue 
			$result = send_frame_userdata($userdata->frame_displayed, true);
		}
		
		if ($userdata->user_id)
			dbq("update user set user_idle_since=NOW(), user_last_node = ? where user_id = ?", "dd", $userdata->node_id, $userdata->user_id);

		$res_code = $result[0];
		$key = $result[1];

		switch($res_code) {
			case TX_DISCONNECT:
				ser_output_conn(VCURSORON);
				to_bottom();
				ser_output_conn(VNUP.VNUP);
				$run = false;
				break;
			case TX_OK:
				if (($userdata->user_id) && (time() - $userdata->last_new_msg_check) >= (10 * 60)) // 10 minute check
				{
					$new_msgs = check_for_new_mail($userdata->mb_id);
					if ($new_msgs > 0)
					{
						show_prompt($userdata->conn, "You have".VTCYN.$new_msgs.VTGRN."unread msg".($new_msgs == 1 ? "" : "s")." - *".$userdata->msg_reading_page."_");
					}
					$userdata->last_new_msg_check = time();
				}

				if ($userdata->is_msg_reading) 
				{
					show_prompt($userdata->conn, ((substr($userdata->frame_current, -1) == $userdata->msg_data['last_subframe']) ? viewdata_to_chr(VTRED)."END.".viewdata_to_chr(VTGRN) : "_ more, ")."Mark <U>nread, <D>el, 0: Index");
					dbq("replace into msg_read(msg_id, user_id, mr_flags) values(?, ?, 'Read')", "ii", $userdata->msg_data['msg_id'], $userdata->user_id);
					log_event('Msg', $userdata->user_id, "Msg ".$userdata->msg_data['msg_id']." marked read");
				}

				if ($allow_keys) // I.e. is this not a response frame?
				{
					$key = array(RX_EMPTY, null);
					$valid_keys = implode("", array_keys($userdata->frame_routes)).VDKEYSTAR.VDKEYENTER;
					if ($userdata->is_msg_reading)
					{
						$valid_keys .= 'udUD0';
						if ($userdata->frame_priv & PRIV_MOD)
							$valid_keys .= 'zZ'; // Z = Delete permanently
					}	
					while ($key[0] == RX_EMPTY)
						$key = ser_input_full($valid_keys, true);
					// Fall through deliberately so we process as if key had been pressed during display
				}
				else
				{
					$key = array(RX_EMPTY, null);
					break;
				}
			case TX_OK_KEY:
				if ($key[0] == RX_UPDATE)
					$userdata->frame_displaymode = FRAMEMODE_UPDATE;
				if ($key[0] == RX_REDISPLAY)
					$userdata->frame_displaymode = FRAMEMODE_REDISPLAY;
				if ($key[0] == RX_KEY_STAR) // Also redisplay because no * route should exist
					$userdata->frame_displaymode = FRAMEMODE_REDISPLAY;
				if ($key[0] == RX_STAR_CMD)
				{
					$input_string = $key[1];
					debug ("Processing star command ".$key[1]);
					$tmp = $userdata->frame_data["frame_pageno"].$userdata->frame_data["frame_subframeid"];
					$frame = process_star($conn, $input_string, $tmp, $userdata->frame_previous, ($userdata->limit_ip_id ? $userdata->limit_ip_id : 1));
					if ($frame != -1) // Rogue for bad command
					{
						if ($frame != $tmp)
							$userdata->frame_previous = $tmp;
						if (preg_match('/[1-9][0-9]*$/', $frame)) // No sub-frame
							$frame .= "a";
						if (check_frame_accessible($frame))
							move_to_frame($frame);
						else
						{
							debug ("check_frame_accessible() returned false after TX_OK_KEY");
							show_error($userdata->conn, "Frame not found");
							$userdata->frame_displaymode = FRAMEMODE_CONTINUE;
						}
					}
					else
					{
						// Reposition cursor
						$startbyte = $userdata->frame_displayed;
						if (!$startbyte) $startbyte = 0;
						goto_xy($userdata->conn, ($startbyte % 40), intval($startbyte/40)+1);
						$userdata->frame_displaymode = FRAMEMODE_CONTINUE; // If we got a bad command, keep going from where we left off - possibly the end!
					}
				}
				if ($key[0] == RX_KEY)
				{
					if ($key[1] == "_" or $key[1] == chr(0x0d))
					{
						$subframeid = $userdata->frame_data["frame_subframeid"];
						if ($subframeid != "z")
						{	
							//$userdata->frame_previous = $userdata->frame_data["frame_pageno"].$userdata->frame_data["frame_subframeid"];
							$userdata->frame_previous = $userdata->frame_current;
							$userdata->frame_current = substr($userdata->frame_current, 0, -1).chr(ord(substr($userdata->frame_current, -1))+1);
							//$userdata->frame_data["frame_subframeid"] = chr(ord($userdata->frame_data["frame_subframeid"])+1);
							$userdata->frame_displaymode = FRAMEMODE_UPDATE; // Cause a fetch
							//debug ("Advancing to ".$userdata->frame_data["frame_pageno"].$userdata->frame_data["frame_subframeid"]);
							debug ("Advancing to ".$userdata->frame_current);
						}
						else if ($subframeid == "z" and $userdata->frame_data["frame_next"])
							move_to_frame($userdata->frame_data["frame_next"]);
						else
							show_error($conn, "Cannot progress.");
					}
					else if ($userdata->is_msg_reading) // Process U, D, 0; Z for moderators
					{
						$msg_index_frame = substr($userdata->frame_current, 0, -4);
						$subframe_number = intval(substr($userdata->frame_current, -4, 3)/5); // Pick out the 3 digit tail in the frame number and divide by 5, take the integer and that gets us the subframe number.
						$subframe_number = chr(ord('a')+$subframe_number); // Convert to a, b, c, etc.
						$msg_index_frame = $msg_index_frame.$subframe_number;
						switch ($key[1])
						{
							case 'u':
							case 'U': // Mark unread
								debug ("Mark message ID ".$userdata->msg_data['msg_id']." unread");
								log_event('Msg', $userdata->user_id, "Msg ".$userdata->msg_data['msg_id']." marked unread");
								dbq("delete from msg_read where msg_id = ? and user_id = ?", "ii", $userdata->msg_data['msg_id'], $userdata->user_id);
								move_to_frame($msg_index_frame);	
								break;
							case 'd':
							case 'D': // Delete
								debug ("Delete message ID ".$userdata->msg_data['msg_id']);
								log_event('Msg', $userdata->user_id, "Deleted msg ".$userdata->msg_data['msg_id']);
								dbq("replace into msg_read values(?, ?, 'Deleted')", "ii", $userdata->msg_data['msg_id'], $userdata->user_id);
								move_to_frame($msg_index_frame);	
								break;

							case '0': // Back to msg index page
								move_to_frame($msg_index_frame);	
								break;
							case 'z': // Moderator permanent delete
							case 'Z': 
								if ($userdata->frame_priv & PRIV_MOD)
								{
									debug ("Permanently delete message ID by moderator ".$userdata->msg_data['msg_id']);
									log_event('Msg', $userdata->user_id, "Permanent delete msg ".$userdata->msg_data['msg_id']);
									dbq("delete from msg_read where msg_id = ?", "i", $userdata->msg_data['msg_id']);
									dbq("delete from msg where msg_id = ?", "i", $userdata->msg_data['msg_id']);
									move_to_frame($msg_index_frame);
								}
								break;
						}	
					}
					else if (isset($userdata->frame_routes[$key[1]]))
					{
						// Do the page action here
						if ($userdata->frame_routes[$key[1]][0] == "Page") // Possible array referencing bug here - what have we loaded into frame_routes?
						{
							//$userdata->frame_previous = $userdata->frame_data["frame_pageno"].$userdata->frame_data["frame_subframeid"];
							$userdata->frame_previous = $userdata->frame_current;
							if ($userdata->frame_routes[$key[1]][1] == 0)
								move_to_frame($userdata->homepage);
							else
								move_to_frame($userdata->frame_routes[$key[1]][1]);
						}
					}
					else
					{
						show_error($conn, "No such route.");
						$userdata->frame_displaymode = FRAMEMODE_CONTINUE; // Keep going from where we left off
					}
				}
				break; // From the res_code switch
			case TX_NOTFOUND:
				move_to_frame($frame_previous);
				if (!is_published($frame_previous) && ($userdata->preview && !is_unpublished($frame_previous)))
					move_to_frame($userdata->homepage);
				break;
			case TX_HANGUP:
				$run = false;
				break;			
		}	

		// Process response frame here

		if (!$allow_keys)
		{
			$frame = response_frame();
			debug ("return from response frame is ".$frame);
			$userdata->frame_previous = $userdata->frame_current;
			move_to_frame($frame);
		}

	} // While run	
	
	logoff($conn);
}

function phoenix($sock)
{

	global $userdata;
	global $port;

	if (preg_match('/^\//', $port))
		$userdata->console = true;
	else	$userdata->console = false;

	if ($userdata->console) // We are in console mode - run and then exit
	{
		$userdata->conn = $sock;
		run_instance($sock);
		exit();
	}

	while ($conn = socket_accept($sock))
	{
		$pid = pcntl_fork();


		if ($pid == -1)
		{
			ser_output_conn("Significant systems failure. Disconnecting.");
			socket_close($conn);
			debug("Couldn't fork. Dying.");
			exit("Error forking...\n");
		}
		else if ($pid == 0) // Child
		{
			debug("Child process created on inbound connection.");
			$userdata->conn = $conn;
			// Clear screen
			ser_output_conn(VCLS);
			run_instance($conn);
			debug("Forked child ended.");
			socket_shutdown($conn, 2);
			socket_close($conn);
			exit();
		}
		else 
			debug("Forked child ".$pid." on inbound connection.");
		
			
			
	}

}

$userdata = new User; // This is a blank one which gets used when there's a fork.
phoenix($sock);

?>
