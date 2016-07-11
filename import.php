<?php

$dbh      = opendb();
$filename = "sample.csv";
$csvArray = ImportCSV2Array($filename);

// Authenticate user
$params = array(
   "password" => "***REMOVED***"
);
$authenticationString = json_decode(httpPost("***REMOVED***/users/***REMOVED***/login",$params));
echo $authenticationString->session . "\n";

// Loop through CSV file
foreach ($csvArray as $row) {
  print 'Loading ' . $row['component_id'] . "\n";

  $data = array("jsonmodel_type" => "archival_object",
                "title"          => $row['title'],
                "level"          => $row['level'],
                "extents"        => [[
                                      'portion'           => $row['extents_portion'],
                                      'extent_type'       => $row['extents_type'],
                                      'container_summary' => $row['extents_container_summary'],
                                      'number'            => $row['extents_number'],
                                      'jsonmodel_type'    => "extent"
                                    ]],
                "dates"          => [[
                                      "date_type"         => $row['dates_type'],
                                      "label"             => $row['dates_label'],
                                      "jsonmodel_type"    => "date",
                                      "expression"        => $row['dates_expression']
                                    ]],
                "resource"       => [
                                      "ref"    => "/repositories/5/resources/" . $row['resource']
                                    ],
                "component_id"   => $row['component_id']
  );

  // Add linked agent if provided
  $agent_ref = linkAgent($row,$dbh,$authenticationString);
  if (isset($agent_ref)) {
    $data['linked_agents'] = [[
                              "role"  => 'source',
                               "ref"  => $agent_ref
                             ]];
  }

  // Find archival object by component_id
  if ($row['parent']) {
    $query = "SELECT id FROM archival_object WHERE component_id = ? AND root_record_id = ?";
    $stmt = $dbh->prepare($query);

    $stmt->bind_param('si', $row['parent'],$row['resource']);
    $stmt->execute();

    // Hopefully there is only one result returned, if not we do not process any more instructions within the loop
    $stmt->store_result();
    $row_cnt = $stmt->num_rows;

    if ($row_cnt == 1) {
      $stmt->bind_result($parent_id);
      $stmt->fetch();
      $data['parent'] = [ "ref" => "/repositories/5/archival_objects/" . $parent_id ];
    } elseif ($row_cnt == 0) {
      echo "The parent id you specified does not exist...skipping row\n"; continue;
    } elseif ($row_cnt > 1) {
      echo "More than 1 parent matches...skipping row\n"; continue;
    }

  } // End If parent

  $data_string = json_encode($data);

  // DEBUG
  //var_dump($data) . "\n";
  // print $data_string . "\n";


  // API request
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

} // End For Loop


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

function ImportCSV2Array($filename) {
  $row = 0;
  $col = 0;

  $handle = @fopen($filename, "r");
  if ($handle) {
    while (($row = fgetcsv($handle, 4096)) !== false) {
      if (empty($fields)) {
        $fields = $row;
        continue;
      }

      foreach ($row as $k=>$value) {
        $results[$col][$fields[$k]] = $value;
      }
      $col++;
      unset($row);
    }
    if (!feof($handle)) {
      echo "Error: unexpected fgets() failn";
    }
    fclose($handle);
  }

  return $results;
}

function openDB() {

  $dbh = new mysqli("***REMOVED***","***REMOVED***","***REMOVED***","***REMOVED***");

  /* check connection */
  if ($dbh->connect_errno) {
    printf("Connect failed: %s\n", $dbh->connect_error);
    exit();
  }

  return $dbh;

}

function linkAgent($row,$dbh,$authenticationString) {

  // Make sure that the agent has all required fields
  if ($row['agent_type'] && $row['agent_primary_name']) {

    $query = "SELECT agent_corporate_entity_id FROM name_corporate_entity WHERE primary_name = ?";
    $stmt = $dbh->prepare($query);

    $stmt->bind_param('s', $row['agent_primary_name']);
    $stmt->execute();

    // Hopefully there is only one result returned, if not we do not process any more instructions within the loop
    $stmt->store_result();
    $row_cnt = $stmt->num_rows;

    if ($row_cnt == 1) {
      $stmt->bind_result($agent_id);
      $stmt->fetch();
      $agent = "/agents/corporate_entities/" . $agent_id;

      return $agent;

    } elseif ($row_cnt == 0) {

      // API request
      $data = array("jsonmodel_type" => "agent_corporate_entity",
                    "agent_type"     => "agent_corporate_entity",
                    "names"          => [[
                                          "jsonmodel_type" => "name_corporate_entity",
                                          "sort_name"      => $row['agent_primary_name'],
                                          "primary_name"   => $row['agent_primary_name'],
                                          "source"          => "ingest"
                                        ]]
      );
      $data_string = json_encode($data);

      $ch = curl_init('***REMOVED***/agents/corporate_entities');
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

      $result = json_decode($result);
      return $result->uri;

    } elseif ($row_cnt > 1) {
      echo "More than 1 agent matches...skipping row\n";
    }

  }
}

?>
