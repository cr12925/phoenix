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

// Frame Editor code

function process_editor_star($conn,$star_str)
{

	if (preg_match('/^[1-9][0-9]+$/', $star_str))
		show_error($conn, "Not while editing");
	else
		show_error($conn, "Bad command in editor");

}

function frame_edit($conn, $frame)
{

	global $userdata;

	$userdata->rfe_start = false; // Clear any residual data
	$userdata->rfe_notempty = false;
	$userdata->rfe_ip_validate = false;
	$userdata->rfe_ip_validate_function = "";

	// If we are editing, then if there is no 'unpublished' version of the frame we need to create it.
	// If there is no published version, we need to create an empty frame.
	// In either case we need to copy any existing routes & keys over.

	preg_match('/^([1-9][0-9]*)([a-z])$/', $frame, $matches);
		$frame_pageno = $matches[1];
		$frame_subframeid = $matches[2];

	$frame_id = 0; // get_frame_id($frame); // This will be >0 for published; <0 for unpublished

	$frame_id = is_published($frame);
	$up_frame_id = is_unpublished($frame);
	debug ("frame_edit($frame): is_published = $frame_id");
	debug ("frame_edit($frame): is_unpublished = $up_frame_id");

	if (!$frame_id && !$up_frame_id)
	{
		// Create from scratch as unpublished
		log_event ("Create Frame", $userdata->user_id, $frame);
	
                $query = "
INSERT INTO frame(frame_pageno, frame_subframeid, frame_content, frame_flags, ip_id)
VALUES (?, ?, to_base64(?), 'login,unpublished', ?)";

                 $r = dbq($query, "issi", $frame_pageno, $frame_subframeid, sprintf("% 880s", ""), $userdata->ip_id);
        
                 if ($r['success'])
                 {
 	                show_prompt ($conn, "Frame ".$frame." created");  
                        @mysqli_free_result ($r['result']);
                 }
                 else
                 { 
                       show_error ($conn, "Unable to create ".$frame);
			return(0);
                 }
		 $inserted_id = $r['insert_id'];

	}
	else if ($frame_id && !$up_frame_id) // published only
	{
		// Copy the published frame
		$query = "insert into frame(frame_id, frame_pageno, frame_subframeid, frame_content, frame_flags, ip_id, frame_next, frame_fr_ip_function)
		select 0, frame_pageno, frame_subframeid, frame_content, if(length(frame_flags)=0,'unpublished',concat('unpublished,',frame_flags)) as frame_flags, ip_id, frame_next, frame_fr_ip_function
from		frame
where		frame_id = ?";

		$r = dbq($query, "d", $frame_id);
		if (!$r['success'])
		{
			log_event("Misc", $userdata->user_id, "Copy frame ".$frame." failed");
			return(0);
		}
		else
			@mysqli_stmt_close();
			//@mysqli_free_result($r['result']);

		$inserted_id = $r['insert_id'];
		$query = "insert into frame_key(frame_id, frame_keypress, frame_key_action, frame_key_metadata1, frame_key_metadata2, frame_key_flags)
		select ".$inserted_id.", frame_keypress, frame_key_action, frame_key_metadata1, frame_key_metadata2, if(length(frame_key_flags)=0,'unpublished',concat('unpublished,', frame_key_flags)) as frame_key_flags
from		frame_key
where		frame_id = ?";

                $r = dbq($query, "i", $frame_id);
                if (!$r['success'])
                {
                        log_event("Misc", $userdata->user_id, "Copy frame keys ".$frame." failed");
                        return(0);
                }
                else
			@mysqli_stmt_close();
                        //@mysqli_free_result($r['result']);

		$query = "insert into frame_response(frame_id, fr_start, fr_end, fr_attr,  
				fr_limit_input, fr_fieldname, fr_flags, fr_action, fr_ip_validate_function)
		select ".$inserted_id.", fr_start, fr_end, fr_attr, 
			fr_limit_input, fr_fieldname, if(length(fr_flags)=0,'unpublished',concat('unpublished,', fr_flags)) as fr_flags, fr_action, fr_ip_validate_function
		from	frame_response
		where	frame_id = ?";

                $r = dbq($query, "i", $frame_id);
                if (!$r['success'])
                {
                        log_event("Misc", $userdata->user_id, "Copy frame response ".$frame." failed");
                        return(0);
                }
                else
			@mysqli_stmt_close();
                        //@mysqli_free_result($r['result']);

	}
	else // unpublished exists
	{
		show_prompt($conn, "Editing unpublished frame ".$frame);
		$inserted_id = $up_frame_id;
	}

	// If there's an existing unpublished frame, we can just use it.

	$editing = true;
	$preview_preserve = $userdata->preview;

	$userdata->editing = $userdata->preview = true;

	$viewframe = $userdata->frame_data["frame_pageno"].$userdata->frame_data["frame_subframeid"];
	$userdata->frame_data["frame_pageno"] = substr($frame, 0, -1);
	$userdata->frame_data["frame_subframeid"] = substr($frame, -1);
	$userdata->frame_current = $frame;
	$result = load_frame_userdata();
	
	if (!$result[0])
	{
		show_error ($userdata->conn, "Error loading frame. Editing finished.");
		$userdata->frame_data["frame_pageno"] = substr($viewframe, 0, -1);
		$userdata->frame_data["frame_subframeid"] = substr($viewframe, -1);
		$userdata->frame_displaymode = FRAMEMODE_UPDATE; // Cause a refresh
		$userdata->editing = false;
		$userdata->preview = $preview_preserve;
		return;
	}
	
	// NB $userdata->frame_data['frmae_id'] should now contain the frame ID we are editing.

	send_frame_title_userdata();
	$result = send_frame_userdata(0, false);

	//$result = send_frame_int ($conn, $frame, false, true); // The final 'true' puts the frame display into edit mode - no substitutions, always has a header. The 'false' means no key interruptions.
	if ($result[0] != TX_OK)
	{
		show_error($conn, "Error displaying frame. Editing finished.");
		$userdata->frame_data["frame_pageno"] = substr($viewframe, 0, -1);
		$userdata->frame_data["frame_subframeid"] = substr($viewframe, -1);
		$userdata->frame_displaymode = FRAMEMODE_UPDATE; // Cause a refresh
		$userdata->editing = false;
		$userdata->preview = $preview_preserve;
		return;
	}

	$frame_data = $userdata->frame_data["frame_content"];
	$frame_flags = array();
	$t = explode(',', $userdata->frame_data['frame_flags']);
		foreach ($t as $flag)
			$frame_flags[$flag] = true;
	$frame_routes = $userdata->frame_routes;
	$frame_response = $userdata->frame_response;

	show_prompt($conn, "Frame editor enabled");
	ser_output_conn(VNHOME.VNDOWN.VCURSORON); // Put us at x=0, y=1
	$x = 0; $y = 1; $g_edit = false; // Graphics edit mode

	while ($editing)
	{

		$key_tuple = ser_input_full(false, true);

		if ($key_tuple[0] == RX_STAR_CMD)
		{
			process_editor_star($conn, $key_tuple[1]);
			goto_xy($conn, $x, $y);
		}
		elseif ($key_tuple[0] == RX_UPDATE || $key_tuple[0] == RX_REDISPLAY)
		{	// Redisplay 	
			ser_output_conn(VNLINESTART.sprintf("%39s", ""));
			goto_xy($conn, $x, $y);
		}
		elseif ($key_tuple[0] == RX_KEY || $key_tuple[0] == RX_KEY_STAR)
		{
			if ($key_tuple[0] == RX_KEY_STAR) // Must reposition
			{
				ser_output_conn(VNLINESTART.sprintf("%39s", ""));
				goto_xy($conn, $x, $y);
			}

			$key = $key_tuple[1];
			switch ($key)	
			{
				case VNLEFT:
					if ($x > 0 or $y > 1)
					{
						ser_output_conn(VNLEFT);
						$x--;
						if ($x < 0) { $x = 39; $y--; }
					}
					break;
				case VNRIGHT:
					if ($x < 39 or $y < 22)
					{
						ser_output_conn(VNRIGHT);
						$x++;
						if ($x > 39) { $x = 0; $y++; }
					}
					break;
				case VNUP:
					if ($y > 1)
					{
						ser_output_conn(VNUP);
						$y--;
					}
					break;
				case VNDOWN:
					if ($y < 22)
					{
						ser_output_conn(VNDOWN.VNCR.str_repeat(VNRIGHT, $x));
						$y++;
					}
					break; /* Commstar seems to do a CR on a VNDOWN... so we fix it. */
				case '_':
				case chr(0x0d): // Traditional return
					if ($y < 22)
					{
						$y++;
						$x = 0;
						ser_output_conn(VNDOWN.chr(13));
					}
					break;
				case chr(0x01): // Ctrl-A - insert line below current
					if ($y < 22)
					{
						for ($counter = 879; $counter > (($y*40)-1); $counter--)
							$frame_data[$counter] = $frame_data[$counter-40];
						for ($counter = ($y*40); $counter <= (($y*40)+39); $counter++)
							$frame_data[$counter] = chr(32); // Spaces
						$frame_data=substr($frame_data, 0, 880);
						ser_output_conn(chr(0x0d).chr(0x0a)); // Start of line & down a line
						for ($counter= ($y*40); $counter <= 879; $counter++)
							ser_output_conn($frame_data[$counter]);
						// Move any field starts on line below down one line, and 
						// Get rid if tly > 21 (bottom line). If bry then
						// > 21 (which is the bottom line) then shorten it if the field
						// still exists

						$response_remove = array();
						foreach ($frame_response as $k => $v)
						{
							$tly = intval($v['fr_start'] / 40);
							$bry = intval($v['fr_end'] / 40);
							if ($bry >= ($y-1))
							{
								if ($bry < 21)
								{
									$frame_response[$k]['fr_end'] += 40;
									$bry++;
								}
							}	
							if ($tly >= ($y-1))
							{
								if ((preg_match('/wrap/', $frame_response[$k]['fr_attr'])) and (($bry-$tly) < 2)) // Can't shink - we are down to two lines
									$response_remove[$k] = true;
								else if ($tly < 21)
									$frame_response[$k]['fr_start'] += 40;
								else // We must have shuted off the bottom
									$response_remove[$k] = true;
							}
						
							$new_response = $frame_response;
							foreach ($frame_response as $k => $v)
								if (!isset($response_remove[$k]))
									$new_response[] = $v;
								else
								{	
									show_prompt($conn, "Field. ".$frame_response[$k]['fr_fieldname']." removed."); 
									sleep(1);
								}
							if (count($response_remove) > 0)
								$frame_response = $new_response;
								
						}
						goto_xy($conn, $x, $y);
					}
					break;
				case chr(0x04): // Ctrl-D - delete line - current line
					for ($counter = (($y-1)*40); $counter <= 839; $counter++)
						$frame_data[$counter] = $frame_data[$counter+40];
					for ($counter = 840; $counter <= 879; $counter++)
						$frame_data[$counter] = chr(32); // Spaces on last line
					ser_output_conn(chr(0x0d)); // Start of line
					// Redisplay
					for ($counter = (($y-1)*40); $counter <= 879; $counter++)
						ser_output_conn($frame_data[$counter]);
					// Move fields up one line and remove any which are on our current line
					$keys_to_remove = array();
					foreach ($frame_response as $k => $v)
					{
						$tly = intval($v['fr_start'] / 40);
						$bry = intval($v['fr_end'] / 40);
						if (($tly == ($y-1)) or ($bry == ($y-1))) // Either we deleted the line where the field starts or ends
						{
							$keys_to_remove[$k] = true;
						}
						else if ($tly >= $y) // Else if the field starts below us, shut it all up a line. Note that $y is screen position, so the row in the frame data is $y-1, so if we want next row down and below, we compare with $y, not $y+1
						{
							$frame_response[$k]['fr_start'] -= 40;
							$frame_response[$k]['fr_end'] -= 40;
						}
						else if ($bry >= $y) // Must be on neither start nor end line, and start is above us, so if end is below us, shorten the field unless it's a wrap and would be left with only one line, in which case delete
						{
							if ($bry != $tly)
								if (($bry - $tly) > 1)
									$frame_response[$k]['fr_end'] -= 40;
								else
									$keys_to_remove[$k] = true;
						}
			
							
					}
					if (count($keys_to_remove) > 0) // We've deleted one or more fields
					{
						$new_frame_response = array();
						for ($k = 0; $k < count($frame_response); $k++)
							if (!isset($keys_to_remove[$k]))
								$new_frame_response[] = $frame_response[$k];
							else
							{
								show_prompt($conn, "Field ".$frame_response[$k]['fr_fieldname']." removed.");
								sleep(1);
							}
						$frame_response = $new_frame_response;
					}
					goto_xy($conn, $x, $y);
					break;
				case chr(0x02): // Ctrl-B - Suck the line forward one character
/*
					for ($counter = $x; $counter < 39; $counter++)
					{
						$location = (40*($y-1))+$counter+1;
						ser_output($conn, $frame_data[$location]);
						$frame_data[$location-1] = $frame_data[$location];
					}
					ser_output($conn, " ");
					$frame_data[(40*($y-1))+39] = chr(32);
*/
					$location = (40*($y-1))+$x+1;
					$length = 39-$x; // Not +1, because if we deleted char 0, we'd copy 39 characters from position *1*
					ser_output_conn(substr($frame_data, $location, $length)." ");
					for ($c = $location; $c < ($location + $length); $c++)
						$frame_data[$c-1] = $frame_data[$c];
					$frame_data[(40*($y))-1] = chr(32);

					// Shuffle TLX left one character for any field starting on this line
					// Which is to the right of current cursor position.
					// If it's ON our cursor position, leave it untouched. The user can
					// Delete it if they want. A wrap field will be widened by this operation
					// because it's bottom right y (bry) will not be on same line
					// If bry on same line, bring brx forwards a character too
					foreach ($frame_response as $k => $v)
					{
						$tlx = $v['fr_start'] % 40;
						$brx = $v['fr_end'] % 40;
						$tly = intval($v['fr_start'] / 40);
						$bry = intval($v['fr_end'] / 40);
						if (($tly == ($y-1)) and ($tly == $bry))
						{
							if ($tlx > $x)
								$frame_response[$k]['fr_start']--;
							if ($brx > $x)
								$frame_response[$k]['fr_end']--;
						}
						// Handle multiline
						if ($tly != $bry)
						{
							if (($tly == ($y-1)) and ($tlx > $x) and ($brx > ($tlx + 20))) // First line
								$frame_response[$k]['fr_start']--;
							if (($bry == ($y-1)) and ($brx > $x) and ($brx > ($tlx + 20)))
								$frame_response[$k]['fr_end']--;
						}
					}
					goto_xy($conn, $x, $y);
					break;
				case chr(0x03): // Ctrl-C - Nudge in a space character
					if ($x < 39)
					{
						for ($counter = 39; $counter > $x; $counter--) // Cannot nudge on final character of line
						{
							$location = (40*($y-1))+$counter;
							$frame_data[$location] = $frame_data[$location-1];
						}
						$frame_data[(40*($y-1))+$x] = chr(32);
						for ($counter = $x; $counter <= 39; $counter++) // Redisplay
							ser_output($conn, $frame_data[(40*($y-1))+$counter]);
						// Sort out response fields
						// Fields starting on my line but to the right get nudged one character
						// Fields ending on my line but to the right get nudged one character unless already on last character (39)
						// If tlx now > brx then delete the field
						// If wrap field and tlx >= brx, delete the field
						// (Because wrap fields need to be at least 2 chars wide)

						foreach ($frame_response as $k => $v)
						{
	                                                $tlx = $v['fr_start'] % 40;
       	                                        	$brx = $v['fr_end'] % 40;
                                                	$tly = intval($v['fr_start'] / 40);
                                                	$bry = intval($v['fr_end'] / 40);
							if (($tly == ($y-1)) and ($brx >= $x) and (
								($tly == $bry) or // Can shunt brx right on same line
								(($tly != $bry) and (($brx - $tlx) >= ($brx == $tlx ? 21 : 2)))
								) 
							)// Single or multi line and end is on our character or to the right and the resulting field is of a viable length when shunted, or brx can be shunted right, shunt start to the right
							{
								if (($tly == ($y-1)) and ($tlx >= $x))
								{
									$frame_response[$k]['fr_start']++;
									$tlx++;
								}
								if (($tly == $bry) and ($brx < 39))
								{
									$frame_response[$k]['fr_end']++;
									$brx++;
								}
							}
							// So the remaining case is that we are shunting on the last line of a multiline and brx is at our position or to our right
							else if (($tly != $bry) and ($bry == ($y-1)) and ($brx >= $x) and ($brx < 39))
							{
								$frame_response[$k]['fr_end']++; $brx++;
							}
						
						}
						goto_xy($conn, $x, $y); // Re-set cursor
					}
					break;
				case ESC:
				{
					debug ("Frame editor - ESC received");
					$second_key = null;
					while (!$second_key)
					{
						debug ("ESC - Wait for second key");
						$second_key = ser_input($conn, false, true);
						debug ("ESC - Second key received - Ord ".ord($second_key));
						if ($second_key == ESC)
						{
							show_prompt($conn, "<1> Save, <2> Pub., <3> Quit, <4> Cont.");
							$key = ser_input($conn, "1234", true);
		
							if ($key < "4")
								$editing = false;
				
							$save_succeeded = false;

							if ($key < "3") // Save or publish - so either way we must save
							{	// Save here as unpublished
								// Need to be careful of quotes etc. - SQL insertion attacks
								// Possibly store as base64 with TO_BASE64() and FROM_BASE64() ?
								//$query = "
//update frame
//set frame_content = to_base64('".addslashes($frame_data)."')
//where frame_id = ".$inserted_id;
								//$r = dbq($query);
								$success_count = 0;
								$success_needed = 3;
								dbq_starttransaction();
								$r = dbq("update frame set frame_content = to_base64(?), frame_flags = ? where frame_id = ?", "ssi", $frame_data, (count($frame_flags) == 0 ? '' : implode(',',array_keys($frame_flags))), $inserted_id);
								if ($r['success']) $success_count++;
								$r = dbq("delete from frame_response where frame_id = ?", "i", $inserted_id);
								if ($r['success'])
								{
									$success_count++;
									foreach ($frame_response as $v)
									{
										$success_needed++;
										$r = dbq("insert into frame_response(frame_id, fr_attr, fr_limit_input, fr_fieldname, fr_flags, fr_action, fr_start, fr_end, fr_maxwraplength) values(?, ?, ?, ?, ?, ?, ?, ?, ?)", "isssssiii", 	
										$inserted_id,
										$v['fr_attr'],
										$v['fr_limit_input'],
										$v['fr_fieldname'],
										$v['fr_flags'],
										$v['fr_action'],
										$v['fr_start'],
										$v['fr_end'],
										4096);
										if ($r['success']) $success_count++;
									}
								}


								$r = dbq("delete from frame_key where frame_id = ?", "i", $inserted_id);
								if ($r['success'])
								{
									$success_count++;
									foreach ($frame_routes as $k => $v)
									{
										$success_needed++;
										$r = dbq("insert into frame_key(frame_id, frame_keypress, frame_key_action, frame_key_metadata1, frame_key_metadata2, frame_key_flags) values (?, ?, ?, ?, ?, ?)", "ississ",
										$inserted_id,
										$k,
										$v[0],
										$v[1],
										$v[2],
										'unpublished');
										if ($r['success']) $success_count++;
									}	

								}

								if ($success_count == $success_needed)
								{
									dbq_commit();
									$save_succeeded = true;
									show_prompt($conn, "Frame saved.");
								}
								else
								{
									dbq_rollback();
									show_error($conn, "Save failed.");
								}
								goto_xy($conn, $x, $y);

/* Old DB code 
									
								if (!$r)
								{
									show_error($conn, "Save failed.");
									goto_xy($conn, $x, $y);
								}
								else
								{
									show_prompt($conn, "Frame saved.");
									goto_xy($conn, $x, $y);
									@mysqli_stmt_close();
									//@mysqli_free_result($r['result']);
								}
*/
		
							}
							if ($key == "2" and $save_succeeded) // Publish - by removing published & marking unpublished as published
							{
								// Needs to be done on each of the three tables
								// Find any published pages
						
								// Needs a transaction wrapping round this.

								$r = dbq("select frame_id from frame where frame_pageno=? and frame_subframeid=? and !find_in_set('unpublished', frame_flags)", "is", $frame_pageno, $frame_subframeid);
								if ($r['result'])
								{
									if ($row = @mysqli_fetch_assoc($r['result']))
									{
										$old_frame_id = $row['frame_id'];
										$r = dbq("delete from frame where frame_id=?", "i", $old_frame_id);
										$r = dbq("delete from frame_key where frame_id=?", "i", $old_frame_id);
										$r = dbq("delete from frame_response where frame_id=?", "i", $old_frame_id);
									}
									@mysqli_free_result($r['result']);
								}
								$r = dbq("update frame set frame_flags = trim(both ',' from replace(concat(',', frame_flags, ','), ',unpublished,', ',')) where frame_id = ?", "i", $inserted_id);
								$r = dbq("update frame_key set frame_key_flags = trim(both ',' from replace(concat(',', frame_key_flags, ','), ',unpublished,', ',')) where frame_id = ?", "i", $inserted_id);
								$r = dbq("update frame_response set fr_flags = trim(both ',' from replace(concat(',', fr_flags, ','), ',unpublished,', ',')) where frame_id = ?", "i", $inserted_id);
								// ERROR CHECK HERE!
					
								show_prompt($conn, "Frame published");
							}
				
							// Nothing required for keypress "3" or "4". If "3" then just quit editing & don't save. If "4", don't quit editing, and don't save either - see above.
							if ($key == "4")
							{
								to_bottom();
								ser_output($conn, sprintf("% 39s", ""));
								goto_xy($conn, $x, $y);
							}
						}

						if ($g_edit)
						{	debug ("ESC-NotESC received in Graphics Edit - Bailing");
						 	break; // Nothing below here in graphic edit mode
						}
	
						if ($second_key == chr(0x0c)) // ESC-Ctrl-L -- Clear the screen
						{
							show_prompt($conn, "Clear screen & response fields (y/n) ?");
							$input = ser_input($conn, "YyNn", true);
							if (strtolower($input) == "y")
							{
								$x = 0; $y = 1;
					
								// TO DO 

								// Clear frame data
								$old_frame_data = $frame_data;
								$frame_data = sprintf("% 880s", "");
								ser_output_conn(VCLS);
								send_frame_title_userdata();
								dbq("delete from frame_response where frame_id = ?", "i", $inserted_id);
								ser_output_conn(VCURSORON);
							}
							else
							{
								// Clear bottom line
								to_bottom();
								ser_output_conn(sprintf("% 39s", ""));
							}
		
							goto_xy($conn, $x, $y);

						}


						// Material below here advances by 1 character space.
						// Break if at end.
						if (($x == 39) && ($y == 22))
							break;

						if ($second_key == "*")
						{
							ser_output_conn("*");
							$frame_data[(40*($y-1))+$x] = chr(42);
	                                        	$x++;
                                                	if ($x > 39) {$x = 0; $y++;}
						}
						
						if ($second_key == '_')
						{
							ser_output_conn(chr(95));
							$frame_data[(40*($y-1))+$x] = chr(95);
	                                        	$x++;
                                                	if ($x > 39) {$x = 0; $y++;}
						}
						
						if (($second_key >= chr(65) and $second_key <= chr(73))  or // Control codes
					    	($second_key >= chr(76) and $second_key <= chr(77)) or
					    	($second_key >= chr(81) and $second_key <= chr(90)) or
					    	($second_key >= chr(92) and $second_key < chr(95))) // 95 is hash which is dealt with above
						{
							debug ("Editor - received escape code ".$second_key." ord ".ord($second_key));
							$frame_data[(40*($y-1))+$x] = chr(ord($second_key) + 64);
							ser_output_conn(chr(ord($second_key)+64));
							$x++;
							if ($x > 39) {$x = 0; $y++;}
						}
					}
					break;
				}
				case chr(0x0c): // Ctrl-L
				{
					debug ("Frame editor - Ctrl-L received");
					$second_key = null;
					while (!$second_key)
					{
						// TO DO
						$second_key = ser_input($conn, false, true);
						if ($second_key == chr(0x0c)) // Ctrl-L Ctrl-L - Reposition cursor
							goto_xy($conn, $x, $y);
						if ($second_key == '1') {  // Mark field start (non-wrap)
							if ($userdata->rfe_start !== false)
								show_error($conn, "Cannot define 2nd field start");
							else
							{
								$userdata->rfe_start = (($y-1)*40)+$x;
								debug("Field start at position ".$userdata->rfe_start);
								ser_output_conn("]".VNLEFT);
								$userdata->rfe_end = null;
								show_prompt($conn, "Field type: <N>ormal,<P>wd,<W>rap"); //,<W>rap");
								$prompt_resp = ser_input_insist("NPW", true, true);
								switch ($prompt_resp)
								{
									case 'N': $userdata->rfe_type = RFE_NORMAL; break;
									case 'P': $userdata->rfe_type = RFE_PASSWORD; break;
									case 'W': $userdata->rfe_type = RFE_WRAP; break;
								}
								show_prompt($conn, "Field input: <N,A,B,T,V>");
								$prompt_resp = ser_input_insist("NABTV", true, true);
								switch ($prompt_resp)
								{
									case 'N': $userdata->rfe_limitinput = RFE_CONTENT_NUMERIC; break;
									case 'A': $userdata->rfe_limitinput = RFE_CONTENT_ALPHA; break;
									case 'B': $userdata->rfe_limitinput = RFE_CONTENT_ALPHANUMERIC; break;
									case 'T': $userdata->rfe_limitinput = RFE_CONTENT_ALLTEXT; break;
									case 'V': $userdata->rfe_limitinput = RFE_CONTENT_VISIBLE; break;
								}

								// show_prompt($conn, "Validate with IP Y/N ? ");
								$prompt_resp = "N"; //ser_input_insist("YN", true, true);
			
								switch ($prompt_resp)
								{
									case 'N': $userdata->rfe_validate = false; $userdata->rfe_ip_validate_function = null; break;
									case 'Y':
									{
										$userdata->rfe_validate = true;
										show_prompt($conn, "Validate function:");
										$userdata->rfe_ip_validate_function = ser_input_str(15, VDKEYALPHA);
									}
								}

								show_prompt($conn, "Can field be empty Y/N ? ");
								$prompt_resp = ser_input_insist("YN", true, true);
								if ($prompt_resp == "Y")
									$userdata->rfe_notempty = false;
								else	$userdata->rfe_notempty = true;

								show_prompt($conn, "Field name: ");
								$userdata->rfe_fieldname = null;
								while ($userdata->rfe_fieldname == null)
									$userdata->rfe_fieldname = ser_input_str(15, "ABCDEFGHIJKLMNOPQRSTUVWXYZ".chr(0x7f));	
																
								//show_prompt($conn, "Action <P>ost Msg, <I>P Call?");
								//$prompt_resp = ser_input_insist("PI", true, true);
								//switch ($prompt_resp)
								//{
									//case 'P': $userdata->rfe_action = RFE_ACTION_POST; break;
									//case 'I': $userdata->rfe_action = RFE_ACTION_IPSEND; break;
								//}
								$userdata->rfe_action = RFE_ACTION_IPSEND;
	
								show_prompt($conn, "Field started. Ctrl-L 2 at end");
							}
						} // 
						if ($second_key == '2') { 
							if ($userdata->rfe_start !== false)
							{							
								$userdata->rfe_end = (($y-1)*40)+$x;
								debug ("Field end request. Current start is ".$userdata->rfe_start.", end is ".$userdata->rfe_end);
								$tlx = ($userdata->rfe_start % 40);
								$brx = ($userdata->rfe_end % 40);
								$tly = intval($userdata->rfe_start / 40);
								$bry = intval($userdata->rfe_end / 40);
								//if ($userdata->rfe_end <= $userdata->rfe_start)
								//{
									//show_error($userdata->conn, "Cannot end field before or at start.");
									//goto_xy($userdata->conn, $x, $y);
								//}
								if (	(($bry - $tly) > 0) and (($brx - $tlx) < 20) ) // Multiline and less than 20 characters wide
								{
									show_error($userdata->conn, "Multi-line must be 20+ wide");
									goto_xy($userdata->conn, $x, $y);
								}
								elseif ( ($brx < $tlx) or ($bry < $tly) ) // End X < Start X; ditto Y
								{
									show_error($userdata->conn, "Invalid field geometry");
									goto_xy($userdata->conn, $x, $y);
								}
					 			//elseif ( (intval($userdata->rfe_start / 40) != intval($userdata->rfe_end / 40) ) && ($userdata->rfe_type != RFE_WRAP) ) // I.e. we have start & end on two different lines but not a wrap field
								//{
									//show_error($userdata->conn, "Non-wrap field ends on wrong line");
									//goto_xy($userdata->conn, $x, $y);
								//}
								else
								{

									ser_output_conn("[".VNLEFT);
									show_prompt($conn, "Save field <Y>,<N>?");
									$prompt_resp = ser_input_insist("YN", true, true);	
									if ($prompt_resp == 'N')
										show_error($conn, "Field not saved");
									else
									{
										$attr_flags = array();
										switch ($userdata->rfe_type) {
											case RFE_PASSWORD:
												$attr_flags[] = "password"; break;
											case RFE_WRAP:
												$attr_flags[] = "pagewrap"; break;
										}
									
										if ($userdata->rfe_ip_validate)
											$attr_flags[] = "validate";

										if ($userdata->rfe_notempty)
											$attr_flags[] = "notempty";

										$fr_attr = implode(",", $attr_flags);
										if ($fr_attr == "")
											$fr_attr = null;

										
										// Remove any response elements which either start at the same place or have the same name
										foreach ($frame_response as $k => $v)
											if (($v['fr_start'] == $userdata->rfe_start) or (strtoupper($v['fr_fieldname']) == strtoupper($userdata->rfe_fieldname)))
												array_splice($frame_response, $k, 1);

										// Insert new

										$frame_response[] = array(
											'frame_id' => $userdata->frame_data['frame_id'],
											'fr_attr' => $fr_attr,
											'fr_limit_input' => $userdata->rfe_limitinput,
											'fr_fieldname' => $userdata->rfe_fieldname,
											'fr_flags' => 'unpublished',
											'fr_action' => $userdata->rfe_action,
											'fr_start' => $userdata->rfe_start,
											'fr_end' => $userdata->rfe_end,
											'fr_validate_function' => $userdata->rfe_ip_validate_function);
										show_prompt($conn, "Field saved.");
										sleep(1);
											
											
										// Removed when we moved to prepare-syntax on the query
										//else
											//$fr_attr = "'".$fr_attr."'";

										// Replace into to get rid of any other that starts sameplace

										// This should just update the frame_response data
										//$query = "
//replace into frame_response 
	//(frame_id, fr_attr, fr_limit_input, fr_fieldname, fr_flags, fr_action, fr_start, fr_end, fr_ip_validate_function) 
//values (".$userdata->frame_data['frame_id'].", ".$fr_attr.", '".$userdata->rfe_limitinput."', '".$userdata->rfe_fieldname."', 'unpublished', '".$userdata->rfe_action."', ".$userdata->rfe_start.", ".strval((40*($y-1))+$x).",'".$userdata->rfe_ip_validate_function."')";
/* Old direct datbase code here 
										$query = "replace into frame_response (frame_id, fr_attr, fr_limit_input, fr_fieldname, fr_flags, fr_action, fr_start, fr_end, fr_ip_validate_function) values (?, ?, ?, ?, 'unpublished', ?, ?, ?, ?)";
										$r = dbq($query, "issssiis", 
											$userdata->frame_data['frame_id'],
											$fr_attr,
											$userdata->rfe_limitinput,
											$userdata->rfe_fieldname,
											$userdata->rfe_action,
											$userdata->rfe_start,
											$userdata->rfe_end, //(40*($y-1))+$x,
											$userdata->rfe_ip_validate_function);
										if ($r['success'])
								  		{
											show_prompt($conn, "Field saved.");
											sleep(1);
											@mysqli_free_result($r['result']);
										}
										else
										{	show_error($conn, "Error saving field."); sleep(1);	
										}
*/
									}
								}
							}
						} // Mark end of field

						if ($second_key == '2' || $second_key == '3') { // Clear existing start position
							if ($userdata->rfe_start !== false)
							{
								if (
									($second_key == '3') || 
									( ($second_key == '2') && 
									  (
										( ($userdata->rfe_type != RFE_WRAP) && ($userdata->rfe_start < $userdata->rfe_end) ) || 
										( ($userdata->rfe_type == RFE_WRAP) && ( ($userdata->rfe_start % 40) < ($userdata->rfe_end % 40))  && ( intval($userdata->rfe_start / 40) <= intval($userdata->rfe_end / 40) ) )
									  )
									)
								)
								{
									// Remove field start marker
									goto_xy($conn, ($userdata->rfe_start % 40), intval(($userdata->rfe_start + 40) / 40));
									ser_output_conn($frame_data[($userdata->rfe_start)].VNLEFT);
									// Remove trailing [
									goto_xy($conn, ($userdata->rfe_end % 40), intval(($userdata->rfe_end + 40) / 40));
									ser_output_conn($frame_data[($userdata->rfe_end)].VNLEFT);
									$userdata->rfe_start = false;
									$userdata->rfe_ip_validate = $userdata->rfe_notempty = false;
									$userdata->rfe_ip_validate_function = "";
								}
							}
							else 
								show_error($conn, "No field definition begun");
							goto_xy($conn, $x, $y);
						}

						if ($second_key == '4') // Reveal fields, accept a key and redisplay
						{
							// Get the list of response fields
/* Old direct database code
							$rvd = dbq("select fr_fieldname, fr_start, fr_end from frame_response where frame_id = ? order by fr_start", "i", $userdata->frame_data['frame_id']);
							if ($rvd['success'])
							{
								if ($rvd['numrows'] < 1)
									show_error($conn, "No fields found to reveal.");
								else
								{
									$fields = array();
									while ($fdata = @mysqli_fetch_assoc($rvd['result']))
									{
										$fields[] = $fdata;
										$i = count($fields)-1;
									}
*/
									$fields = $frame_response;
									foreach ($fields as $k => $f)
									{
										$tlx = $f['fr_start'] % 40;
										$tly = intval($f['fr_start'] / 40);

										$brx = $f['fr_end'] % 40;
										$bry = intval($f['fr_end'] / 40);

										$fields[$k]['tlx'] = $tlx;
										$fields[$k]['tly'] = $tly;
										$fields[$k]['brx'] = $brx;
										$fields[$k]['bry'] = $bry;
									
										$width = $brx - $tlx + 1;
										$fields[$k]['width'] = $width;

										$fname = substr($f['fr_fieldname'], 0, $width-1);
										goto_xy($conn, $tlx, $tly+1);
										ser_output_conn("[".$fname);
										goto_xy($conn, $brx, $bry+1);
										ser_output_conn("]");
									}

									show_prompt($conn, "_ to continue.");
	
									ser_input_insist("_", true);
					
									// Put them all back again...

									foreach ($fields as $f)
									{
										goto_xy($conn, $f['tlx'], $f['tly']+1);
										ser_output_conn(substr($frame_data, $f['fr_start'], $f['width']-1));
										goto_xy($conn, $f['brx'], $f['bry']+1);
										ser_output_conn($frame_data[$f['fr_end']]);
									}

/* Old database code
								}
								@mysqli_free_result($rvd['result']);		

							}
							else
								show_error($conn, "Cannot retrieve fields.");
*/
						goto_xy($conn, $x, $y);
						}

						if ($second_key == '5') // Delete a field
						{
							show_prompt($conn, "Field to delete: ");
							$fdelete = ser_input_str(15, "ABCDEFGHIJKLMNOPQRSTUVWXYZ".chr(0x7f));	
/* Old database code
							$fd = dbq("delete from frame_response where frame_id = ? and fr_fieldname = ?", "is", $userdata->frame_data['frame_id'], $fdelete);
							if ($fd['success'])
							{
								show_prompt($conn, $fdelete." deleted.");
								@mysqli_free_result($fd['result']);
							} else	show_error($conn, "Database error.");
*/
							$found = false;
							foreach ($frame_response as $k => $v)
								if (strtoupper($v['fr_fieldname']) == $fdelete)
								{
									array_splice($frame_response, $k, 1);
									$found = true;
								}
							if ($found) show_prompt($conn, $fdelete." deleted.");
							else	    show_error($conn, "Field not found.");
								

						}

						if ($second_key == '6') // Toggle frame variable display
						{
							if (isset($frame_flags['framevars']))
							{
								unset($frame_flags['framevars']);
								show_prompt($conn, "Frame variables disabled.");
							}
							else	
							{
								$frame_flags['framevars'] = true;
								show_prompt($conn, "Frame variables enabled.");
							}
/* Old database code
							$fvd = dbq("select IF(FIND_IN_SET('framevars', frame_flags), 1, 0) as framevars from frame where frame_id = ?", "i", $userdata->frame_data['frame_id']);					
							if ($fvd['success'])
							{
								$fvd_data = @mysqli_fetch_assoc($fvd['result']);
								@mysqli_free_result($fvd['result']);
								if ($fvd_data['framevars'] == 1) // Framevars enabled at the moment
								{
									$fvd = dbq("update frame set frame_flags=trim(both ',' from replace(concat(',', frame_flags, ','), ',framevars,', ',')) where frame_id = ?", "i", $userdata->frame_data['frame_id']);
									if ($fvd['success'])
										show_prompt($conn, "Frame variables disabled.");
									else	show_error($conn, "Database error.");
								}
								else
								{
									$fvd = dbq("update frame set frame_flags=concat(frame_flags,',framevars') where frame_id = ?", "i", $userdata->frame_data['frame_id']);
									if ($fvd['success'])
										show_prompt($conn, "Frame variables enabled.");
									else	show_error($conn, "Database error.");
								}
							}
							else	show_error($conn, "Database error.");
*/
						}

						if ($second_key == '7') { show_prompt($conn, "Graphics edit mode"); $g_edit = true; } // Graphics editing mode on
						if ($second_key == '8') { show_prompt($conn, "Text edit mode"); $g_edit = false; } // Graphics editing mode off
						if ($second_key == '9') { } // Fall through to Reposition cursor in case it moves (e.g. pressed ESC in commstar instead of F7)
						if ($second_key == 'R' or $second_key == 'r') {
							show_prompt($conn, "Route? (0-9, / to exit): ");
							$route_input = ser_input($conn, "0123456789/", true);
							if ($route_input == "/")
								show_prompt($conn, sprintf("% 39s", ""));	
							else
							{ 	// Set route destination
								show_prompt($conn, "Page_/<C>lear/_ exit:");
								$newdest = trim(ser_input_str(10,"C0123456789".chr(0x7f)));
								if ($newdest == "")
									show_prompt($conn, "No change.");
								else if (!preg_match('/C/', $newdest))
								{
									$frame_routes[$route_input][0] = 'Page';
									$frame_routes[$route_input][1] = $newdest;
									$frame_routes[$route_input][2] = null;
									show_prompt($conn, "Route set.");
								}
								else if ($newdest == "C")
								{
									unset($frame_routes[$route_input]);
									show_prompt($conn, "Route cleared.");
								}
							}
						}

						goto_xy($conn, $x, $y);
					}		
					break;
				}
				case chr(0x7f): // Delete
				{
					if (!$g_edit && (($x > 0) or ($y > 1))) // Ignore in graphics edit mode
					{
						$x--;
						if ($x < 0) {$x = 39; $y--;}
						$frame_data[(40*($y-1))+$x] = chr(32);
						ser_output_conn(VNLEFT." ".VNLEFT);
					}
					break;
				}
				default: // Some other character
				{
					if ($g_edit) // graphics editing mode
					{
						$key = ord(strtolower($key));
						$xor_val = 0;
						if (($key == 113) or ($key == 111)) $xor_val = 1;
						if (($key == 119) or ($key == 112)) $xor_val = 2;
						if (($key == 97) or ($key == 107)) $xor_val = 4;
						if (($key == 115) or ($key == 108)) $xor_val = 8;
						if (($key == 122) or ($key == 109)) $xor_val = 16;
						if (($key == 120) or ($key == 44)) $xor_val = 64;
						// For some reason switch wasn't playing ball here.

						if ($xor_val != 0)
						{
							debug ("Pulling character at ".$x.", ".$y." = ".((40*($y-1))+$x));
							$new_char = chr(ord($frame_data[(40*($y-1))+$x]) ^ $xor_val);
							//debug ("New character ".$new_char." Ord ".ord($new_char));
							$frame_data[(40*($y-1))+$x] = $new_char;
							ser_output_conn($new_char.VNLEFT);
						}
						else
						{
							if ($key == 32) // Space
							{
								$new_char = chr(32);
								$frame_data[(40*($y-1))+$x] = $new_char;
								ser_output_conn($new_char.VNLEFT);
							}
							else
							{
								show_error($conn, "Invalid graphic edit command");
								goto_xy($conn, $x, $y);	
							}
						}
					}
					// Deal with graphics editing mode here
					// Check not at end
					else if (($x < 39) or ($y < 22))
					{
						// Validate
						if (ord($key) >= 32 and ord($key) <= 126)
						{
							$frame_data[(40*($y-1))+$x] = $key;
							ser_output_conn($key);
							$x++;
							if ($x > 39) { $x = 0; $y++; }
						}
					}
					break;
				}

			}
		}

	}

	$userdata->frame_data["frame_pageno"] = substr($viewframe, 0, -1);
	$userdata->frame_data["frame_subframeid"] = substr($viewframe, -1);
	$userdata->frame_displaymode = FRAMEMODE_UPDATE; // Cause a refresh
	$userdata->editing = false;
	$userdata->preview = $preview_preserve;

} // End of editor


?>
