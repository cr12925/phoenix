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

define('TX_OK', -1); // Line output but no key pressed
define('TX_OK_KEY', -2); // Key was pressed - second parameter has the key
define('TX_HANGUP', -3); // User had hung up - time to end.
define('TX_NOTFOUND', -4); // Frame not found
define('TX_ERROR', -5); // Something undefined went wrong in frame transmit
define('TX_DISCONNECT', -6); // Disconnect at end of frame transmission

function logoff($conn = false)
{
	global $userdata;

	if (isset($userdata->user_id))
		dbq("update user set user_idle_since=null, user_last_logoff=NOW() where user_id=?", "i", $userdata->user_id);
	debug ("Logged off node id ".$userdata->node_id.", user ".$userdata->user_id);

	log_event ('Logoff', $userdata->user_id ? $userdata->user_id : 0, "");
	log_event ('Disconnect', $userdata->user_id ? $userdata->user_id : 0, "");

	sleep(3); // Clear buffers etc.

	@socket_shutdown($conn,2);
	@socket_close($conn);
	exit();
}

// Can ONLY be used once there is a connection because it looks up the node id from the user data
function log_event($event, $data1 = null, $data2 = null)
{

	global $userdata;

	$query = "
INSERT INTO log(node_id, log_event, log_data_1, log_data_2) 
VALUES (?, ?, ?, ?)";

	$r = dbq($query, "isis", $userdata->node_id, $event, $data1, $data2);

	// R['result'] will be false on an insert.
	//if (!$r['result'])
		//debug ("Log insert error on query $query - $userdata->node_id - $event - $data1 - $data2");

}

function cleanup($sig)
{
	global $sock, $userdata;
	debug ("Caught signal - Cleaning up.");
	socket_shutdown($sock,2);
	socket_close($sock);
	exit();
}
	
// ser_output_conn
// Sends data to the other end without checking for keys. Used to
// avoid recursion into ser_output
function ser_output_conn ($data) 
{

	// returns TX_OK, TX_HANGUP
        
	global $userdata;

	$retval = array(TX_OK, 0);


        for ($counter = 0; $counter < strlen($data); $counter++)
        {
		if (!$userdata->console) // Fake delays for baud rate. If we are actually on a tty, the hardware will do it (ie. no need to fake it because the delay will be real!)
		{
        		$interval = microtime(true) - $userdata->last_output;
			if ($interval < $userdata->tx_rate)
				usleep(intval(($userdata->tx_rate-$interval)*1000000));
		}

		$byte = ord($data[$counter]);

		if ($userdata->console)
		{
			if ($byte > 127)
				$res = fwrite($userdata->conn, "\x1b".chr($byte-64), 2);
			else
				$res = fwrite($userdata->conn, chr($byte), 1);
		}
		else
		{
			if ($byte > 127) // Escape high-bit control codes
				$res = @socket_write($userdata->conn, "\x1b".chr($byte-64),2);
			else
                		$res = @socket_write($userdata->conn, chr($byte), 1);
		}
	
		$userdata->last_output = microtime(true);
		
		if ($res == false)
			return array(TX_HANGUP, $counter+1);
        }

	return array(TX_OK, $counter);
}

function ser_output ($conn, $data, $valid_keys = false) // Sends data out to the user at the right baud speed and checks for valid keystrokes. If $valid_keys is false, then any key will do.
{

	// Returns either:
		//(TX_OK, false)
		//(TX_OK_KEY, key)
		//(TX_HANGUP, false)
        
	global $userdata;

	$key = false;
	$retval = TX_OK;

        for ($counter = 0; $counter < strlen($data); $counter++)
        {
		if ($valid_keys)
		{
			$key = ser_input_full($valid_keys); // Non-blocking key check
			if ($key[0] != RX_EMPTY)
			{
				if ($key[0] == RX_ERROR) // Socket problem
				{
					$retval = TX_HANGUP;
					break;
				}
				else	
				{
					$retval = TX_OK_KEY;
					break;
				}
			}	
		}

		$res = ser_output_conn($data[$counter]);
		if ($res == TX_HANGUP)
		{
			$retval = TX_HANGUP;
			break;
		}
		
        }

	return(array($retval, $key));
}

function ser_output_conn_keys ($data, $valid_keys = false) // Sends data out to the user at the right baud speed and checks for valid keystrokes. If $valid_keys is false, then any key will do.
{

	// Returns either:
		//(TX_OK, false, bytes sent)
		//(TX_OK_KEY, key, bytes sent)
		//(TX_HANGUP, false, bytes sent)
        
	global $userdata;

	$key = false;
	$retval = TX_OK;

        for ($counter = 0; $counter < strlen($data); $counter++)
        {
		if ($valid_keys)
		{
			$key = ser_input_full($valid_keys); // Non-blocking key check
			if ($key[0] != RX_EMPTY)
			{
				if ($key[0] == RX_ERROR) // Socket problem
				{
					$retval = TX_HANGUP;
					break;
				}
				else	
				{
					$retval = TX_OK_KEY;
					break;
				}
			}	
		}

		$res = ser_output_conn($data[$counter]);
		if ($res[0] == TX_HANGUP)
		{
			$retval = TX_HANGUP;
			break;
		}
		
        }

	if ($retval == TX_HANGUP || $retval == TX_OK_KEY) // Interrupted - so increment counter to be accurate
		$counter++;

	return(array($retval, $key, $counter));
}

