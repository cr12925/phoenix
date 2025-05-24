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

// Database

define('DB_HOST', "mysql.server.royle.org");
define('DB_USER', "phoenix");
define('DB_PW', "kmVQNcD77EX4M4bka3ja");
define('DB_NAME', "phoenix");

// Don't alter anything below here

$dbh = false;
$db_debug_flag = false;

date_default_timezone_set('Europe/London');

// Viewdata constants - leave this include here
include_once "viewdata.php";
include_once "dblib.php";
include_once "lib.php";
include_once "nationalrail.php";

// System vars
define('IDLEKICKOFF', 20); // Minutes before the system decides a user has really gone away
define('SYSBANNER', VTBLU.VBKGNEW.VTWHT."*** Phoenix v1.0 beta ***  ".VBKGBLACK."\r\n\n"); // This will be automatically preceded by a clear screen as the first thing sent to a user
define('SYSWELCOME', VFLASH."Welcome ...\r\n".VTYEL."From: %s\r\n".VTCYN."Port %s\r\n\n");

// Star prompt format
define('VSTAR_PROMPT', VTGRN."*".VCURSORON);

// Frame redisplay mode constants
define('FRAMEMODE_REDISPLAY', 1); // Redisplay existing data
define('FRAMEMODE_UPDATE', 2); // Pull new frame data and redisplay
define('FRAMEMODE_CONTINUE', 3); // Continue displaying part or fully displayed frame


// Response frame editor constants
define('RFE_NORMAL', 1);
define('RFE_PASSWORD', 2);
define('RFE_WRAP', 3);

define('RFE_CONTENT_NUMERIC', 'numeric'); // [0-9]
define('RFE_CONTENT_ALPHA', 'alpha'); // [a-zA-Z.,]
define('RFE_CONTENT_ALPHANUMERIC', 'alphanumeric'); // [0-9a-ZA-Z.,]
define('RFE_CONTENT_ALLTEXT', 'alltext'); // [Everything visible apart from control codes]
define('RFE_CONTENT_VISIBLE', 'visible'); // Everything visible including control codes

define('RFE_ACTION_LOGIN', 'login');
define('RFE_ACTION_POST', 'post'); // Leave message - though this may turn into a system IP function
define('RFE_ACTION_IPSEND', 'ipsend'); // Send to IP

// IP calling constants
define('IPR_GOTOFRAME', 1); // Successful call - IP directs us to particular frame
define('IPR_UNKNOWNFUNCTION', 2); // IP talking, but doesn't know what we're asking. Go to IP base page.
define('IPR_CALLFAILURE', 3); // Couldn't talk to the IP over HTTP
define('IPR_BADDATA', 3); // IP spoke to us but rejected our data. Should come with a failure frame.
define('IPR_TRYAGAIN', 6); // IP Rejected our data but wants the same frame redrawn
define('IPR_VALIDATED', 4); // If we asked for data to be validated, then it was. May come with a return string.
define('IPR_INVALID', 5); // If we asked for data to be validated, it wasn't.

// Frame privileges
define('PRIV_PUBLIC', 8);
define('PRIV_USER', 4);
define('PRIV_MOD', 2 );
define('PRIV_OWNER', 1 );
define('PRIV_NONE', 0);
define('PRIV_SUPER', PRIV_OWNER | PRIV_MOD | PRIV_USER | PRIV_PUBLIC);

define('IS_USER', PRIV_USER | PRIV_MOD | PRIV_OWNER);
define('IS_MOD', PRIV_MOD | PRIV_OWNER);
define('IS_OWNER', PRIV_OWNER);

class User {

	var $user_id;
	var $node_id;
	var $node_name;
	var $limit_ip_id; // Once logged in, will only show frames from this IP ID
	var $user_name;
	var $homepage;
	var $logoffpage;
	var $last_output;
	var $last_input;
	var $remote_addr;
	var $remote_host; // hostname if known
	var $remote_port;
	var $conn; // Socket to other end
	var $console; // True if we are on a tty device
	var $tx_baud; // Headline transmit - for display
	var $rx_baud; // Ditto reiceve
	var $tx_rate; // Time gap for one transmit character
	var $rx_rate;
	var $ip_base; // Root page number of IP's own space
	var $ip_base_len; // Minimum page number length to fall within IP's space. Usually 3.
	var $ip_id; // IP ID for new frames
	var $secondary_ip_id; // Other IP IDs we have access to - array of k:v and both are an ip_id
	var $preview; // Whether in unpublished frame preview mode
	var $editing; // Whether in the frame editor
	var $login_time; // time() when logged in - calculates time online variable
	var $previous_login_time; // time() of previous login
	var $frame_data; // Current frame as loaded, all fields in associative array
	var $frame_routes; // Current frame routes as loaded
	var $frame_response; // Current response frame configuration - also used to store current input
	var $frame_previous; // Previous frame & sub-frame
	var $frame_current; // Current frame
	var $frame_displayed; // Number of bytes of frame data displayed
	var $frame_displaymode; // Are updating, redisplaying, or doing nothing? 
	var $frame_priv; // after loading frame data, will be one of PRIV_SUPER, PRIV_OWNER, etc. showing privilege on current frame
	var $is_msg_index; // True if the page being displayed is a msg reading index page
	var $is_msg_reading; // True if the page being displayed is a page with a msg on it
	var $underlying_page; // Where this is a msg reading page, so that we are spoofing a page with the message on by loading the frame data from the *sending* page and repopulating it with the actual message, this is the underlying source page we have loaded and will then populate

	// Response frame editor variables
	var $rfe_start; // Byte number - so to get row we need to div / mod
	var $rfe_end; // Byte number - end
	var $rfe_type; // See constants
	var $rfe_fieldname; // Once entered
	var $rfe_limitinput; // See constants
	var $rfe_action; // See constants
	var $rfe_ipfunction; // IP Function to call for dynamic frames
	var $rfe_fr_ip_function; // IP function to call to submit a completed frame
	var $rfe_validate; // Whether input has to be validated before moving off a response field
	var $rfe_ip_validate_function; // Function to call at IP to validate
	var $rfe_notempty; // Set to true if field cannot be empty
	var $last_new_msg_check; // Seconds since the Epoch when we last looked for new personal mail
	var $msg_reading_page; // Page for reading personal mail

	// Stores message being read
	var $msg_data; // Basically a row from the msg table
	var $msg_index_data; // Holds the list of displayable messages on a message index page

	// National Rail API stuff, including system IP state for same
	var $nr_client; // SOAP client object
	var $nr_stn_srch; // Station the user asked for
	var $nr_aord; // Arrival or departure
	var $nr_filter_stn; // Only want trains to/from here if not null
}



function open_db()
{
	global $dbh;

	$dbh = mysqli_connect(DB_HOST, DB_USER, DB_PW, DB_NAME);
	if (!$dbh)
	{
		echo "Database inaccessible.\n";
		exit(0);
	}

}

open_db();

?>
