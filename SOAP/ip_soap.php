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

define('IPR_VALIDATED', 4); // If we asked for data to be validated, then it was. May come with a return string.
define('IPR_INVALID', 5); // If we asked for data to be validated, it wasn't.
// IP calling constants
define('IPR_GOTOFRAME', 1); // Successful call - IP directs us to particular frame
define('IPR_TRYAGAIN', 6); // IP Rejected our data but wants the same frame redrawn

require "Feed.php";

class ViewdataSoap
{
	private $my_key = "testkey";


	// VALIDATE - field validation on a response frame.
	// This will be called when the user attempts to exit the field 
	// i.e. before the frame is submitted with SUBMIT()
	//
	// $key - authentication string from the server. Compared to
	// $my_key above.
	// $frame - frame number and subframe ID as a text string
	// $user_id - Phoenix user ID without checkdigit
	// $field_name - name of field to be validated
	// $data - content of field
	//
	// Return one of the IPR_* constants
	//
	public function VALIDATE($key, $frame, $user_id, $field_name, $data)
	{
		if ($key != $this->my_key)
			return false;
		return IPR_VALIDATED;
	}

	// DYNAMIC - call from Phoenix to IP to produce a frame other than in the framestore
	// Inputs:
	// 	$user_id - Phoenix user ID without checkdigit
	//	$pageno - Integer page number
	//	$subframeid - single character subframe ID (a-z)
	//
	// Return value is an array of four keyed values:
	//	frame_content - base64 encoded string of precisely 880 bytes of frame data (see below)
	//	frame_next - If this is the 'z' frame, give a frame number (without subframe ID - Phoenix will start at 'a') for the 
	//			frame to move to
	//	area_name - area name (upper case) of the user group to which
	// 		this frame belongs. If:
	//			The area is public and the user has no specific permission, he will be allowed access.
	//			The area is closed and the user has no specific permission, he will be denied access.
	//			The user has a negative permission, he will be denied
	//		The area name must match precisely the upper case 
	//		area name in Phoenix. Otherwise Phoenix will reject it
	//		and put the frame into the public area.
	//		Likewise if the area is not associated with your IP
	//	frame_routes - An array of routing information which is an associative array as follows:
	//			Key - single character keypress ('0' - '9' only; Phoenix will remove anything else)
	//			Value - array('Page', numeric page number, "")
	//	frame_response - Array of arrays, one for each field to be visited in order
	//			'fr_start' - byte on frame for start of field (e.g. 126 is row 3, character 6)
	//			'fr_end' - Byte on frame for end of field (same basis)
	//			'fr_fieldname' - Field name. Usually upper case only
	//			'fr_attr' - comma-separated case-sensitive flags:
	//				page_wrap - allows a text area which spans multiple lines to wrap beyond the area
	//				password - displays input as *
	//				notempty - field cannot be exited if empty
	//			'fr_limit_input' - string which causes Phoenix to restrict valid input in the field
	//				numeric - [0-9] only
	//				alpha - 'A-Za-z' only
	//				alphanumeric - numeric + alpha
	//				alltext - alphanumeric + punctuation
	//				visible - everything including escaped colour codes etc.	
	//	fr_action	 - String determining what happens on submit
	//				post - Posts a message in the board area of the current frame
	//					NB field names MUST be
	//						SUBJECT
	//						BODY
	//				submit - Sends the fields to the IP SUBMIT function for the page
				