// This function won't return unless it has a valid character
// This one handles high bit characters if included in $valid
function ser_input_insist($valid = false, $allow_block = false, $upper = false)
{

	global $userdata;

	$recv = 0;

	$highbit = false;
	$highbit_chars = $non_highbit_chars = "";
	$valid_escape = false;

	for ($c = 0; $c < strlen($valid); $c++)
	{
		if (ord($valid[$c]) > 128 and ord($valid[$c]) <= 192)
		{
			$highbit = true;
			if (ord($valid[$c]) <= 192)
			{
				//debug ("Found high-bit character ".$valid[$c]." -- ord = ".ord($valid[$c]));	
				$highbit_chars .= chr(ord($valid[$c])-64);
			}
		}
		else	$non_highbit_chars .= $valid[$c];

		if ($valid[$c] == ESC) // Escape
			$valid_escape = true;
	}

	if ($highbit and $valid_escape)
		$highbit_chars .= ESC; // Add ESC so the user can do ESC-ESC

	if (!$valid_escape and $highbit) // Need to add ESC to the valid list of non-highbit characters so that the user can press ESC to input a high bit character
		$non_highbit_chars .= chr(27);

	$current_valid = $non_highbit_chars;
	$current_escape = false;

	//debug ("ser_input_insist: valid = $valid, highbit = ".($highbit ? "true" : "false").", highbit_chars = /".$highbit_chars."/, non-high-bit-chars = /".$non_highbit_chars."/");
	while (true) // Avoid fuzzy matching on "0"
	{
		$recv = ser_input($userdata->conn, ($current_escape ? $highbit_chars : $non_highbit_chars), $allow_block, $upper);
		//debug ("ser_input returned ord ".ord($recv). " -- is zero? ".($recv === 0 ? "yes" : "no"));
		if ($recv < 0)
		{	logoff(); exit(); }
		if ($recv === 0) // Really got a zero
		{
			if  (!$allow_block) // Get out if not blocking
				break; // Empty, but non-blocking, so get out
		}
		else if (!$current_escape and $highbit and $recv == chr(27)) // Escape received in normal text
		{
			//debug ("ser_input_insist: Escape received with high bit enabled and not in escape mode - moving to escape mode.");
			$current_escape = true;
		}
		else if ($current_escape and $recv == chr(27)) // Esc-Esc
		{
			//debug ("ser_input_insist: Esc-Esc received");
			$current_escape = false;
			break; // Return the escape character
		}
		else if ($current_escape and $recv !== 0) // Esc-Something else
		{
			//debug ("ser_input_insist: Esc-somthing else received - turning off escape mode");
			$current_escape = false;
			$recv = chr(64+ord($recv));
			break; // Return the unescaped character
		}
		//else if ($recv !== 0)	{
		else if (ord($recv) > 0 and ord($recv) < 128) {
			//debug ("ser_input_insist: Non-Esc received - returning");
			break;	// Valid character - get out
		}
	}

	return $recv;

}

function ser_input ($conn, $valid = false, $allow_block = false, $upper = false)
{

	global $userdata;
	$interval = microtime(true) - $userdata->last_input;	

	if (!$userdata->console) // Fake input baud - otherwise hardware does it for real
	{
		if (($interval < $userdata->rx_rate) && ($allow_block === false))
			return(false);
		else if ($interval < $userdata->rx_rate)
			usleep (($userdata->rx_rate - $interval) * 1000000);
	}

	// Now see if the socket would block on input

	if (!$userdata->console)
	{
		if ($allow_block)
			socket_set_block($conn);
		else
			socket_set_nonblock($conn);

		$recv = @socket_read($conn,1); // Take anything
	}
	else
	{
		if ($allow_block)
			stream_set_blocking($conn, 1);
		else
			stream_set_blocking($conn, 0);

		$recv = fgetc($conn);

	}

	$userdata->last_input = microtime(true);
	
	// Return false if we mysteriously get a character with bit 8 set
	if ($recv > 127) // Noise
		$recv = false;
	else if ($recv === false) // Some form of error
	{	
		if (!$userdata->console)
		{
			$error = socket_last_error($conn);
			if ($error != SOCKET_EWOULDBLOCK)
			{
				debug ("Input receive error: No. ".$error." (".socket_strerror($error).")");
				$recv = -1 * $error;	
			}
			// Means a return of 'false' means no data, a charater is the data received, and a negative figure is -1 * the socket error code;
		}
		else // Console
		{


		}
	}
		
	if ($recv < 0) // Error on receive
	{
		// This needs moving or getting rid of
		debug ("ser_input() got disconnect for user id ".$userdata->user_id);
		logoff($conn);
	}

	if ($upper)
		$recv = strtoupper($recv);
	// Return false if this is not a character we are allowed to accept
	if ($valid !== false)
	{
		$found = false;
		for ($counter = 0; $counter < strlen($valid); $counter++)
		{
			if ($valid[$counter] === $recv)
			{
				$found = true;
				break;
			}
		}

		if ($found === false)
			$recv = intval(0);
	}

	if ($userdata->console)
		stream_set_blocking($conn, 0);
	else
		socket_set_nonblock($conn);
	
	return ($recv);
}

