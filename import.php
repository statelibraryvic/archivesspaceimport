<?php

// Authenticate user
$params = array(
   "password" => "***REMOVED***"
);
$authenticationString = json_decode(httpPost("***REMOVED***/users/***REMOVED***/login",$params));
echo $authenticationString->session . "\n";

$data = array("jsonmodel_type" => "archival_object",
              "title"          => "zztest6",
              "level"          => "series",
              "extents"        => [[
                                    'portion'           => "whole",
                                    'extent_type'       => "metres",
                                    'container_summary' => "2 boxes",
                                    'number'            => "1111",
                                    'jsonmodel_type'    => "extent"
                                  ]],
              "dates"          => [[
                                    "date_type"         => "inclusive",
                                    "label"             => "creation",
                                    "jsonmodel_type"    => "date",
                                    "expression"        => "1921/1922"
                                  ]],
              "parent"         => [
                                    "ref"    => "/repositories/5/archival_objects/6769"
                                  ],
              "resource"       => [
                                    "ref"    => "/repositories/5/resources/29"
                                  ]
              );
// DEBUG
//var_dump($data) . "\n";
//$data_string = json_encode($data);
//print $data_string . "\n";
//exit;

$ch = curl_init('***REMOVED***/repositories/5/archival_objects');
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Content-Type: application/json',
    'Content-Length: ' . strlen($data_string),
    'X-ArchivesSpace-Session: ' . $authenticationString->session)
);

$result = curl_exec($ch);

echo $result;

function httpPost($url,$params)  {
  $postData = '';
   //create name value pairs seperated by &
   foreach($params as $k => $v)
   {
      $postData .= $k . '='.$v.'&';
   }
   $postData = rtrim($postData, '&');

    $ch = curl_init();

    curl_setopt($ch,CURLOPT_URL,$url);
    curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
    curl_setopt($ch,CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_POST, count($postData));
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

    $output=curl_exec($ch);

    curl_close($ch);
    return $output;
}

?>
