<?php

$config['nr_token'] = 'f8b71f2c-17bc-4d9e-ac2e-68009e726625';
class User {
	var $nr_client;
}

$userdata = new User;

include_once "nationalrail.php";

nr_init();

//print "argc: $argc ... ".implode(' - ', $argv);

if ($argc < 2)
{
	print "Must specify at least one CRS code and A or D.\n\n";
	exit(1);
}

$filterType = $filterCrs = null;

if (preg_match('/^[A-Z]{3}$/i', $argv[1]))
	$station = strtoupper($argv[1]);
else
{
	print "Station CRS code wrong.\n\n";
	exit(1);
}

if (preg_match('/^[AD]$/i', $argv[2]))
	$dir = strtoupper($argv[2]);
else
{
	print "Must specify A or D for arrive or depart.\n\n";
	exit(1);
}

if ($argc == 5)
{
	if (preg_match('/^(to|from)$/i', $argv[3]) && preg_match('/^[A-Z]{3}$/i', $argv[4]))
	{
		$filterType = strtolower($argv[3]);
		$filterCrs = strtoupper($argv[4]);
	}
	else	
	{
		print "Error with filter specification.\n\n";
		exit(1);
	}
}

$board = nr_get_board($station, $dir, 15, $filterType, $filterCrs);

var_dump ($board);

if ($board && isset($board->GetStationBoardResult->trainServices->service))
{
	$station_name = $board->GetStationBoardResult->locationName;
	$platforms = $board->GetStationBoardResult->platformAvailable;
	$trains = $board->GetStationBoardResult->trainServices->service;

	printf ("%s board for %s%s\n\n", "Departure", $station_name, ($filterType ? " ".$filterType." ".$filterCrs : ""));

	foreach ($trains as $t)
	{
		printf("%2s %- 5s %- 20s %- 20s % 3s %- 9s\n", 
			$t->operatorCode,
			($dir == 'A' ? $t->sta : $t->std),
			substr($t->origin->location->locationName, 0, 15),
			substr($t->destination->location->locationName, 0, 15),
			(($platforms && isset($t->platform)) ? $t->platform : "-"),
			($dir == 'A' ? $t->sta : $t->etd)
		);
	
		if (isset($t->isCancelled) && $t->isCancelled)
			printf("         %- 41s\n", $t->cancelReason);

		if (isset($t->delayReason))
			printf("         %- 41s\n", $t->delayReason);
	
	}
}
else
	print "Get board failed.\n\n";

?>
