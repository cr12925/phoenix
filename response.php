<?php

function response_frame()
{

	global $userdata, $config;

	$incomplete = true;
	$current_field = 0;
	$response = $userdata->frame_previous;
	$userdata->frame_displaymode = FRAMEMODE_UPDATE;
	$send_frame = false;
	
	while ($incomplete)
	{

		$current_pos = $userdata->frame_response[$current_field]['fr_start'];
		$current_x = $userdata->frame_response[$current_field]['fr_start'] % 40;
		$current_y = intval($userdata->frame_response[$current_field]['fr_start'] / 40);
		$current_end_x = $userdata->frame_response[$current_field]['fr_end'] % 40;
		$current_end_y = intval($userdata->frame_response[$current_field]['fr_end'] / 40);
		$current_len = $userdata->frame_response[$current_field]['fr_end'] - $current_pos + 1;
			// current_len only meaningful if not a wrap field

		if (isset($userdata->frame_response[$current_field]['data']))
			$current_data = $userdata->frame_response[$current_field]['data'];
		else
			$current_data = "";

		//$current_wrap = preg_match('/pagewrap/', $userdata->frame_response[$current_field]['fr_attr']);
		$current_wrap = false;
		if ($current_end_y <> $current_y) $current_wrap = true; // If multiline, wrap.

		if ($current_wrap)
		{
			$current_wrap_linelen = $current_end_x - $current_x;
			$current_wrap_maxdatalength = $userdata->frame_response[$current_field]['fr_maxwraplength'];
			$current_wrap_pos = 0;
			if (isset($userdata->frame_response[$current_field]['editing_position']))
				$current_wrap_pos = $userdata->frame_response[$current_field]['editing_position'];
		}

		if(preg_match('/password/', $userdata->frame_response[$current_field]['fr_attr']))
			$current_password = "*";
		else	$current_password = false;
	
		$current_valid = VDKEYGENERAL.ESC;

		switch ($userdata->frame_response[$current_field]['fr_limit_input'])
		{
			case 'numeric': $current_valid = VDKEYNUMERIC.VNLEFT.VDKEYBACKSPACE; break;
			case 'alpha':	$current_valid = VDKEYALPHA.VDKEYSPACE.VNLEFT.VDKEYBACKSPACE; break;
			case 'alphanumeric': $current_valid = VDKEYALPHANUMERIC.VDKEYSPACE.VNLEFT.VDKEYBACKSPACE; break;
			case 'alltext': $current_valid = VDKEYSPACE.VDKEYALPHANUMERIC.VDKEYPUNCT.VNLEFT.VDKEYBACKSPACE; break;
		}


		if (!$current_wrap)
		{
			goto_xy_pos($current_pos); // Start of input area
	
			ser_output_conn(VCURSORON);

			$ret = ser_input_str_full($current_len,
					$current_pos,
					$current_valid.VNUP.VNDOWN,
					false,
					$current_data,
					$current_password); 
		}
		else
		{
			// Do input wrap function here
			$ret = wrap_input_full($current_data,
					$current_valid.VNUP.VNDOWN.VNLEFT.VNRIGHT,
					$current_x, $current_y,
					$current_end_x, $current_end_y,
					$current_wrap_maxdatalength, $current_wrap_pos);
					// Note: if current_wrap_line < current_wrap_displaylines-1. then the whole of current_data will be displayed and we will start part way down. Otherwise we will always be on the last line, so that the pre-populate of the frame data will always show the tail end of the data if it is longer than wrap_displaylines. E.g. say there are 10 displaylines, and we are on current_wrap_line=4 (i.e. 5th line), we'll have pre-populated 5 lines and will land on the 5th read to keep typing. If displaylines is, say, 5, and we are on wrap line 9, then we'll be on the 5th line and will have pre-populated the last 5 of the 10 lines (because the line count starts at 0).
					
		}

		if ($ret[0] == RX_NAVUP && $current_field > 0)
		{
			if (preg_match('/notempty/', $userdata->frame_response[$current_field]['fr_attr']) && strlen($ret[1]) == 0)
				show_error($userdata->conn, "Field cannot be empty.");
			else
			{	
				$userdata->frame_response[$current_field]['data'] = $ret[1];
				$current_field--;
			}
		}

		if ($ret[0] == RX_NAVDOWN && $current_field < sizeof($userdata->frame_response))
		{
			if (preg_match('/notempty/', $userdata->frame_response[$current_field]['fr_attr']) && strlen($ret[1]) == 0)
				show_error($userdata->conn, "Field cannot be empty.");
			else
			{	
				$userdata->frame_response[$current_field]['data'] = $ret[1];
				$current_field++;
			}
		}

		if ($ret[0] == RX_STRING)
		{
			
			if (preg_match('/notempty/', $userdata->frame_response[$current_field]['fr_attr']) && strlen($ret[1]) == 0)
				show_error($userdata->conn, "Field cannot be empty.");
			else
			{
				$userdata->frame_response[$current_field]['data'] = $ret[1];
				if ($current_field < sizeof($userdata->frame_response)-1)
					$current_field++;
				else
				{
					show_prompt($userdata->conn, "<S>end, <Q>uit, <C>ontinue? ");
					$prompt_resp = ser_input_insist("SQC", true, true); // 1st true is block; 2nd is force upper
					switch ($prompt_resp)
					{
						case 'S': $send_frame = true; // Fall through
						case 'Q': $incomplete = false; break;
						case 'C': $current_field = 0; break; // Go back to start
					}
				}
			}
		}

		if ($ret[0] == RX_STAR_CMD)
		{
			$incomplete = false;
			$response = $ret[1];
		}

		if ($ret[0] == RX_REDISPLAY or $ret[0] == RX_UPDATE)
		{
			if ($current_wrap)
				if ($ret[0] == RX_REDISPLAY) // $ret[1] is an array which contains not only the string but the current editing position
				{
					$userdata->frame_response[$current_field]['data'] = $ret[1][0];
					$userdata->frame_response[$current_field]['editing_position'] = $ret[1][1];
				}
				else
				{
					$userdata->frame_response[$current_field]['data'] = $ret[1];
					$userdata->frame_response[$current_field]['editing_position'] = 0;
				}
			if (isset($userdata->frame_response[$current_field]['data']))
				debug ("Input: Redisplay or update: String received: ".$userdata->frame_response[$current_field]['data']);
			ser_output_conn(VCLS);
			send_frame_title_userdata();
			send_frame_userdata(0, false);

			foreach ($userdata->frame_response as $k => $v)
			{
				if ($ret[0] == RX_UPDATE)
					unset($userdata->frame_response[$k]['data']);
				else
				{
					
					$start_y = intval($userdata->frame_response[$k]['fr_start'] / 40);
					$end_y = intval($userdata->frame_response[$k]['fr_end'] / 40);
					$wrap_field = false;
					if ($end_y != $start_y) $wrap_field = true;
					if (!$wrap_field) // We don't just splat the data on the frame for a wrap field because it needs to be wrapped
						if (isset($userdata->frame_response[$k]['data']))
						{
							goto_xy_pos($userdata->frame_response[$k]['fr_start']);
							if (preg_match('/password/', $userdata->frame_response[$k]['fr_attr']))
								ser_output_conn(sprintf("%'*".strlen($userdata->frame_response[$k]['data'])."s", ""));
							else
								ser_output_conn($userdata->frame_response[$k]['data']);	
						}
					else
					{ // Render & display wrap field.
						if (isset($userdata->frame_response[$k]['data']))
						{
							debug("Response-Frame-Display: Would display wrapped data here");
						}
					}
					
				}
			}
			if ($ret[0] == RX_UPDATE) // CLear fields
				$current_field = 0;
				
		}

	}

	ser_output_conn(VCURSOROFF);

	if ($send_frame)
	{
		$fields = array();
		foreach ($userdata->frame_response as $k => $v)
			$fields[$userdata->frame_response[$k]['fr_fieldname']] = $userdata->frame_response[$k]['data'];
		//$ip_function_name = "SUBMIT"; // $userdata->frame_data['frame_fr_ip_function'];
		//if (strlen($userdata->frame_data['frame_fr_ip_function']) > 0)
			$ip_function_name = $userdata->frame_data['frame_fr_ip_function'];
	
		if ($ip_function_name === null)
			$ip_function_name = "SUBMIT";

		if (strlen($ip_function_name) == 0)
			show_error($userdata->conn, "IP function call impossible");
		else
		{
			$result = ip_function($userdata->frame_data['ip_id'],
					$ip_function_name,
					$userdata->frame_data['frame_pageno'].$userdata->frame_data['frame_subframeid'],
					$fields);
			
			switch ($result[0]) {
				case IPR_GOTOFRAME:	$response = $result[1]; break;
				case IPR_UNKNOWNFUNCTION:	show_error($userdata->conn, "Unknown IP function"); break;
				case IPR_CALLFAILURE:		show_error($userdata->conn, "IP Call failed"); break;
				case IPR_BADDATA:		show_error($userdata->conn, "IP rejected frame data"); break;
			}
		}

	}
	else
	{
		show_error ($userdata->conn, "Frame not sent. 0 to continue.");
		$char = ser_input_insist("0", true);
	}

	return $response;

}

