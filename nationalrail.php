<?php

function nr_init()
{
	global $userdata;
	$userdata->nr_client = new SoapClient("https://lite.realtime.nationalrail.co.uk/OpenLDBWS/wsdl.aspx?ver=2017-10-01");
}

function nr_get_board($stn, $aord, $rows = 15, $filterType = null, $filterCrs = null)
{

	global $config, $userdata;

	$headerParams = array('ns2:TokenValue' => $config['nr_token']);
	$soapStruct = new SoapVar ($headerParams, SOAP_ENC_OBJECT);
	$header = new SoapHeader('http://thalesgroup.com/RTTI/2010-11-01/ldb/commontypes', 'AccessToken', $soapStruct, false);
	$userdata->nr_client->__setSoapHeaders($header);

	$args = array('numRows' => $rows, 'crs' => $stn);
	
	$args['timeWindow'] = 120;

	try {
		if ($filterType !== null)
		{
			$args['filterType'] = $filterType;
			$args['filterCrs'] = $filterCrs;
		}

		if (strtolower($aord) == 'a')
			return $userdata->nr_client->GetArrBoardWithDetails($args);
		else
			return $userdata->nr_client->GetDepBoardWithDetails($args);
	}
	catch (SoapFault $e)
	{
		log_event("SoapFault", 1, "SoapFaul in call to National Rail");
		return null;
	}
}

?>