// ser_input_str
// Accepts an input string with delete processing
// Single line only
// Up to maximum length
// $len - max length
// $starstar - whether the routine will return a "*" if first typed character is a *. Used for * command processing
// $showhash - Whether the trailing 'hash' character is displayed when the user presses enter
function ser_input_str($len, $valid = false, $starstar = false, $showhash = false)
{
	global $userdata;

	$input_str = "";

	$last_char = false;

	if ($starstar) // Catch the special ** redisplay code
		$valid .= "\*";
	
	$valid .= chr(0x0d)."_"; // Otherwise we can't end the string!

	while ($last_char !== '_' and $last_char !== 0x0d) // the Enter characters
	{
		$last_char = ser_input_insist($valid, true);

		if ($last_char !== false and $last_char !== 0) // We got neither int(0) [invalid] nor was it "0" which fuzzy matches to 0
		{
			if ((strlen($input_str) == 0) && ($last_char == "*") && ($starstar))
			{
				ser_output_conn($last_char);
				$input_str = "*";
				break; // Don't like this, but it gets us out
			}
			else if ((preg_match('/^0[0-9]$/', $input_str.$last_char)) && ($starstar))
			{
				ser_output_conn($last_char);
				$input_str .= $last_char;
				break; // I still don't like this.
			}
			else if ($last_char != '_' and $last_char != 0x0d) // We have input
			{
				if (($last_char == VDKEYBACKSPACE) || ($last_char == VNLEFT)) // Some clients seem to send 8 instead of backspace
				{ // Backspace & delete
					if (strlen($input_str) > 0)
					{
						ser_output_conn(VNLEFT.' '.VNLEFT);
						$input_str = substr($input_str, 0, strlen($input_str)-1);
					}
				}
				else
				{
					if (strlen($input_str) < $len)
					{
						$input_str .= $last_char;
						ser_output_conn($last_char);
					}
				}	
			} // IF statement checking for return
			else // Return character
				if ($showhash)
					ser_output_conn("_");
		}
	} // While loop

	return ($input_str);

}

// ser_input_str_full
// Accepts an input string with delete processing but handles * commands as well, and ESC codes if included in the valid string
// Single line only
// Up to maximum length
// $len - max length
// $xy - single integer position in the frame data where the string starts - used to put original characters back on delete. Uses spaces if false
// $valid - valid string characters - to which we will add return/hash so as to terminate. If VNUP/VNDOWN are included, special return codes for those so that a response frame editor can pick them up. Similarly ESC.
// $showhash - Whether the trailing 'hash' character is displayed when the user presses enter
// $start_str - Current string (used in response frames on redisplay)
// $password - whether to show *s because this is a password input

define ('RX_STRING', 50); // Extra return code compared to ser_input_full (i.e. the single character version)
define ('RX_NAVUP', 51); // Tells response editor user has navigated up to get out of the field
define ('RX_NAVDOWN', 52); // Likewise, but down