function wrap_input_full (
	$current_data,
	$valid,
	$tlx, $tly,
	$brx, $bry,
	$max_data_length,
	$current_data_pos = -1)
{

	global $userdata;

	//debug ("wrap_input_full() called: '".$current_data."', len ".strlen($current_data).", '".$valid."', $tlx, $tly, $brx, $bry, $max_data_length, $current_data_pos");

	if ($current_data == "") $current_data = VNLINESTART; // Always make sure there's at least one character in there.

	// NB, All variables are 0-based, so if (e.g.) displaylines==1, there are two lines

	$linelength = ($brx-$tlx)+1;
	$displaylines = ($bry-$tly)+1;

	// Add some useful characters to the input validator

	$valid .= VNLEFT.VDKEYBACKSPACE.VNLINESTART."_".ESC;

	if ($current_data_pos == -1) // Position at end of string - this is the default until we work out a clever way of preserving position on redisplay
		$current_data_pos = strlen($current_data) - 1; // There's always a space at the end of the string.. so that there is always something to insert before.

	$render_result = render_wrap_str($current_data, $linelength, $current_data_pos); // Comes back as wrapped lines of length 0...($brx-$tlx) in an array

	$rendered_str = $render_result['wrapped'];
	$current_data_line = $render_result['ypos'];
	$current_data_x = $render_result['xpos'];

	if ($current_data_line < $displaylines) // I.e. current line is within the display size, so display from line 0
		$first_line = 0;
	elseif ((count($rendered_str) > $displaylines) and ($current_data_line >= (count($rendered_str) - $displaylines))) // I.e. display line is within 1 screenful of the end, display up to the last $displaylines lines
		$first_line = count($rendered_str) - $displaylines -1; // -1 because otherwise we end up with a blank line at the end.
	else // Put us about in the middle
	{
		$middle = intval(($displaylines+1)/2); // Note $displaylines >= 1 (i.e. 2 lines) on a multiline.
		$first_line = $current_data_line - $middle;
	}
	

	//$current_data_line = count($rendered_str)-1; // Change this if $current_data_pos is ever anything but the end
	//$current_data_x = strlen($rendered_str[count($rendered_str)-1])-1; // Ditto

	// Redisplay -- this will normally have been done during the frame display routine, but we have it here
	// during development so that we can test
	
	goto_xy_win ($tlx, $tly, 0, 0);
	if ($current_data != VNLINESTART) wrap_redisplay ($tlx, $tly, $linelength, $displaylines, $rendered_str, false, $first_line); // Don't redisplay on an empty message
	show_prompt($userdata->conn, "ESC-ESC to exit multiline editor");
	goto_xy_win ($tlx, $tly, $current_data_x, $current_data_line - $first_line);
	ser_output_conn(VCURSORON);

	$editing_data = true;

	while ($editing_data)
	{
		debug ("Wrap-Input: Waiting for character");
		$last_char = ser_input_insist($valid."*", true);
		debug ("Wrap-Input: Got character ".ord($last_char));
		
		// Handle * input
		if ($last_char == "*")
		{
                        $star_string = phoenix_star();
                        if ($star_string == "00") // Redisplay frame
                                $ret = array(RX_REDISPLAY, array($current_data, $current_data_pos)); // We return the actual input string here, not 00 so that a response editor can store it
                        else if ($star_string == "09")
                                $ret = array(RX_UPDATE, $current_data);
                        else $ret = array(RX_STAR_CMD, $star_string); // This time we don't return the string - it gets lost

                        if ($star_string != "*") // I.e. not inserting a * character
                                $editing_data = false;

                        ser_output_conn(VCURSOROFF);
                        goto_xy_win($tlx, $tly, $current_data_x, $current_data_line - $first_line);
                        ser_output_conn(VCURSORON);
		}
		
		if ($editing_data and $last_char == '*')
		{
			// See if * is in our valid list
			if (!strpos($valid, "*"))
				$last_char = false;
		}

		// If still editing (i.e. it wasn't a * command which wasn't **) and we have a valid input
		if ($editing_data && $last_char !== false and $last_char !== 0)
		{

			if ($last_char == VNUP) // Move up
			{
				debug ("Wrap_Move-Up: Current data x, line: $current_data_x , $current_data_line");
				// Only do anything if we are not on the first line of the rendered data
				if ($current_data_line != 0)
				{
					// Is the line above shorter than our current x position? If so
					// We move to the end of the line above. (One character after end unless
					// line is completely full, in which case go *to* the end
					$receiving_line_length = strlen($rendered_str[$current_data_line-1]);
					$move_left = 0;
					if ($receiving_line_length != $linelength) // Receiving line is not full
					{
						//debug("Wrap-Move-Up: Receiving line not full");
						if (($current_data_x + 1) > $receiving_line_length) // Receiving line is shorter
						{
							$move_left = $current_data_x - $receiving_line_length + 1;
							//debug("Wrap-Move-Up: Shift left by ".$move_left);
							$current_data_x = $receiving_line_length-1;
							// If last chracater of receiving line is CR, move data pointer one more space
							//if ($rendered_str[$current_data_line-1][$receiving_line_length-1] === VNLINESTART)
							//{
								//$current_data_x--; $move_left++;
							//}
						}
					}

					$current_data_line--;
					//debug ("Wrap-Move-Up: Old data pos: ".$current_data_pos);
					$current_data_pos = calc_data_pos ($rendered_str, $current_data_line, $current_data_x);
					//debug ("Wrap-Move-Up: New data pos: ".$current_data_pos);
				
					//debug_data_pos ($current_data_pos, $current_data);

					$left_length = 0;
					$left_start = $current_data_pos-1;
					$left_string = "[END OF STRING]";

					if ($left_start >= 0) // Something to display
					{
						$left_length = $left_start;
						if ($left_length > 2) $left_length = 2;
						$left_string = substr($current_data_line, $left_start, $left_length);
					}

					if ($current_data_pos != 0)
						if ($current_data_pos >= 2)
							$left_length = 2;
						else
							$left_length = 1;

					$right_length = 0;
					$right_start = $current_data_pos+1;
					$right_string = '[END OF STRING]';

					if ($right_start <= strlen($current_data_line))
					{	// THere are at least some characters to display
						$right_length = strlen($current_data_line) - $right_start;
						if ($right_length > 2) $right_length = 2;
						$right_string = substr($current_data_line, $right_start, $right_length);
					}


					//debug ("Wrap-Move-Up: Characters surrounding new current_data_pos: $left_string .. ".$current_data_line[$current_data_pos]." .. $right_string");

					//debug ("Wrap-Move-Up: New current data line: ".$current_data_line." vs present first_line ".$first_line);
	
					if ($current_data_line < $first_line) // Redraw the window
					{
						// If we have gone off the top of the window, move $first_line as far as we can
						//$first_line--;
						$first_line -= ($displaylines - 1);
						if ($first_line < 0) $first_line = 0;
						// Redisplay window
						goto_xy_win ($tlx, $tly, 0, 0);
						wrap_redisplay ($tlx, $tly, $linelength, $displaylines, $rendered_str, false, $first_line);
						ser_output_conn(VCURSOROFF);
						//debug("Wrap-Move-Up: After redisplay, current_data_x = $current_data_x");
						goto_xy_win ($tlx, $tly, $current_data_x, ($current_data_line - $first_line)); 
						ser_output_conn(VCURSORON);
					}
					else 	// Just echo an up character and work out if we need to move left
					{
						ser_output_conn(VCURSOROFF);
						ser_output_conn(VNUP);
						ser_output_conn(str_pad("", $move_left, VNLEFT));
						ser_output_conn(VCURSORON);
					}

				}
			}
			elseif ($last_char == VNDOWN) // Move down
			{
				//debug ("Wrap-Move-Down: Current Data Line: $current_data_line ; Last data index: ".count($rendered_str)-1);
				// Only attempt to move  if we are not on the last rendered data line
				if ($current_data_line < (count($rendered_str)-1))
				{
					$receiving_line_length = strlen($rendered_str[$current_data_line+1]);
					$move_left = 0;
					// Is receiving line shorter than where we are now?
					if ($receiving_line_length != $linelength) // I.e. line is not full
						if (($current_data_x+1) > $receiving_line_length) // Receiving line is shorter
						{
							$move_left = $current_data_x - $receiving_line_length;
							$current_data_x = $receiving_line_length-1;
						}

					$current_data_line++;
				
					$current_data_pos = calc_data_pos ($rendered_str, $current_data_line, $current_data_x);
					ser_output_conn(VCURSOROFF);
					// Do we need to shift the window?
					if ($current_data_line >= ($first_line + $displaylines + 1))
					{
						//$first_line++;
						$first_line = $current_data_line - 1;
						wrap_redisplay ($tlx, $tly, $linelength, $displaylines, $rendered_str, false, $first_line);
						//goto_xy_win ($tlx, $tly, $current_data_x, $displaylines); // Y position should always be bottom line
						//ser_output_conn(VCURSORON);
					}
					else // Just move without redisplay
					{
						ser_output_conn(VNDOWN);
						ser_output_conn(VNCR);
					}
					// Changed from the apparently more efficient method commented out because Commstar seems to do a CR when it receives VNDOWN.
					goto_xy_win ($tlx, $tly, $current_data_x, $current_data_line - $first_line);
					//ser_output_conn(str_pad("", $move_left, VNLEFT));
					ser_output_conn(VCURSORON);

				}
			}
			elseif ($last_char == VNLEFT or $last_char == VDKEYBACKSPACE) // Move left (possibly delete)
			{
				//$redisplay_from_char = false;
				$moved = false;
				$previous_render = $rendered_str;

				if ($current_data_pos > 0) // Not on first character, so a left move is permissible
				{
					$old_current_data_line = $current_data_line;
					// Was it a delete?
					if (($last_char == VDKEYBACKSPACE))
					{
						debug("Wrap-Move-Left: Backspace processing");
						// Remove the necessary character
						$tmpdata = $current_data;
						$current_data = substr($tmpdata, 0, $current_data_pos-1);
						$current_data .= substr($tmpdata, $current_data_pos);
						// Re-render
						$render_result = render_wrap_str($current_data, $linelength, $current_data_pos); // Comes back as wrapped lines of length 0...($brx-$tlx) in an array
						$rendered_str = $render_result['wrapped'];
						//$moved = true;
					}

					// Move left
					debug("Wrap-Move-Left: Old current_data_x $current_data_x");
					$current_data_x--;
					debug("Wrap-Move-Left: New current_data_x: $current_data_x");
					if ($current_data_x < 0) // Moved off left of window
					{
						debug("Wrap-Move-Left: Moved off left of window");
						$current_data_line--;
						$moved = true;
						debug("Wrap-Move-Left: New current_data_line: $current_data_line vs first_line $first_line, strlen(rendered_str(current_data_line))-1 = ".(strlen($rendered_str[$current_data_line])-1).", rendered_str(current_data_line) = /".$rendered_str[$current_data_line]."/");
						$current_data_x = strlen($rendered_str[$current_data_line])-1; // Should always be at least one character in the rendered string - even if it's just a CR
						debug("Wrap-Move-Left: Revised current_data_x: $current_data_x");
						// NB should never be on first line when that happens - IF above catches that
						if ($current_data_line < $first_line) // We are top left of window doing adelete, so need to redraw the window
						{
							debug("Wrap-Move-Left: Moved off left of window on first line");
							//$first_line--;
							$first_line -= 5; if ($first_line < 0) $first_line = 0; // Gives a few lines at top of window, or start of data whichever is later.
							$moved = true;
							// Probably should try and put the line we've just landed on 
							// 2nd from bottom here
							// If we are here, then we are now ON first_line.
							if ($current_data_line == count($rendered_str)) // We are on last line of actual text (not window)
								$first_line = $current_data_line - $displaylines; // Put it on the bottom row
							else
								$first_line = $current_data_line - $displaylines - 1; // Put it one up from bottom rot
							// Clear up in case first_line now negative
							if ($first_line < 0) $first_line = 0;
						}
					}

					if ($moved) // This was in the 'if' before.  or ($last_char == VNLEFT) or ($last_char == VDKEYBACKSPACE))
					{
						// This gets triggered on a window move or a delete. Need to make it more intelligent on a delete - see if it can just do VNLEFT.SPACE.VNLEFT if (a) stayed on same line (i.e. didn't move up a line), and (b) rest of line apart from deleted character is blank so doesn't need to be re-drawn
						debug("Wrap-Moved-Left: Redisplay required");
						ser_output_conn(VCURSOROFF);
						wrap_redisplay ($tlx, $tly, $linelength, $displaylines, $rendered_str, $previous_render, $first_line, $moved, $current_data_pos);
						goto_xy_win ($tlx, $tly, $current_data_x, $current_data_line - $first_line);
						ser_output_conn(VCURSORON);
						
					}
					else // Not moved the window
					{
						debug("Wrap-Move-Left: NO redisplay required");
						if ($last_char == VDKEYBACKSPACE)
						{
							if ((($current_data_x+1) == strlen($rendered_str[$current_data_line]))) // Just go left, erase and left - if we weren't at the start of a line
								ser_output_conn(VNLEFT." ".VNLEFT);
							else
							{
								// Limit the number of lines to redisplay to the ones we might actually need to redisplay (i.e. don't blank off already blank lines)
								$redisplaylines = $displaylines - ($current_data_line - $first_line); // Calculates bottom of window up to current line.
								if (($current_data_line + $redisplaylines) > count($rendered_str)) // See if there are more lines on the screen than we have to render - if so, reduce the number of lines to render accordingly
									$redisplaylines = count($rendered_str) + 1 - $current_data_line; // Add 1 in case we need to blank a line.
	
								// Don't re-use the next line. It has been modified to redraw from current line downwards
								//wrap_redisplay ($tlx, $tly + ($current_data_line - $first_line), $linelength, $displaylines - ($current_data_line - $first_line), $rendered_str, $previous_render, $current_data_line, true, $current_data_pos);
								wrap_redisplay ($tlx, $tly + ($current_data_line - $first_line), $linelength, $redisplaylines, $rendered_str, $previous_render, $current_data_line, true, $current_data_pos);
								goto_xy_win ($tlx, $tly, $current_data_x, $current_data_line - $first_line);
							}
						}
						else // Just move left if we weren't deleting
							ser_output_conn(VNLEFT);

						if ($old_current_data_line != $current_data_line) // We moved lines but didn't redraw the window
						{
							debug("Wrap-Move-Left: Move to previous line without redisplay");
							goto_xy_win ($tlx, $tly, $current_data_x, $current_data_line - $first_line);
							// Old code below
							//ser_output_conn(VNLINESTART.VNLEFT); // Works even if on start of line, since we did not echo the VNLEFT on input - but probably doesn't work on the javascript Viewdata client.
							// One way or another, now sitting on last character of previous line
							//debug ("Wrap-Move-Left: Moving ".(39-($tlx+$linelength-1))." spaces left from right end of frame");
							//ser_output_conn(str_pad("", 39-($tlx+$linelength-1), VNLEFT));
							// By here, we are sitting at the right hand edge of the window.
							// So now need to move to current_data_x
							//debug ("Wrap-Move-Left: \$linelength=$linelength, \$current_data_x=$current_data_x, moving ".($linelength-$current_data_x-1)." left from right edge of window");
							//ser_output_conn(str_pad("", $linelength-$current_data_x-1, VNLEFT));
						}
					}
				}

				$current_data_pos = calc_data_pos($rendered_str, $current_data_line, $current_data_x);

				//debug ("Wrap-Move-Left: New data position $current_data_pos");

			}
			elseif ($last_char == VNRIGHT)
			{

				$old_current_data_line = $current_data_line;
				$old_current_data_x = $current_data_x;
				$old_first_line = $first_line;

				//debug("Wrap-Move-Right: Current co-ords: $current_data_x, $current_data_line");
				// Are we within a line?
				if ($current_data_x <= strlen($rendered_str[$current_data_line])-2)
					$current_data_x++;
				else
				{
					// moving off end of line
					// Only do anything if not on last line
					if ($current_data_line < count($rendered_str)-1)
					{
						$current_data_x=0;
						$current_data_line++;
							//debug("Wrap-Move-Right: moving to line $current_data_line - current first_line = $first_line and displaylines=$displaylines");
						if ($current_data_line > ($first_line + $displaylines)) // Need to move first line
							$first_line++;
					}
					
				}

				// Recalculate position
				$current_data_pos = calc_data_pos($rendered_str, $current_data_line, $current_data_x);

				//debug ("Wrap-Move-Right: New data pos $current_data_pos");
				//debug("Wrap-Move-Right: New co-ords: $current_data_x, $current_data_line");

				if ($first_line != $old_first_line) // Need to re-draw
				{
					//debug("Wrap-Moved-Right: Redisplay required");
					ser_output_conn(VCURSOROFF);
					wrap_redisplay ($tlx, $tly, $linelength, $displaylines, $rendered_str, false, $first_line, true);
					goto_xy_win ($tlx, $tly, $current_data_x, $current_data_line - $first_line);
					ser_output_conn(VCURSORON);
				}
				else // No redraw, but are we moving lines?
				{
					if ($current_data_line != $old_current_data_line)
						ser_output_conn(VCURSOROFF.VNLINESTART.VNDOWN.str_pad("", $tlx, VNRIGHT).VCURSORON);
					elseif ($current_data_x != $old_current_data_x)  // Not changing lines - check if we have moved at all!
						ser_output_conn(VNRIGHT);
				}
				
			}
			else
			{
				if ($last_char == ESC) // ESC (which, from this routine, will have been ESC-ESC from the user)
				{
					$editing_data=false;	
					$ret = array(RX_STRING, $current_data);
				}

				// Are we at maximum length?
				
				if ($editing_data) // We didn't get ESC-ESC
				{
					if (strlen($current_data) == ($max_data_length-1)) // We are full
					{
						show_error($userdata->conn, "Field full");
						goto_xy_win($tlx, $tly, $current_data_x, $current_data_line);
					}
					else
					{
						//debug ("Wrap-Insert: substring to move: ".substr($current_data, $current_data_pos));
						//debug("Wrap-Insert: Inserting character ".ord($last_char));
						if ($last_char == '_')
						{
							//debug("Wrap-Insert: Substituting _ for VNLINESTART");
							$last_char = VNLINESTART; // Convert '#' to a CR
						}
 
						$current_data = substr($current_data, 0, $current_data_pos).$last_char.substr($current_data, $current_data_pos, strlen($current_data)-$current_data_pos+1);

						//$current_data[$current_data_pos] = $last_char;
						$current_data_pos++;
			
						debug("Wrap-Insert: current_data_line = $current_data_line, current_data_x = $current_data_x, strlen(current) = ".strlen($rendered_str[$current_data_line]).", linelength = $linelength, strlen(current_data)=".strlen($current_data).", current_data_pos = $current_data_pos");
						// Flag up if we just need to echo the character:
						if ( // Either we are at the end of the whole data, or we are at the end of a current line and less than line length
							(	 preg_match ('/^(\s\r)*$/', substr($rendered_str[$current_data_line], $current_data_x+1))
							 and
							 ($current_data_x < $linelength)
							 and
							 ($last_char != VNLINESTART)
							 and
							 ($current_data_pos == (strlen($current_data) - 1)) // Must be at end of string, otherwise we're inserting and need to re-draw
						   	)
							or
							(
								$current_data_x == strlen($rendered_str[$current_data_line])
								and
								$current_data_x < $linelength
							)
						)
						{
							//debug ("Wrap-Input-Insert: Simple Output");
							ser_output_conn($last_char);
							$current_data_x++;
							$render = render_wrap_str($current_data, $linelength, $current_data_pos);
						}
						else {
							$render = render_wrap_str($current_data, $linelength, $current_data_pos);
							$old_current_data_line = $current_data_line;
							$old_rendered_str = $rendered_str;
							$rendered_str = $render['wrapped'];
							$current_data_line = $render['ypos'];
							$current_data_x = $render['xpos'];
			
							//debug ("Wrap-Insert: Post-Render: \$current_data_line=$current_data_line, count lines = ".count($rendered_str).", first_line = $first_line, displaylines = $displaylines");
							$wrapped = false;

							if ($old_current_data_line != $current_data_line) // We have wrapped
								$wrapped = true;

							$moved = false;
							if (($current_data_line - $first_line) > $displaylines) // After the render, we have moved off the end of the window
							{
								$first_line = $current_data_line -1; // Start at top of window
								$moved = true;
							}
				
							// Now work out what to redisplay thereafter.
							$lines_output = wrap_redisplay($tlx, $tly, $linelength, $displaylines, $rendered_str, $old_rendered_str, $first_line, $moved, false);
							if (!$moved and !$wrapped and ($lines_output == 1)) // We only did the one line (ie all other lines were as before)
							{
								if ($current_data_x + $tlx > 20) // use VNLEFT to get back there
								{
									ser_output_conn(VNLEFT);
									// We are now on last RH character of window, whatever the linelength.
									ser_output_conn(str_pad("", $linelength - $current_data_x + 1, VNLEFT));
								}
								else // From the left - use VNRIGHT
								{
									ser_output_conn(str_pad("", (39-($tlx+$linelength)+$current_data_x), VNRIGHT));
									ser_output_conn(VNUP);
								}
							}
							else	goto_xy_win($tlx, $tly, $current_data_x, $current_data_line - $first_line);
						}
					}
				}
			}

		}

	}

	return ($ret);
}
	