	// Frame content:
	// 8-bit data - so the control codes for colours are *not* escaped but are
	// character 129+. So ESC-A is chr(129).
	// Phoenix will strip out and replace with spaces (chr(32)) any non-displayable character
	// including cursor movement / cursor on/off etc.
	//
	// Note that it is up to you to work out which page/subframe is being asked for and respond accordingly - the
	// system simply calls this function for ALL dynamic frames.
	//
	public function DYNAMIC($key, $frameid, $data_array, $user_id) 
	{

		if ($key != $this->my_key)
			return false;

		$pageno = substr($frameid, 0, -1);
		$subframeid = substr($frameid, -1);

		$frame_data = array(	'frame_content' => "Bad frame",
				'frame_next' => 0,
				'frame_routes' => array(),
				'frame_response' => array() );


		if ($pageno == 210) // Stock exchange
		{
 			$frame_data['frame_routes']['0'] = array ('Page', 210, ""); // Go back to start of frame

                	$frame_data['frame_content'] = sprintf("%-40s", chr(130)."FTSE 100 Data feed - May be delayed");

                	$rss_url = 'https://spreadsheets.google.com/feeds/list/0AhySzEddwIC1dEtpWF9hQUhCWURZNEViUmpUeVgwdGc/1/public/basic?alt=rss';
                	$rss_result= Feed::loadRss($rss_url);

			if (!$rss_result)	return false;

			$iterate = 0;
			while (isset($rss_result->item[$iterate]))
                	{
				$value = $rss_result->item[$iterate];
                        	if (preg_match('/.*name:\s+(.*),\s+price:\s+(.*),\s+change:\s+(.*)/', $value->description, $preg_match_result))
					$ftse[] = array($value->title, $preg_match_result);
				$iterate++;
                	}

                	$start_index = 19 * (ord($subframeid) - ord('a'));
                	$end_index = $start_index + 19;
                	if (count($ftse) <= $start_index)
                        	return false;

			$frame_data['frame_content'] .= sprintf(chr(134)."%- 6s %- 17s %- 7s%- 1s%- 6s", "Symbol", "Company", "Price", "", "+/-");
                	for (; $start_index < $end_index; $start_index++)
                        	$frame_data['frame_content'] .= sprintf(chr(134)."%- 6s".chr(130)."%- 17s".chr(134)."%- 7s%- 1s%- 6s", $ftse[$start_index][0],
										substr($ftse[$start_index][1][1], 0, 16),
                                                                                $ftse[$start_index][1][2],
                                                                                chr($ftse[$start_index][1][3] < 0 ? 129 : 130),
                                                                                $ftse[$start_index][1][3]);

                	$frame_data['frame_content'] .= sprintf("%-40s", chr(130)."_".chr(135)."Next,".chr(130)."0".chr(135)."Back to start");
		}
		else
		{

			// Example frame_route
			$frame_data['frame_routes']['0'] = array('Page', 1, ""); // Go to frame 1a

			// Example response frame
			$frame_data['frame_response'][0]['fr_start'] = 10;
			$frame_data['frame_response'][0]['fr_end'] = 20;
			$frame_data['frame_response'][0]['fr_attr'] = "notempty";
			$frame_data['frame_response'][0]['fr_limit_input'] = "alphanumeric";
			$frame_data['frame_response'][0]['fr_fieldname'] = 'TEST';

			$frame_data['fr-action'] = 'submit';

			$frame_data['frame_content'] = sprintf("Page number given: %-11s %1s%8sUserID: %-10s%22s%-40s%-840s", $pageno, $subframeid, "", $user_id, "", "INPUT:    ..........", "Rest of frame blank");
		}
	
		$frame_data['frame_content'] = base64_encode($frame_data['frame_content']);

		$frame_data['area_name'] = 'PUBLIC';

		return ($frame_data);
	}


	//
	// SUBMIT
	// Takes data from a response frame and returns one of a range of codes 
	// and a destination page number if applicable

	// INPUTS:
	// $frame - frame page number & subframeID as a string
	// $data - an associative array where the key is the fieldname in the response frame, and the value is the content of the field.
	// $userid - Phoenix user ID without checkdigit
	//
	// RETURN: array(result_code, frame number)
	// result_code is one of the constants IPR_GOTOFRAME or IPR_TRYAGAIN
	// frame number is an integer frame number to go to.

	public function SUBMIT($key, $frame, $data, $userid)
	{

		if ($key != $this->my_key)
			return false;
		
		// e.g.
		return array(IPR_GOTOFRAME, 20); // Start at 20a
		// or on a fault
		// return array(IPR_TRYAGAIN, 0);	
	}


}


$options = array('uri'=>'http://localhost/ip_soap.php');
$server = new SoapServer(NULL, $options);
$server->setClass('ViewdataSoap');
$server->handle();


?>