function ser_input_str_full($len, $xy, $valid, $showhash = false, $start_str = "", $password = false, $not_in_frame = false)
{
	global $userdata;

	$input_str = $start_str;
	$starstar = true;

	$ret = array(RX_STRING, "");

	$last_char = false;

	$valid .= VDKEYBACKSPACE.chr(0x0d)."_"; // Otherwise we can't end the string!

	$editing_string = true;
		
	if (!$not_in_frame) // Because if in frame, we need to position ourselves. If not in frame, we'll be at the end of "current data" anyway.
		for ($counter = 0; $counter < strlen($start_str); $counter++)
			ser_output_conn(VNRIGHT);

	$allow_star = "*";
	if ($not_in_frame) $allow_star = ""; // Not in frame means we aren't editing a response field so we don't allow a * and we don't fill in the frame background on delete
	while ($editing_string) 
	{
		$last_char = ser_input_insist($valid."*", true);
	

		if (!$not_in_frame && ($last_char === '*')) // Handle as * command if we ARE in a frame
		{
			$star_string = phoenix_star();
			if ($star_string === "00") // Redisplay frame
				$ret = array(RX_REDISPLAY, $input_str); // We return the actual input string here, not 00 so that a response editor can store it
			else if ($star_string === "09")
				$ret = array(RX_UPDATE, $input_str);
			else $ret = array(RX_STAR_CMD, $star_string); // This time we don't return the string - it gets lost

			if ($star_string != "*") // I.e. not inserting a * character
				$editing_string = false;

			ser_output_conn(VCURSOROFF);
			goto_xy_pos($xy + strlen($input_str));
			ser_output_conn(VCURSORON);
		}

		// If we have processed a star command but editing_string remains true, then the input was **, so insert a * in the string
		// But we need to re-check it's in the valid list because we artificially add it to the ser_input_insist call so that we
		// Can process star commands.

		// Only do this bit if still editing after we've processed any Star command, since the * may not be the character to insert
		if ($editing_string and $last_char !== false and $last_char !== 0) // Something potentially valid arrived
		{

			if ($last_char == '*')
			{	// Check valid
				if (!strpos($valid, "*"))
					$last_char = null;
			}

			if ($last_char == VNUP) // Will only be if it was in the valid string
			{
				$ret = array(RX_NAVUP, $input_str);
				$editing_string = false;
			}
			else if ($last_char == VNDOWN) // Likewise VNUP
			{
				$ret = array(RX_NAVDOWN, $input_str);
				$editing_string = false;
			}
			else if (($last_char == VNLEFT) || ($last_char == VDKEYBACKSPACE)) // Delete character
			{
				if (strlen($input_str) > 0)
				{
					if ($not_in_frame)
						$deleted_char = ' ';
					else
						$deleted_char = $userdata->frame_data['frame_content'][$xy+strlen($input_str)-1];
					ser_output_conn(VNLEFT.$deleted_char.VNLEFT);
					$input_str = substr($input_str, 0, strlen($input_str)-1);
				}
				// Otherwise we're at the start of the string - no delete
			}
			else if ($last_char === null)
			{	// Do nothing - it was a failed * 
				debug ("Ignored failed *");
			}
			else if ($last_char != '_' and $last_char != 0x0d) // Some valid key apart from return and the ones we catch above
			{
				if ($last_char == chr(0x1b)) // ESC - Limited input for second character
				{
					// Wait for second input
					$second_input = ser_input_insist("ABCDEFGHILQRSTUVWYZ\\]", true);
					$last_char = chr(ord($second_input)+64);
				}

				if (strlen($input_str) < $len)
				{
					$input_str .= $last_char;
					if ($password)
						ser_output_conn($password);
					else
						ser_output_conn($last_char);
				}
			} // IF statement checking for return
			else // Return character
			{
				if ($showhash)
					ser_output_conn("_");
				$ret = array(RX_STRING, $input_str);
				$editing_string = false;
			}

		}
	} // While loop

	return ($ret);

}

// Lookup frame_id from frame table. 
// Return +n if frame exists only as a published frame
// Return -n if frame exists as an unpublished frame, whether or not it is also published
// In the case of -n, n will be the unpublished frame id
// Return 0 for error or not found

function get_frame_id ($frame)
{

	$frame = trim(strtolower($frame));
	$ret = 0; // Default is to say not found

	if (preg_match('/^(\d+)([a-z])$/', $frame, $matches))
	{
	
		debug ("Checking frame existence for [".$matches[1]."] [".$matches[2]."]");
		if (is_dynamic($matches[1]))
			$ret = 999999999999999;
		else
		{
			$query = "
select 
	frame_id, 
	find_in_set('unpublished', frame_flags) as unpublished
from	frame
where	frame_pageno = ? and frame_subframe_id = ?";

			$r = dbq($query, "is", $matches[1]. $matches[2]);
	
			if ($r['result']) 
			{	// If SQL failure, return default 0

				$frame_list = array();
				while ($row = @mysqli_fetch_assoc($r['result']))
				{
					if (($row['unpublished']) && ($ret >= 0)) // I.e. we either already have a published frame or nothing	
						$ret = -1 * $row['frame_id'];
					else
						$ret = $row['frame_id'];
				}
				@mysqli_free_result($r['result']);
			}
		}
	}

	return ($ret);

}


function published_yn($frame, $yn)
{

	$pageno = substr($frame, 0, strlen($frame)-1);
	$subframeid = substr($frame, -1);
	if ($yn)
		$flag = "!";
	else	$flag = "";

	$ret = false;

	$query = "select frame_id from frame where frame_pageno=? and frame_subframeid=? and ".$flag."find_in_set('unpublished', frame_flags)";
	$r = dbq($query, "is", $pageno, $subframeid);
	if ($r['result'])
	{
		if ($row = @mysqli_fetch_assoc($r['result']))
			$ret = $row['frame_id'];
		else	$ret = false;
		@mysqli_free_result($r['result']);
	}

	return $ret;

}

