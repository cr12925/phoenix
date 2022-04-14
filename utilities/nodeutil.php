<?php

include_once 'conf.php';
include_once 'ip_lib.php';

$options = getopt("hn:l", array("node:", "port:", "nodename:", "baud:", "host:", "startpage:", "homepage:", "presname:", "ip:", "help", "list", "delete"));

if (isset($options['h']) or isset($options['help']) or $argc == 1)
{

	fprintf(STDERR, "\n%s: usage

			Parameters marked * are mandatory unless -n / --node
			is specified (i.e. if a new node is being created),
			or the -l | --list option is used.

	-n | --node	node_id (integer, index to the node table)
			If specified, updates an existing node. If omitted,
			a new node will be created

*	--port		Either TCP port number to listen on on the host
			specified, or a Unix device (e.g. /dev/ttyUSB0)
			upon which a getty process is run by the system.
			
			For Unix devices, a script utilities/run.getty
			is provided which sets the CS7 parameter with
			stty and starts the system.

	--presname	String to display (up to 5 characters) instead of
			the port number or device. Allows 'concealment' of
			device names like /dev/ttyUSB0 by displaying them
			as, for example, 'Ln001'.

*	--nodename	The node name displayed to users as being the name
			of the system they have connected to. 
			E.g. 'Enterprise'

*	--baud		Baud rate to simulate. Options are
			300, 1200, 1200/75, 2400, 4800, 9600
			(Except for 1200/75, the rates are simultaed for
			both input and output.)

*	--host		The hostname this node will run on. This needs to
			match the string returned by 'gethostbyname' on the
			system the code runs on. The hostname can be
			a regular expression compatible with MySQL 'RLIKE',
			so as to enable a node to run on multiuple hosts,
			e.g. 'cluster?'

	--startpage	A frame pagenumber (without subframe ID) for the
			first page to be displayed on connection. This is
			typically the login frame. This parameter is optional.
			If not set, the node will use the system configured
			start page from the 'config' table. If it IS set
			then the page must be in an area configured as 
			'Public' in the area table, and should have the
			'nologin' flag set on the frame so that it cannot
			be accessed by logged in users. 

			The page should also have a longer page number than
			the minimum length of the IP's base page, otherwise
			there is a risk the system will not treat it as 
			accessible on the node if the --ip setting is
			configured. E.g. if the IP's space is 3XX, the login
			page needs to be at least 4 characters long and in
			an area configured as Public with a page regex at least
			that length.

			The purpose of the setting is to enable an IP
			to have their own bespoke login page, displayed 
			to all users of the node. That IP can then publicise
			only that node. Often used in conjunction with
			--homepage and --ip - see below.

	--homepage	A frame pagenumber (without subframe ID) for the home
			page to be used for connections on this node.
			Like --startpage, this is designed to allow an IP
			to have a dedicated node which presents the home page
			out of their own space rather than the system default
			as configured in the config table.

			The system will automatically map *HOME# and *0#
			to this page.

	--ip		IP number (i.e. ip_id from the information_provider
			table -- displayed with --list). This limits the node
			to displaying ONLY frames within the specified IP's
			framespace. This means the login, home, messaging and
			logoff frames must all be within the IP's space.
			
	-l | --list	List Information Providers. If specified, will 
			disable all other options except -n | --node, which
			(if specified) provides more detail for the 
			specified node.
	
	--delete	Delete the node specified with -n | --node

", $argv[0]);


	exit ();
}

// Input validation

if (isset($options['n']) or isset($options['node']))
	if (!isset($options['n']))
		$node_id = $options['node'];
	else	$node_id = $options['n'];

if (isset($node_id) and !preg_match('/^\d+$/', $node_id))
{
	fprintf(STDERR, "%s: Node Id must be numeric\n", $argv[0]);
	exit();
}

if (isset($options['presname']) and !preg_match('/^[A-Z0-9]{1,5}|null$/i', $options['presname']))
{
	fprintf(STDERR, "%s: Presentation Port name must be 1-5 characters and consist of A-Z, a-z or 0-9 only.\n", $argv[0]);
	exit();
}

if (isset($options['nodename']) and !preg_match('/^.{1,15}$/i', $options['nodename']))
{
	fprintf(STDERR, "%s: Node name must be 1-15 characters.\n", $argv[0]);
	exit();
}

if (isset($options['host']) and !preg_match('/^[\*\?A-Z0-9\.\-]{1,30}$/i', $options['host']))
{
	fprintf(STDERR, "%s: Host name must be 1-30 characters and may include '?' or '*' wildcards.\n", $argv[0]);
	exit();
}

if (isset($options['baud']) and !preg_match('/^300|1200|1200\/75|2400|4800|9600$/i', $options['baud']))
{
	fprintf(STDERR, "%s: Baud rate must be one of 300, 1200, 1200/75, 2400, 4800 or 9600.\n", $argv[0]);
	exit();
}

if (isset($options['startpage']) and !preg_match('/^[1-9][0-9]{0,9}|null$/i', $options['startpage']))
{
	fprintf(STDERR, "%s: Start page must be between 1 and 9999999999 without a subframe ID.\n", $argv[0]);
	exit();
}

if (isset($options['homepage']) and !preg_match('/^([1-9][0-9]{0,9})|(null)$/i', $options['homepage']))
{
	fprintf(STDERR, "%s: Home page must be between 1 and 9999999999 without a subframe ID.\n", $argv[0]);
	exit();
}

if (isset($options['ip']) and !preg_match('/^\d+|null$/i', $options['ip']))
{
	fprintf(STDERR, "%s: IP number must be an integer or null.\n", $argv[0]);
	exit();
}

if (isset($options['port']) and !preg_match('/^([1-9][0-9]{0,4}|\/dev\/.+)$/i', $options['port']))
{
	fprintf(STDERR, "%s: Port name must either be up to 5 digits 0-9 (not beginning with 0, or /dev/... device name \n", $argv[0]);
	exit();
}

if (isset($options['port']) and !preg_match('/^\//', $options['port'])) // Port number is not a device
{
	if ($options['port'] < 50 or $options['port'] >= 65535)
	{
		fprintf(STDERR, "%s: Port number must be >= 50 and < 65535.\n", $argv[0]);
		exit();
	}
}
if (isset($options['delete']))
{
	if (!isset($node_id))
	{
		fprintf(STDERR, "%s: Must specified node ID with -n | --node in order to delete a node.\n", $argv[0]);
		exit();
	}
	
	$r = dbq("delete from node where node_id = ?", "i", $node_id);

	if ($r['success'])
		printf ("Node %d deleted.\n", $node_id);
	else	printf ("Delete failed. Unknown node id?\n");
	exit();
}

// Enforce mandatory parameters if -n | --node not specified
if (!isset($node_id) and !isset($options['l']) and !isset($options['list']) and
	(!isset($options['port']) or
	 !isset($options['baud']) or
	 !isset($options['nodename']) or
	 !isset($options['host'])
	)	)
{
	fprintf(STDERR, "%s: Must specify port, baud, nodename and host unless node ID is specified with -n | --node\n", $argv[0]);
	exit();
}

if (isset($options['l']) or isset($options['list']))
{

	if (isset($node_id))
	{
		$r = dbq("select * from node where node_id = ?", "i", $node_id);
		$d = @mysqli_fetch_assoc($r['result']);
		@mysqli_free_result($r['result']);
		printf(
"Node ID                : %d
Node name              : %s
Host                   : %s
Port                   : %s
Presentation port name : %s
Baud                   : %s
Startpage              : %s
Homepage               : %s
IP ID                  : %s
",
		$d['node_id'],
		$d['node_name'],
		$d['node_host'],
		$d['node_port'],
		($d['node_portpres'] != null ? $d['node_portpres'] : '(Unset)'),
		$d['node_baud'],
		($d['node_startpage'] != null ? $d['node_startpage'] : '(Unset)'),
		($d['node_homepage'] != null ? $d['node_homepage'] : '(Unset)'),
		($d['node_ip_id'] != null ? $d['node_ip_id'] : '(Unset)')
);
	}
	else
	{
		printf("%8s %-20s %-15s %-10s %-15s\n", "Node ID", "Node Name", "Host name", "Baud rate", "Port");
		$r = dbq("select * from node order by node_id asc");
		while ($d = @mysqli_fetch_assoc($r['result']))
			printf("%8d %-20s %-15s %-10s %-15s\n", $d['node_id'], $d['node_name'], $d['node_host'], $d['node_baud'], $d['node_port']);
		@mysqli_free_result($r['result']);
	}

	exit();
}

$field_types = "";
$field_names = array();
$field_data = array();
$field_queries = array();

$field_list = array( 	"port" => "node_port",
			"nodename" => "node_name",
			"baud" => "node_baud",
			"host" => "node_host",
			"startpage" => "node_startpage",
			"homepage" => "node_homepage",
			"ip" => "node_ip_id" );
			
foreach ($field_list as $param => $db_field)
{
	if (isset($options[$param]))
	{
		if ($param == "startpage" or $param == "homepage" or $param == "ip")
			$field_types .= "i";
		else	$field_types .= "s";
		$field_names[] = $db_field;
		$field_data[] = ($options[$param] == "null" ? null : $options[$param]);
		$field_queries[] = "?";
	}
}
			
if (!isset($node_id))
{
	$query = "insert into node(".implode(', ', $field_names).") values (".implode(', ', $field_queries).")";
}
else
{
	$query = "update node set ";
	$query_part = array();
	foreach ($field_names as $k)
		$query_part[] = "$k = ?";
	$query .= implode(', ', $query_part)." where node_id = ?";
	$field_types .= "i"; 
	$field_names[] = 'node_id';
	$field_data[] = $node_id;
	$field_queries[] = "?";
}	
	

//printf ($query." -- ".$field_types." -- ".implode(', ', $field_data)."\n");

//exit();

$r = dbq($query, $field_types, ...$field_data);
if ($r['success'])
{
	printf ("Node ".(isset($node_id) ? "updated" : "inserted").".\n");
	if (!isset($node_id))
		printf ("New node ID %d\n", $r['insert_id']);
}
?>
