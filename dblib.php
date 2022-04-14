<?php

function debug($str)
{
	global $debug_flag;
	global $userdata;
	global $node_id;

	$s = "";

	if (isset($userdata->node_id))
		$s = sprintf("NODE %04s", $userdata->node_id);
	else if (isset($node_id))
		$s = sprintf("NODE %04s", $node_id);
	
	if (isset($userdata) && isset($userdata->user_id))
		$s .= sprintf("(%8d):", $userdata->user_id);
	else
		$s .= "(--------)";

	$s .= ":".$str;

	if ($debug_flag)
		printf("%8d: %s\n", getmypid(), $s);
}

function dbq($s, ...$args)
{

	global $dbh, $db_debug_flag;

	$msg = null;
	$success = false;

	if (count($args) == 0)
	{
		$result = @mysqli_query($dbh, $s);
		if (!$result)
		{
			$msg = @mysqli_error($dbh);	
			debug("$s -- Query error: $msg");
		}
		else
		{
			if ($db_debug_flag) debug("$s -- Successful query - ".@mysqli_num_rows($result)." rows returned");
			$success = true;
		}
	}
	else
	{
		if ($db_debug_flag)
		{	$ac = count($args);
			debug("Preparing query $s with params ... $ac - ".implode(' - ', $args));
		}

		$stmt = @mysqli_prepare($dbh, $s);

		if (!$stmt)
		{
			$result = false;
			$success = false;
			debug ("Prepare failure - ".@mysqli_error($dbh));
		}
		else
		{
			if (!@mysqli_stmt_bind_param($stmt, ...$args))
			{
				$result = false; $success = false;
				debug("SQL Param Bind failure - ".@mysqli_stmt_error($stmt));
			}
			else	if (@mysqli_stmt_execute($stmt))
				{
					$result = @mysqli_stmt_get_result($stmt);
					// NB mysqli_stmt_get_result returns false on a query which returns no results
					$success = true;
				}
				else
					debug("Stmt Execute problem - ".mysqli_stmt_error($stmt));
		}

		if (!$success)
		{
			$msg = @mysqli_stmt_error($stmt);	
			$params = array();
			array_push ($params, ...$args);
			debug("$s  -- Query error: $msg");
		}
		else
			if ($db_debug_flag) debug("$s -- Successful query - ".@mysqli_num_rows($result)." rows returned");
	}

	$ret['success'] = $success;
	if (!isset($result)) $result = false;
	$ret['result'] = $result;
	$ret['msg'] = $msg;
	$ret['numrows'] = @mysqli_num_rows($result);
	$ret['insert_id'] = @mysqli_insert_id($dbh);
	if (count($args) == 0)
		$ret['affected'] = @mysqli_affected_rows($dbh);
	else
		$ret['affected'] = @mysqli_stmt_affected_rows($stmt);

	return($ret);

}

function dbq_starttransaction()
{
	global $dbh;
	return(@mysqli_begin_transaction($dbh, MYSQLI_TRANS_START_READ_WRITE));
}

function dbq_rollback()
{
	global $dbh;
	return(@mysqli_rollback($dbh));
}
	
function dbq_commit()
{
	global $dbh;
	return(@mysqli_commit($dbh));
}