function is_published($frame)
{
	return published_yn($frame, true);
}

function is_unpublished($frame)
{
	return published_yn($frame, false);
}

function is_public($frame_id)
{

	$query = "select frame_flags from frame where frame_id = ?";
	$answer = false;
	$r = dbq($query, "i", $frame_id);
	if ($r['result'])
	{
		$row = @mysqli_fetch_assoc($r['result']);
		@mysqli_free_result($r['result']);
		if (preg_match('/login/', $row['frame_flags']))
			$answer = true;
	}
	
	return $answer;
}

define('CA_IP', 1); // User is the IP for the area
define('CA_OWNER', 2); // User not IP, but has owner rights
define('CA_MODERATOR', 3); // Moderator
define('CA_USER', 4); // User

// check_ip_permission will return one of the above constants
// depending on what status the user has
// We are fed a frame ID including subframe

function check_ip_permission($frame)
{
	global $userdata;

	$ret = false;
	
	debug ("check_ip_permission($frame)");
	if ($userdata->ip_id == 1) // System IP is IP for all frames
		$ret = CA_IP;
	else
	{
		if (!preg_match('/^([1-9][0-9]*)([a-z])$/', $frame, $matches))
			return false; // Invalid frame ID
		else
		{
			$frame_pageno = $matches[1];
			$frame_subframeid = $matches[2];
		}

		// See what rights we have if any. 
		$query = "
select	area.area_public, area.area_id
from	frame join area on frame.area_id = area.area_id where frame.frame_pageno=? and frame.frame_subframeid='a'"; // Only check the 'a' frame so that its area permission applies to all subframes
		$r = dbq($query, "i", $frame_pageno);
		if ($r['result'] && ($r['numrows'] == 0)) // Assume no area entry and the frame is public
		{
			debug("Frame $frame - no area table entry - Assuming public frame");
			return CA_USER;
		}
		if ($r['result'])
		{
			$row = @mysqli_fetch_assoc($r['result']);
			@mysqli_free_result($r['result']);
			$area_public = $row['area_public'];
			$area_id = $row['area_id'];

			if (!isset($userdata->user_id)) // Not yet logged in
				if ($area_public == "Public")
					return CA_USER; // NB, separate check that the page requires or does not require login is done elsewhere.
	
			$query = "
select	ap_invert,
	ap_permission
from	area_permission
where	user_id = ? 
and	area_id = ?";
			$r = dbq($query, "ii", $userdata->user_id, $area_id);
			if ($r['result'])
			{
				$numrows = $r['numrows'];
				if ($numrows >= 1)
				{
					$row = @mysqli_fetch_assoc($r['result']);
					$ap_invert = $row['ap_invert'];
					$ap_permission = $row['ap_permission'];
					if ($ap_invert == "Negative")
						$ret = false;
					else
					{
						switch ($ap_permission)
						{
							case 'Moderator': $ret = CA_MODERATOR; break;
							case 'User': $ret = CA_USER; break;
						}
					}
				}
				else
				if ($area_public == "Public")
					$ret = CA_USER;
				@mysqli_free_result($r['result']);
			}
		}
	}
	
	return $ret;
}

// Returns true if we are the owner of the area id specified

function check_area_owner($area_id)
{

	$ret = false;

	global $userdata;

	if ($userdata->ip_id == 1)
		return true; // Superuser

	$query = "
select	area_id
from	area
where	area_id = ?
and	ip_id = ?";

	$r = dbq($query, "ii", $area_id, $userdata->ip_id);
	if ($r['result'])
	{
		if ($r['numrows'] == 1)
			$ret = true; // We got one row back so must have matched
		@mysqli_free_result($r['result']);
	}

	return $ret;

}


// Converts area name to area ID

function area_name_to_id($area_name)
{

	$ret = false; // Not found by default

	$area_name = mysql_escape_string($area_name); // Protect

	$query = "
select	area_id
from	area
where	area_name = UPPER(?)
";

	$r = dbq($query, "s", trim($area_name));
	
	if ($r['result'])
	{
		$row = @mysqli_fetch_assoc($r['result']);
		@mysql_free_result($r['result']);
		$ret = $row['area_id'];
	}

	return $ret;

}

