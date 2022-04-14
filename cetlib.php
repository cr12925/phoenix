<?php

// THIS IS ALL WELL AND GOOD, BUT THE REAL DECODERS RESET THEIR SHIFT OFFSET TO 0 ON |A AT START OF FRAME...
// NEED TO ENCODE FRAME AT A TIME AFTER ALL - CAN'T DO THE WHOLE FILE BECAUSE WITH HEADER BLOCK WE CAN'T
// SAY PRECISELY HOW MUCH SPACE WE HAVE IN EACH FRAME

$cet_shift_offset = 0;

function cet_escape($c)
{

	global $cet_shift_offset;

	$orig = $c = ord($c);

	if ($c == 125)
	{
		//printf("Input %02x Output |\\\n", $orig);
		return "|".chr(125); // The 3/4 escape
	}

	if ($c == 0x7c) // | character
		return "|E";

	if ($c === 32)
		$c = 125; // Spaces go to 3/4

	if ($c < 32)
	{	
		$ec = 1;
		$c += 64;
	}
	else if ($c < 128)
		$ec = 0;
	else
	{
		$ec = intval(($c-64) / 32);
		$c -= (32 * $ec);
	}

	$retval = chr($c);
	if ($ec != $cet_shift_offset)
	{
		$cet_shift_offset = $ec;
		$retval = "|".$ec.$retval;
	}
	
	//printf("Input %02x Output %s\n", $orig, $retval);
	return $retval;
}

function cet_convert ($in_handle, $in_size, $offset = 0, $length = 880)
{

	//$cet_shift_offset = 0; // Looks like Commstar might not re-set this at start of each frame?
	$bytes_output = 0;
	$retval = array("converted" => "", "processed" => 0);
	$bytes_input = 0;

	fseek($in_handle, $offset);

	while (($bytes_input < $in_size) and (($length == -1) or ($bytes_output < $length)))
	{

		$in_c = fread($in_handle, 1);

		if (feof($in_handle))
			$out_c = "|F";
		else
			$out_c = cet_escape($in_c);

		if (($length != -1) and ($bytes_output + strlen($out_c)) > $length) // If we'd exceed the length available, then don't output (so we don't split across a frame)
			break;

		$bytes_output += strlen($out_c);
		if ($out_c != "|F") // We weren't at end of file
			$bytes_input++;

		$retval['converted'] .= $out_c;
	
		if ($out_c == "|F") // End of file
			break;

	}

	$retval['processed'] = $bytes_input;
	
	return ($retval);
}

function cet_decode ($infile, $outfile)
{

	global $cet_shift_offset;

	$in_handle = fopen($infile, "r");
	$out_handle = fopen($outfile, "w");
	$cet_shift_offset = 0;
	$fsize = filesize($infile);
	//print "Input file size ".$fsize." bytes\n";
	$bytes_read = 0;
	$bytes_output = 0;

	while ($bytes_read < $fsize)
	{
		$in_c = fread($in_handle, 1);
		$bytes_read++;
		$in_c = ord($in_c); 
		//printf ("Read byte %d - character %02x...", $bytes_read, $in_c);
		if (chr($in_c) == "|") // Escape
		{
			$next_char = fread($in_handle, 1);
			$bytes_read++;
			//printf ("and %02x...", ord($next_char));
			if (ord($next_char) == 0x5c) 
			{
				fwrite($out_handle, chr(0x5c));
				$bytes_output++;
			}
			else if (ord($next_char) == 69) // \E = |
			{
				fwrite($out_handle, chr(0x7c));
				$bytes_output++;
			}
			else if (($next_char >= "0") and ($next_char <= "5"))	
			{
				$cet_shift_offset = ord($next_char)-ord("0");
				if ($cet_shift_offset > 5) $cet_shift_offset = 0; // Cope with invalid shifts.
			}
			//print "\n";
			continue;
		}
	
		if ($cet_shift_offset == 1)
			$out_c = $in_c - 64;
		else
			$out_c = $in_c + ($cet_shift_offset * 32); // works for offset 0.

		if ($out_c == 125)
			$out_c = 32; // Change 3/4 back to space

		//printf ("Output %02x...", $out_c);
		fwrite ($out_handle, chr($out_c));
		$bytes_output++;
		//print "Output byte $bytes_output\n";

	}

	fclose($in_handle); fclose($out_handle);

}

// Retrieve a remote file for CET purposes
// Return FALSE (couldn't get it)
// Return TRUE (it was there, but didn't need updating)
// Return timestamp (It was there, we retrieved it, and this is its last update time)
function cet_fileupdate($url, $localfile, $lastmodifiedtime, $cetid)
{

	$headers = get_headers($url, 1);
	$date = null;
	$retval = false;

	if ($headers && (strpos($headers[0], '200') !== FALSE))
	{
		$time = strtotime($headers['Last-Modified']);
		if ($time > $lastmodifiedtime)
			if(file_put_contents($localfile, file_get_contents($url)))
				$retval = $time;
			else	$retval = false;
		else	$retval = true;
		
	}
	
	return $retval;

}

?>