function render_wrap_str ($data, $len, $pos) // Renders $data (ascii text; line breaks are 0x0d) into lines of $len, 
					// Word-wrapping as it goes. $pos is the current cursor position in $data, indexed from the 0 byte
{

	//debug ("Rendering multiline string: ('".$data."', $len, $pos)");
	$wrapped = array();

	$processed = 0;
	$xpos = $ypos = false;
	$original_data = $data;

	while (strlen($data) > 0)
	{
		$line = substr($data, 0, $len);
		
		//debug("Wrap-Render: Processing /$line/");
		// First, look for an end of line, because if there is one then it does what it says on the tin
		$debug_hex = "";
		for ($a = 1; $a < 5; $a++)
			if (strlen($line) >= $a)
				$debug_hex .= "chr(".ord($line[$a-1]).") ";

		//debug("Wrap-Render: Start of \$line: ".$debug_hex);
			
		$eolpos = strpos($line, VNLINESTART);

		if ($eolpos !== false)
		{	//debug ("Wrap-Render: Found CR at $eolpos");
			$line = substr($line, 0, $eolpos+1); // Includes the line break
		}
		else // No EOL
		{
			if (($spacepos = strrpos($line, 32)) > 0)
			{
				//debug ("Wrap-Render: Found space at $spacepos");
				$line = substr($line, 0, $spacepos+1); // Includes the space in case of inserted characters
			}
		}
	
		$wrapped[] = $line; // NB fall through is no 0x0d and no space - just get the whole line.

		//debug ("Wrap-Render: Rendered /$line/ - length ".strlen($line)." xpos = ".$xpos.", processed = ".$processed);

		if ($xpos === false && ($pos <= ($processed+strlen($line)))) // I.e. the position we are looking for is in the line we just processed. This is < not <= because strlen will be > 0 if there are any characters. So if, e.g., pos = 0 and processed = 0 and there is just the one character in the string, strlen will be 1, but we need xpos to be 0. But with < instead of <=, we were missing this code out when we wrapped to the start of a line?
		{
			$xpos = $pos - $processed;
			$ypos = count($wrapped)-1;
			debug ("Found desired position in string: $xpos, $ypos");

		}

		$processed += strlen($line);

		if (strlen($line) == strlen($data)) // I.e. all of the data was rendered
			$data = "";
		else // Shorten data appropriately
			$data = substr($data, -1 * (strlen($data) - strlen($line)));
		//debug ("Source data shortened to: /$data/");

	}

	//if ($pos != 0) debug ("Wrap-Render: pos = $pos, processed = $processed, strlen(original_data) = ".strlen($original_data).", ypos = $ypos, xpos = $xpos, ord(original_data[pos-1]) = ".ord($original_data[$pos-1]));
	if (($pos < $processed) && ($pos != 0) && (ord($original_data[$pos-1]) == 0x0d)) // On a CR but not at end of data so we need to be on the next line down - and not at start of string (otherwise we end up on a line below where we should be at the start). $pos-1 because after inserting a CR we will be pointing to the character *after* the CR
	{
		debug ("Wrap-Render: Moving cursor to start of next line because was on a CR.");
		$ypos++; $xpos = 0;
	}

	//debug ("Multiline string rendered");
	$result = array ('wrapped' => $wrapped, 'xpos' => $xpos, 'ypos' => $ypos);
	//debug ("Multi-Wrap: Co-ords found: $xpos, $ypos");
	return ($result);

}