function check_frame_accessible($frame)
{

	global $userdata, $config;

	// Either:
	// 1: There is a published frame (TBI: in an area we are a member of if we are not the IP)
	// 2: IP for the frame and there is an unpublished frame and we are in preview mode

	// Need to cope with msg index and msg reading pages, which are 'spoofed'.

	if ($p = is_msg_reading_page(substr($frame,0,-1), substr($frame,-1)))
	{
		debug ("check_frame_accessible($frame) detected msg reading page - sending page is $p.");
		$frame = $p; // Check for existence of the underlying 'send' page because that's the data we need.
		// NOte the above does mean that if the send page is in an area the reader cannot see,
		// The message won't display.
	}

	if ($p = is_msg_index(substr($frame, 0, -1), substr($frame, -1)))
		$frame = substr($frame, 0, -1)."a"; // Always check for existence of the 'a' page.

	$frame_id = is_published($frame);
	$up_frame_id = is_unpublished($frame);
	$dynamic = is_dynamic(substr($frame, 0, strlen($frame)-1));
	$privs = page_get_priv(substr($frame, 0, -1), $userdata->user_id);

	if ($frame_id && $privs[0] > 0)	return true; // Published frame and we can read it.
	if ($up_frame_id && ($privs[0] & PRIV_OWNER)) return true; // We're either superuser or owner

	// See if we are IP for the area or have access to it
	//if (!check_ip_permission($frame))
		//return false;

	if ($dynamic)
		return true;

	if ($frame_id)
		return true;

if (false) 
	if (($frame_id > 0) || (($frame_id < 0) && check_privs($frame))) // The check privs checks to see if we can edit/preview - is it in our IP space?
	{
		// Can we access it?
		// See if it requires us to be logged in first
		if ($frame_id < 0) $frame_id = $frame_id * -1;
		$query = "select frame_flags from frame where frame_id = ?";
		$r = dbq($query, "i", $frame_id);
		if ($r['result'])
		{
			$row = @mysqli_fetch_assoc($r['result']);
			@mysqli_free_result($r['result']);
			$login_flag = preg_match('/login/', $row['frame_flags']);
			if (!$userdata->user_id) // Not logged in - so frame MUSTN'T have login flag set
			{
				if (!$login_flag)
				{	debug ("check_accessible(): Frame $frame does not have login flag set. Accessible.");
					return true;
				}
			}
			else // We ARE logged in - any frame in permitted areas is accessible (at the moment this includes the login frame)
			{
				// If it's the login frame and we are not superuser, then refuse
				if ($frame == $config['startpage']."a" && (intval($config['superuser']) != $userdata->user_id))
				{
					debug ("check_accessible($frame): Logged in but on start page and not superuser - false");
					return false;
				}

				if (intval($config['superuser']) == $userdata->user_id)
				{
					debug ("check_accessible($frame): Logged in as superuser - true");
					return true; // We can see what we like
				}

				debug ("check_accessible($frame): Logged in normal user - return true");
				// Somewhere in here we'll check area permissions as well
				return true; // Either no login flag, or we are editing or previewing.
			}
		}
			
	}

	debug ("check_accessible($frame): Frame not found.");
	return false;
}
	
	
function to_bottom($conn = false)
{
        ser_output_conn(VNHOME.VNUP);
}

function show_error($conn, $str)
{
        to_bottom();
        ser_output_conn(VTRED.sprintf("%-38s",substr($str,0,38)));
        sleep(2);
}

function show_prompt($conn, $str)
{
        to_bottom();
	ser_output_conn(sprintf("%-39s".chr(0x0d).VTGRN."%-s", "", substr($str,0,38)));
        //ser_output_conn(VTGRN.sprintf("%-38s",substr($str,0,38)));
}

function goto_xy_pos($pos) // This is a frame position - needs converting to X, Y
{
	global $userdata;
	
	$x = ($pos % 40);
	$y = intval(($pos+40) / 40);

	goto_xy ($userdata->conn, $x, $y);
}

function goto_xy($conn, $x, $y)
{

	// Bounds check

	if ($y > 23) $y = 23;
	if ($x > 39) $x = 39;

	ser_output_conn(VNHOME);
	for ($counter = 1; $counter <= $y; $counter++)
		ser_output_conn(VNDOWN);
	for ($counter = 1; $counter <= $x; $counter++)
		ser_output_conn(VNRIGHT);

}

// phoenix_star()
// Returns a string - the input from the user
function phoenix_star()
{
	global $userdata;
        to_bottom();
        ser_output_conn(sprintf("%39s", "").VNLINESTART.VSTAR_PROMPT);
        $input_string = ser_input_str(35,"+-".VDKEYALPHANUMERIC.VDKEYSPACE.VNLEFT.VDKEYBACKSPACE, true, true);
        return ($input_string);
}

