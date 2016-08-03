<?php

$dbh      = opendb();

if (!isset($argv[1])) { print "Missing import file....exiting\n"; exit; }
$filename = $argv[1];
$csvArray = ImportCSV2Array($filename);

if (!isset($argv[2])) { print "Missing function call. Possible calls are [agent_object,archival_object]....exiting\n"; exit; }
$functionCall = $argv[2];

// Authenticate user
$params = array(
   "password" => "***REMOVED***"
);
$authenticationString = json_decode(httpPost("***REMOVED***/users/***REMOVED***/login",$params));
echo $authenticationString->session . "\n";

if ($functionCall == 'agent_object') {
  print "Agent\n";
  agent_object($csvArray,$dbh,$authenticationString);
} else if ($functionCall == 'archival_object') {
  print "Archival Object\n";
  archival_object($csvArray,$dbh,$authenticationString);
} else {
  print "Invalid function call. Possible calls are [agent_object,archival_object]....exiting\n"; exit;
}

function archival_object($csvArray,$dbh,$authenticationString) {

  foreach ($csvArray as $row) {   // Loop through CSV file
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
    $agent_ref = linkAgent($row['agent_primary_name'],$dbh,$authenticationString);
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

}

function agent_object($csvArray,$dbh,$authenticationString) {

  foreach ($csvArray as $row) {   // Loop through CSV file

    if ($row['name_type'] == 'Person') {
      $apiCall       = '/agents/people';
      $containerType = 'agent_person';
      $nameType      = 'name_person';
    } else {
      $apiCall       = '/agents/corporate_entities';
      $containerType = 'agent_corporate_entity';
      $nameType      = 'name_corporate_entity';
    }

    print 'Loading ' . $row['agent_primary_name'] . "\n";

    $agent = linkAgent($row['agent_primary_name'],$dbh,$authenticationString,$row['name_type']);

    // API request
    $data = array("jsonmodel_type" => "$containerType",
                  "agent_type"     => "$containerType",
                  "names"          => [[
                                        "jsonmodel_type" => "$nameType",
                                        "sort_name"      => $row['agent_primary_name'],
                                        "primary_name"   => $row['agent_primary_name'],
                                        "source"         => "ingest"
                                      ]],
                  "lock_version"   => $agent['lock_version']
    );

    // Add existing related agents back into the data structure
    if (isset($agent['related_agents'])) { $data['related_agents'] = $agent['related_agents']; }

    // Only add things applicable to corporate entities
    if ($row['name_type'] == 'Corporate Entity') {
      $data['names'][0]['authority_id'] = $row['authority_id'];

      $relatedAgent = linkAgent($row['related_agent_primary_name'],$dbh,$authenticationString,$row['name_type']);

      $data['related_agents'][] = array(
        "jsonmodel_type" => $row['related_agent_type'],
        "relator"        => $row['related_agent_relator'],
        "ref"            => $relatedAgent['ref'],
        "description"    => $row['related_agent_description']
      );

      // Add dates to related agents
      if ((!empty($row['date_start'])) && (!empty($row['date_end']))) {
        $data['related_agents'][count($data['related_agents'])-1]['dates'] = array(
                                              "date_type"         => "range",
                                              "label"             => "agent_relation",
                                              "jsonmodel_type"    => "date",
                                              "expression"        => $row['date_start'] . ' - ' . $row['date_end']
                                            );
      }

    } else {
      $data['names'][0]['name_order'] = 'inverted';
    }

    // Add notes
    if ((!empty($row['note1'])) || (!empty($row['note2'])) || (!empty($row['note3']))) {

      $data['notes'] = [["jsonmodel_type" => "note_bioghist"]];

      $notesArray = array('note1','note2','note3');
      foreach ($notesArray as $noteRow) {
        if (!empty($row[$noteRow])) {
          $data['notes'][0]['subnotes'][] = array(
                                             "jsonmodel_type" => "note_text",
                                             "content"        => $row[$noteRow]
                                          );
        }
      }
    }

    $data_string = json_encode($data);

    $ch = curl_init('***REMOVED***' . $agent['ref']);
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

    // Clear variables for next loop iteration
    unset($data,$agent,$relatedAgent);

  }

}

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

function linkAgent($agent_primary_name,$dbh,$authenticationString,$name_type) {

  if ($name_type == 'Person') {
    $apiCall       = '/agents/people';
    $containerType = 'agent_person';
    $nameType      = 'name_person';
    $query         = 'SELECT n.agent_person_id, a.lock_version
                      FROM   name_person n, agent_person a
                      WHERE  n.primary_name = ?
                         AND n.agent_person_id = a.id';
  } else {
    $apiCall       = '/agents/corporate_entities';
    $containerType = 'agent_corporate_entity';
    $nameType      = 'name_corporate_entity';
    $query         = 'SELECT n.agent_corporate_entity_id, a.lock_version
                      FROM   name_corporate_entity n, agent_corporate_entity a
                      WHERE  n.primary_name = ?
                         AND n.agent_corporate_entity_id = a.id';
  }

  // Make sure that the agent has all required fields
  if (isset($agent_primary_name)) {

    $stmt = $dbh->prepare($query);
    $stmt->bind_param('s', $agent_primary_name);
    $stmt->execute();

    // Hopefully there is only one result returned, if not we do not process any more instructions within the loop
    $stmt->store_result();
    $row_cnt = $stmt->num_rows;

    if ($row_cnt == 1) {
      $stmt->bind_result($agent['id'],$agent['lock_version']);
      $stmt->fetch();
      $agent['ref'] = $apiCall . '/' . $agent['id'];

      // Grab the existing related agents so we don't lose it when we update the record
      $ch = curl_init('***REMOVED***' . $agent['ref']);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_HTTPHEADER, array(
          'Content-Type: application/json',
          'X-ArchivesSpace-Session: ' . $authenticationString->session)
      );

      $result = curl_exec($ch);

      $result = json_decode($result);
      $agent['related_agents'] = $result->related_agents;

      return $agent;

    } elseif ($row_cnt == 0) {

      // API request
      $data = array("jsonmodel_type" => $containerType,
                    "agent_type"     => $containerType,
                    "names"          => [[
                                          "jsonmodel_type" => $nameType,
                                          "sort_name"      => $agent_primary_name,
                                          "primary_name"   => $agent_primary_name,
                                          "source"         => "ingest"
                                        ]]
      );

      // Only add things applicable to corporate entities
      if ($name_type == 'Person') {
        $data['names'][0]['name_order'] = 'inverted';
      }

      $data_string = json_encode($data);

      $ch = curl_init('***REMOVED***' . $apiCall);
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
      $agent['ref'] = $result->uri;
      $agent['lock_version'] = $result->lock_version;
      $agent['id'] = $result->id;
      return $agent;

    } elseif ($row_cnt > 1) {
      echo "More than 1 agent matches...skipping row\n";
    }

  }
}

?>