function wrap_redisplay ($tlx, $tly, $linelength, $displaylines, $rendered_str, $old_rendered_str, $first_line, $moved = true, $start_char = 0)
{

	// tlx, tly are top left co-ordinates on the actual display
	// linelength is the length of each line
	// displaylines is the number of lines in the window
	// rendered_str is the array of word-wrapped strings
	// old_rendered_str - if available, is the old rendering for comparison and display efficiency (don't display if nothing changed)
	// first_line is the line to be displayed at (tlx, tly) (may not be first line in the array if we have scrolled)
	// moved means this is a redisplay after window move, so all lines need displaying
	// start_char is which character in the whole string (not the rendered portion) we are starting with. This will be
	// forced to 0 if the window has moved

	// But if we have moved, we start from 0 effectively because we need to redisplay everything in the window

	if ($moved)	$start_char = 0;
	
	if ($start_char == 0)
		$redisplay_all = true; // Just means we're doing all of it, but we may skip lines which are unchanged
	else
		$redisplay_all = false;

	// If start_char is >0, it will be assumed that the cursor is in the right place to start rendering
	// from that character, otherwise the characters used to navigate there will use the same data capacity
	// as just re-rendering.

	$a_x = $a_y = 0;

	if ($start_char != 0)
	{
		// Fine line and x-index in the render
		$a = 0;
		if (count($rendered_str) == 0) // Something badly wrong - can't have empty render if we have start_char != 0
			$start_char = 0;
		else
		{
			//debug ("Wrap-Redisplay: Locating start character by line & position");
			$total_chars = 0;
			$line_count = 0;
			while (($a_x == 0) && ($a_y == 0) && ($line_count < count($rendered_str)))
			{
				if (($total_chars + strlen($rendered_str[$line_count])) > $start_char) // start_char is on this line
				{
					$a_x = ($start_char - $total_chars);
					$a_y = $line_count;
					//debug("Wrap-Redisplay: Located at ($a_x, $a_y)");
				}
				else
				{
					$total_chars += strlen($rendered_str[$line_count]);
					$line_count++;
				}
			}
		}
	}

	$index = $first_line; // Compute index into string array

	if ($redisplay_all)
		goto_xy_win ($tlx, $tly, 0, 0); // We don't reposition if starting from a particular character, because we will already be in the right screen position

	debug("Wrap-Redisplay: Starting count on line $index");

	$lines_output = 0; // Used to work out if we can reposition on same line, or need to move more globally after output

	while (($index < count($rendered_str)) and (($index - $first_line) <= $displaylines)) // Must have strings to display and still be within the window
	{

		$linedata = $rendered_str[$index];

		$display_this_line = false;

		if ($redisplay_all)
		{
			debug ("Wrap-Redisplay: Redisplaying whole window");
			if (
				($old_rendered_str === false) 
			or 	(!isset($old_rendered_str[$index])) 
			or 	($old_rendered_str[$index] != $linedata) 
			or 	($moved)
			) // Only spit out the whole line if we need to: either if the window moved, or the old line is different to the new line. Cope also with there being no old rendered string to compare.
			{
				debug ("Wrap-Redisplay: either no old string, or old string different, or forced redisplay because window moved");
				$display_this_line = true;
			}
		}
		elseif ($a_y < $index) // Either a_y=0 so we want redisplay (but that should be caught in the if statement above), or we are past the character we needed to start redisplaying at
		{
			if (
				($old_rendered_str === false) 
			or 	(!isset($old_rendered_str[$index])) 
			or 	($old_rendered_str[$index] != $linedata)
			) // Only spit out the whole line if we need to: either if the window moved, or the old line is different to the new line. Cope also with there being no old rendered string to compare.
			{
				debug ("Wrap-Redisplay: a_y == 0 and either no old line or old line is different");
				$display_this_line = true;
			}
			//else
			//{	debug ("Wrap-Redisplay: a_y != 0 so need to display");
				//$display_this_line = true;
			//}
		}
		elseif ($a_y == $index) // We found our start line for partial redisplay
			$display_this_line == true;
			
		if ($display_this_line)
		{
			debug ("Wrap-Redisplay: Displaying line");

			if ($linedata[strlen($linedata)-1] == VNLINESTART) // Strip the carriage return off the end
				$linedata = substr($linedata, 0, -1);

			$linedata = str_pad($linedata, $linelength); // Add spaces
			$output = substr($linedata, -1*(strlen($linedata)-$a_x)); // This works because if a_x is non-zero, we get the right bit, and if it's zero (because it always was or because (below) we have re-set it to zero because we want full lines after the part line we started with), we get the whole lot

			ser_output_conn ($output);
			// If we have another line to display (potentially)
			if (($index - $first_line) <= $displaylines)
				ser_output_conn (VNDOWN.VNLINESTART.str_pad("", $tlx, VNRIGHT));
			$lines_output++;
		}
		elseif ($redisplay_all and (($index - $first_line) <= $displaylines))
		{	debug ("Wrap-Redisplay: Redisplay-All set, but didin't display line. Skip down a line");
			ser_output_conn (VNDOWN.VNLINESTART.str_pad("", $tlx, VNRIGHT));
			$lines_output++;
		}
		else
		{
			debug ("Wrap-Redisplay: Line $index - Nothing to display");
			ser_output_conn (VNDOWN.VNLINESTART.str_pad("", $tlx, VNRIGHT));
			$lines_output++;
		}

		if ($a_y == $index)
			$a_x = $a_y = 0; // Prevent partial redisplay
		$index++; // Next line, and always redisplay from start of line
	}	

	// Clean up lines at the bottom of the display if $start_line still <= $displaylines

	if ($moved and (($index - $first_line) < $displaylines)) // Potentially something to do if the end of the lines were above the bottom of the window
		while (($index - $first_line) <= $displaylines)
		{
			//ser_output_conn(VNDOWN.VNLINESTART.str_pad("", $tlx, VNRIGHT));
			ser_output_conn(VNLINESTART.str_pad("", $tlx, VNRIGHT));
			ser_output_conn(str_pad("", $linelength).VNDOWN);
			//debug ("Wrap-Display: Cleared line $start_line in window");
			$index++;
			$lines_output++;
		}
	return ($lines_output);
}

function goto_xy_win ($tlx, $tly, $x, $y) // move to $x, $y relative to ($tlx, $tly)
{

	$position = ((($tly+$y)*40)+($tlx+$x));
	goto_xy_pos($position);

}

// Work out where we are in the master string based on which line & x position we are in the rendered set of strings
function calc_data_pos ($rendered, $line, $x)
{

	$position = 0;
	$current_line = 0;
	if (count($rendered) == 0)
		return 0; // Empty rendered set of lines
	while (($current_line < $line) and ($line < count($rendered))) // Count up the previous lines, and bounds check as we go
		$position += strlen($rendered[$current_line++]);
	$position += $x;

	return $position;
}

function debug_data_pos ($p, $s) // position and tring
{

	$start_len = 5; $end_len = 5;

	$start = $p-5; if ($start < 0) { $start_len = $p; $start = 0; }

	$end = $p+1; if (($end+5) > strlen($s)-1) { $end_len = strlen($s)-1-$p; }

	debug ("Wrap-Calc-Pos: ".substr($s, $start, $start_len)." P(".$s[$p].") ".substr($s, $p+1, $end_len));

}
?>