// ser_input_full($keys, $block)
// $keys is a string of permissible input keys
//    e.g. on a frame, this may well be the set of routes + *
//         during editing graphics, probably just ESC, Ctrl-L and the graphics edit keys
// $block - wait for input if buffer empty
// This hands * commands if a * is pressed as the character input
// However, the calling function MUST remember where the cursor
// Was on screen and be prepared to put it back there 
// on return if necessary.
//
// Returns a tuple as follows
// (RX_FUNCTION (see below), metadata)
// Metadata varies depending on what RX_FUNCTION is.
define ('RX_KEY', 1); // A key has been pressed. Metadata is the single character keypress
define ('RX_KEY_STAR', 2); // ** Was pressed - to input a star. Cursor reposition required
define ('RX_STAR_PAGE', 3); // A page number was put in as a star command
define ('RX_REDISPLAY', 4); // *00 - NB, if the frame is a response frame, the input to date should be redisplayed
define ('RX_UPDATE', 5); // *09 - Redisplay and update (e.g. call to IP if necessary)
define ('RX_STAR_CMD', 6); // Textual star command (e.g. *edit, *publish, etc.)
define ('RX_ERROR', 97); // Something went wrong - error from socket_read
define ('RX_EMPTY', 98); // Non-blocking but buffer empty
define ('RX_DISONNECT', 99); // Line disconnected - logoff

function ser_input_full($valid = false, $allow_block = false)
{

	global $userdata;

	$ret = array(RX_EMPTY, null);

	$input = ser_input ($userdata->conn, $valid, $allow_block);
	if ( ( ($input === false) || ($input === 0) ) && (!$allow_block)) // No valid input
		return array(RX_EMPTY, null);

	if ($input < 0) // Error
	{
		if (
			($input == (-1 * SOCKET_ECONNABORTED))
		||	($input == (-1 * SOCKET_ENOTCONN))
		|| 	($input == (-1 * SOCKET_ETIMEDOUT))
		||	($input == (-1 * SOCKET_ECONNRESET))
		)
			return array(RX_DISCONNECT, null);
		else
			return array(RX_ERROR, (-1 * $input));
	}

	if ($allow_block && (($input === 0) || ($input === false))) // We got here with invalid input on a blocking connection
	{	// Wait for some valid input
		while ( ($input === false) || ($input === 0) )
			$input = ser_input ($userdata->conn, $valid, $allow_block);
	}

	if ($input === "*")
	{
			// Process *
			$star_string = phoenix_star();
			if ($star_string == "*")
				$ret = array(RX_KEY_STAR, "*");
			else if ($star_string == "00" && (strlen($star_string) == 2))
				$ret = array(RX_REDISPLAY, "00");
			else if ($star_string == "09")
				$ret = array(RX_UPDATE, "09");
			else
				$ret = array(RX_STAR_CMD, $star_string);
	}
	else if (($input !== false) && ($input !== 0))
		$ret = array(RX_KEY, $input);
		
	return ($ret);

}

// move_to_frame - set up the userdata variables for the frame & force a refresh
function move_to_frame ($frame)
{
	global $userdata;
	if (!preg_match("/[a-z]$/", $frame))
		$frame .= "a";
	//$userdata->frame_data["frame_pageno"] = substr($frame, 0, -1);
	//$userdata->frame_data["frame_subframeid"] = substr($frame, -1);
	$userdata->frame_current = $frame; 
	$userdata->frame_displaymode = FRAMEMODE_UPDATE;
}
	
//pcntl_signal(SIGINT, "cleanup");

function get_area_id_by_name ($area_name, $ip_id = null)
{

	$retval = false;

	$area_name = strtoupper($area_name);
	$query = "select area_id from area where area_name = ?";
	if (($ip_id) && ($ip_id != 1)) // Superuser doesn't get restricted
	{
		$query .= " and ip_id = ?";
		$r = dbq($query, "si", $area_name, $ip_id);
	}
	else	$r = dbq($query, "s", $area_name);
	if ($r['result'])
	{
		if ($row = @mysqli_fetch_assoc($r['result']))
			$retval = $row['area_id'];
		@mysqli_free_result($r['result']);
	}
	else	debug("get_area_id(".$area_name.", ".$ip_id.") - query failed.");

	return $retval;
}

function get_area_id_by_page ($pageno)
{
	$reval = false;


	return $retval;

}

// Takes a PHOENIX regular expression for a page and works out whether it matches the IP regular expression.
// So (e.g.) IP regex 3XX will match 301, 35011, 30XXX, 35XX+, but not (e.g.) 501, 5XX, etc.
function ip_match_page ($ip_regex, $pg_regex)
{
	
	$retval = false; // I.e. not matched

	$pg_regex = pg_regex_to_subject($pg_regex, strlen($ip_regex)); // Cap to length of our IP regex - before we tinker with the ip_regex variable
	$ip_regex = ip_regex_to_object($ip_regex);

	$q = "select 1 from area where ? RLIKE ? limit 1";
	debug ($q);
	$r = dbq($q, "ss", $pg_regex, $ip_regex);
	if ($r['result'])
	{
		if ($r['numrows'] == 1)
			$retval = true;
		@mysqli_free_result($r['result']);
	}

	return $retval;
}

function ip_regex_to_object($ip_regex)
{
	// Convert 3XX etc. to 3[0-9X][0-9X] for example - this function is for the pattern to be compared against, not the subject

	return '^'.preg_replace('/X/', '[0-9X]', $ip_regex); // Add the SQL % because anything can come after an IP regex - there's an implicit  +

}

