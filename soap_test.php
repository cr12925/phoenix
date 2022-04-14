<?php

$options = array(
	'location' => 'http://ts.barrister.legal/ip_soap.php',
	'uri'	=> 'http://ts.barrister.legal/ip_soap.php');

$client = new SoapClient(NULL, $options);
var_dump ($client->DYNAMIC('testkey', 10000, 300, 'a'));

?>