function pg_regex_to_subject($pg_regex, $l = null)
{
	// Convers like ip_regex_to_object, but if $l is set, it will attempt to pad to that minimum length but ONLY
	// if the pg_regex is shorter than that AND ends in a +

	$pg_len = strlen($pg_regex)-1;

	if (($pg_regex[$pg_len] == "+") and $l and ($l > $pg_len)) // Wildcard at end, we have been asked for a minimum length, and the length is greater than the string length
	{
		// Pad string
		$pg_regex[$pg_len] = "X";
		while (strlen($pg_regex) < $l)
			$pg_regex .= "X";
	}
	else
		$pg_regex = substr($pg_regex, 0, $l);

	return $pg_regex;


}

// Returns one of the following values, in an array with second element as area id
//
// PRIV_SUPER - Superuser - the logical or of Owner, Modserator, User
// PRIV_OWNER - Area owner
// PRIV_MOD - Area moderator
// PRIV_USER - Ordinary reader
// PRIV_PUBLIC - Public page:  even those who are not logged in can read it - e.g. the login page!
// PRIV_NONE - No access (e.g. non-existent page, or page which this user cannot see) (i.e. 0)

function page_get_priv($pageno, $userid)
{

	global $userdata;

	if (preg_match('/^([1-9][0-9]{0,9})[a-z]$/', $pageno, $matches))
		$pageno = $matches[1]; // Strip subframe ID
	
	$retval = array(PRIV_NONE, NULL, NULL);

	if ($userid == NULL) // i.e. not logged in yet
	{
		$query = "	select area.area_id,
			area_public,
			area_name,
			ap_permission,
			IF (area_public = 'Public', 1, 0) as permission,
			ip_id
			from area left join area_permission on area.area_id = area_permission.area_id
			where
				? RLIKE area_pageno_regex
			and	(area_permission.user_id IS NULL)
			order by LENGTH(area_pageno) DESC LIMIT 1";
		$r = dbq($query, "s", $pageno);
	}
	else
	{
		$query = "select area.area_id,
			area_public,
			area_name,
			ap_permission,
			IF (	area_public = 'Closed', 
					IF(ap_invert = 'Positive', 1, 0), 
					IF	(area_public = 'Open', 
							IF(ap_invert = 'Positive', 0, 1),
							1
						)
			) as permission,
			ip_id
		from area left join area_permission on area.area_id = area_permission.area_id
		where
			? RLIKE area_pageno_regex
		and	(area_permission.user_id = ? OR 
			 area_permission.user_id IS NULL)
		order by LENGTH(area_pageno) DESC
		LIMIT	1";

		$r = dbq($query, "si", $pageno, $userid);
	}

	if ($r['result'])
	{
		if ($data = @mysqli_fetch_assoc($r['result']))
		{
			$retval[1] = $data['area_id'];
			$retval[2] = $data['area_name'];
			if ($data['ip_id'] == $userdata->ip_id) // Owner
				$retval[0] = PRIV_OWNER;
			else if ($data['permission'] == 1) // Positive result
				if ($data['ap_permission'] == 'Moderator')
					$retval[0] = PRIV_MOD;
				else if ($data['area_public'] == 'Public')
					$retval[0] = PRIV_PUBLIC;
				else
					$retval[0] = PRIV_USER;
		}
		@mysqli_free_result($r['result']);
	}

	// Re-set to PRIV_NONE if there is an IP limit on this node and this would be in breach

	if (($userdata->limit_ip_id) && ($userdata->ip_id != 1)) 
	{
		$r = dbq("select ip_id from information_provider where ? rlike concat(ip_base_regex,'.*') order by length(ip_base_regex) desc limit 1", "s", $pageno);

		if ($r['success'])
		{
			$e = @mysqli_fetch_assoc($r['result']);
			if ($e['ip_id'] != $userdata->limit_ip_id)
			{
				debug ("page_get_priv(): page is in IP ".$e['ip_id']." and our limiter is to ".$userdata->limit_ip_id);
				$retval = array(PRIV_NONE, NULL, NULL);
			}
			@mysqli_free_result($r['result']);
		}
		else $retval = array(PRIV_NONE, NULL, NULL); // fallback deny
	}	


	// Now check if we're the superuser
	
	if ($userdata->ip_id == 1)
		$retval[0] = PRIV_SUPER;

	debug ("page_get_priv($pageno, $userid) = ".implode(',', $retval));

	$retval[0] = intval($retval[0]);
	$retval[1] = intval($retval[1]);
	return $retval;

}

// Converts an escaped string to the high bit equivalent
function viewdata_to_chr($s)
{
	if (strlen($s) > 1)
		return chr(64+ord($s[1]));
	else	return $s;
}

function eighties_delay($n = 2)
{
	usleep(rand(500000, 1000000*$n));
}
?>
