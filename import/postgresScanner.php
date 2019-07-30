<?php
  // get the issue info string for this row
  $monthStr = [1 => "January", 2 => "February", 3 => "March", 4 => "April", 5 => "May", 6 => "June", 7 => "July", 8 => "August", 9 => "September", 10 => "October", 11 => "November", 12 => "December", 21 => "Spring", 22 => "Summer", 23 => "Fall", 24 => "Winter"];
  function getIssueStr($thisRow) {
    global $monthStr;

    // generate date string
    $years = explode("-", $thisRow["bci"]);
    $months = explode("-", $thisRow["bcj"]);
    $days = explode("-", $thisRow["bck"]);

    // this runs twice if any of those strings includes a '-' (hard split)
    $index = 0;
    $issueStr = "";
    while( $index < count($years) || $index < count($months) || $index < count($days) ) {
      // process years
      // look for a soft split ('/')
      $yearSplit = explode("/", $years[($index < count($years)) ? $index : 0]);
      $slashSeparator = count($yearSplit) > 1;
      $issueStr .= ($index ? " - " : "") . "m1md1d" . ((count($yearSplit) > 1) ? ((int)$yearSplit[0] . "-") : "") . "m2md2d" . (int)$yearSplit[(count($yearSplit) > 1) ? 1 : 0];

      // process months (if present)
      if( $months[($index < count($months)) ? $index : 0] ) {      
        // look for a soft split ('/')
        $monthSplit = explode("/", $months[($index < count($months)) ? $index : 0]);
        $issueStr = str_replace("m1m", $monthStr[(int)$monthSplit[0]], $issueStr);

        // soft split is there
        if( (count($monthSplit) > 1) ) {
          $issueStr = str_replace("m2m", (($slashSeparator ? "" : "-") . $monthStr[(int)$monthSplit[1]]), $issueStr);
          $slashSeparator = true;
        // years had a soft split
        } else if( $slashSeparator ) {
          $issueStr = str_replace("m2m", ("-" . $monthStr[(int)$monthSplit[0]]), $issueStr);
        // no soft split in either years or months
        } else {
          $issueStr = str_replace("m2m", "", $issueStr);
        }
      // if it's not there, blank out the placeholders
      } else {
        $issueStr = str_replace("m1m", "", $issueStr);
        $issueStr = str_replace("m2m", "", $issueStr);
      }

      // process days (if present)
      if( $days[($index < count($days)) ? $index : 0] ) {
        // look for a soft split ('/')
        $daySplit = explode("/", $days[($index < count($days)) ? $index : 0]);
        $issueStr = str_replace("d1d", (" " . (int)$daySplit[0] . (((count($yearSplit) == 1) && ((count($daySplit) > 1) || $slashSeparator)) ? "" : ", ")), $issueStr);

        // soft split is there
        if( (count($daySplit) > 1) ) {
          $issueStr = str_replace("d2d", (($slashSeparator ? " " : "-") . (int)$daySplit[1] . ", "), $issueStr);
        // years and/or months had a soft split
        } else if( $slashSeparator ) {
          $issueStr = str_replace("d2d", (" " . (int)$daySplit[0] . ", "), $issueStr);
        // no soft split in either years, months. or days
        } else {
          $issueStr = str_replace("d2d", " ", $issueStr);
        }
      // if it's not there, replace the placeholders with a space (unless there was no soft split in years and no days/months given) 
      } else {
        $issueStr = str_replace("d1d", ((($days[0] || $months[0]) && (count($yearSplit) > 1)) ? " " : ""), $issueStr);
        $issueStr = str_replace("d2d", (($days[0] || $months[0]) ? " " : ""), $issueStr);
      }

      // make a second pass if there was a hard split
      $index++;
    }

    // add in volume information
    $issueStr .= ($thisRow["bea"] ? (" " . ((strpos($thisRow["clea"], "(") === false) ? $thisRow["clea"] : "") . $thisRow["bea"]) : "") . ($thisRow["beb"] ? (" " . ((strpos($thisRow["cleb"], "(") === false) ? $thisRow["cleb"] : "") . $thisRow["beb"]) : "");
    return $issueStr;
  }

  // find connection details
  $configFile = fopen("/usr/local/vufind2/local/config/vufind/config.ini", "r");
  $section = null;
  $postgresProperties = [];
  $mysqlProperties = [];
  while( $line = fgets($configFile) ) {
    if( substr($line, 0, 1) == "[" ) {
      $section = substr($line, 1, strpos($line, "]") - 1);
    } else if( $section == "ScriptPostgres" ) {
      $chunks = explode("=", $line, 2);
      if( count($chunks) == 2 ) {
        $postgresProperties[trim($chunks[0])] = trim($chunks[1]);
      }
    } else if( $section == "ScriptMysql" ) {
      $chunks = explode("=", $line, 2);
      if( count($chunks) == 2 ) {
        $mysqlProperties[trim($chunks[0])] = trim($chunks[1]);
      }
    }
  }
  fclose( $configFile );

  // get postgres connection
  $db = pg_connect("host=" . $postgresProperties["host"] . " port=" . $postgresProperties["port"] . " dbname=" . $postgresProperties["dbname"] . " user=" . $postgresProperties["user"] . " password=" . $postgresProperties["password"]);

  // get sql connection
  $sqlDB = mysqli_connect($mysqlProperties["host"], $mysqlProperties["user"], $mysqlProperties["password"], $mysqlProperties["postgresScannerDbname"]);

  // query for order records
  $orderRecords = [];
  $results = pg_query("select bv.record_num as bnum, " . 
                             "copies, " .
                             "location_code, " . 
                             "location_name.name as name " . 
                      "from sierra_view.order_view as ov " . 
                           "join sierra_view.bib_record_order_record_link as brorl on (brorl.order_record_id=ov.id) " . 
                           "join sierra_view.bib_view as bv on (brorl.bib_record_id=bv.id) " . 
                           "join sierra_view.order_record_cmf as orc on (orc.order_record_id=ov.id) " . 
                           "join sierra_view.location on (location_code=location.code) " . 
                           "join sierra_view.location_name on (location_id=location.id) " . 
                      "where ov.received_date_gmt is null " . 
                            "and location_code != 'multi' " . 
                            "and ov.ocode4 != 'n' " .
                      "order by bv.record_num, location_code");
  while($thisRow = pg_fetch_array($results)) {
      // add the new row if we haven't seen this bib before
      if( !isset($orderRecords[$thisRow["bnum"]]) ) {
          $orderRecords[$thisRow["bnum"]] = [];
      }
      // add the new location code if we haven't seen it before
      if( !isset($orderRecords[$thisRow["bnum"]][$thisRow["location_code"]]) ) {
          $orderRecords[$thisRow["bnum"]][$thisRow["location_code"]] = ["copies" => 0, "location" => $thisRow["name"]];
      }
      $orderRecords[$thisRow["bnum"]][$thisRow["location_code"]]["copies"] += $thisRow["copies"];
  }  

  // query for identity and lib has records
  $checkinRecords = [];
  $results = pg_query("select location_name.name, " . 
                             "varfield_type_code, " .
                             "field_content, " .
                             "location_code, " .
                             "occ_num, " . 
                             "varfield_view.record_num, " . 
                             "bib_view.record_num as bnum " . 
                      "from sierra_view.bib_view " . 
                           "join sierra_view.bib_record_holding_record_link on (bib_record_holding_record_link.bib_record_id=bib_view.id) " . 
                           "join sierra_view.holding_record on (bib_record_holding_record_link.holding_record_id=holding_record.id) " . 
                           "join sierra_view.varfield_view on (varfield_view.record_id=holding_record_id) " . 
                           "join sierra_view.holding_record_location using (holding_record_id) " . 
                           "join sierra_view.location on (location_code=location.code) " . 
                           "join sierra_view.location_name on (location_id=location.id) " . 
                           "left join sierra_view.holding_record_card using (holding_record_id) " . 
                           "left join sierra_view.holding_record_cardlink on (holding_record_card.id=holding_record_cardlink.holding_record_card_id) " . 
                           "left join sierra_view.holding_record_box on (holding_record_box.holding_record_cardlink_id=holding_record_cardlink.id) " . 
                      "where box_count is null " . 
                            "and scode4 != 'n' " . 
                            "and varfield_type_code in ('h','i') " .
                      "order by bib_view.record_num, varfield_view.record_num, varfield_type_code, occ_num");
  while($thisRow = pg_fetch_array($results)) {
      // add the new row if we haven't seen this bib before
      if( !isset($checkinRecords[$thisRow["bnum"]]) ) {
          $checkinRecords[$thisRow["bnum"]] = [];
      }
      // add the new location code if we haven't seen it before
      if( !isset($checkinRecords[$thisRow["bnum"]][$thisRow["location_code"]]) ) {
          $results2 = mysqli_fetch_assoc( mysqli_query($sqlDB, "select location.code as bCode from shelving_location join location using (locationId) where shelving_location.code='" . $thisRow["location_code"] . "'") );
          $checkinRecords[$thisRow["bnum"]][$thisRow["location_code"]] = [
              "type" => "issueSummary",
              "location" => $thisRow["name"],
              "code" => $thisRow["location_code"],
              "branchCode" => $results2["bCode"]
          ];
      }
      // handler for identity checkin records
      if( $thisRow["varfield_type_code"] == "i" ) {
          $addComma = false;
          if( isset($checkinRecords[$thisRow["bnum"]][$thisRow["location_code"]]["identity"]) ) {
              $addComma = true;
          } else {
              $checkinRecords[$thisRow["bnum"]][$thisRow["location_code"]]["identity"] = "";
          }
          $str = $thisRow["field_content"];
          while( ($index = strpos($str, "|")) !== false ) {
            $str = substr($str, 0, $index) . substr($str, $index + 2);
          }
          $str = str_replace(".  ", ".\n", trim($str));
          if( $str && strpos($checkinRecords[$thisRow["bnum"]][$thisRow["location_code"]]["identity"], $str) === false ) {
              $checkinRecords[$thisRow["bnum"]][$thisRow["location_code"]]["identity"] .= ($addComma ? "," : "") . $str;
          }
      }
      // handler for libHas checkin records
      if( $thisRow["varfield_type_code"] == "h" ) {
          $addComma = false;
          if( isset($checkinRecords[$thisRow["bnum"]][$thisRow["location_code"]]["libHas"]) ) {
              $addComma = true;
          } else {
              $checkinRecords[$thisRow["bnum"]][$thisRow["location_code"]]["libHas"] = "";
          }
          $str = $thisRow["field_content"];
          while( ($index = strpos($str, "|")) !== false ) {
            $str = substr($str, 0, $index) . substr($str, $index + 2);
          }
          $str = str_replace(".  ", ".\n", trim($str));
          if( $str && strpos($checkinRecords[$thisRow["bnum"]][$thisRow["location_code"]]["libHas"], $str) === false ) {
              $checkinRecords[$thisRow["bnum"]][$thisRow["location_code"]]["libHas"] .= ($addComma ? "," : "") . $str;
          }
      }
  }  

  // grab the latest issue
  $results = pg_query(// generate the temporary table
                      "create temporary table maxABoxes (target_num, latest_box_count) on commit drop as " .
                      "select varfield_view.record_num, " .
                             "max(box_count) " .
                      "from sierra_view.bib_view " .
                           "join sierra_view.bib_record_holding_record_link on (bib_record_holding_record_link.bib_record_id=bib_view.id) " .
                           "join sierra_view.holding_record on (bib_record_holding_record_link.holding_record_id=holding_record.id) " .
                           "join sierra_view.varfield_view on (varfield_view.record_id=holding_record_id) " .
                           "join sierra_view.holding_record_card using (holding_record_id) " .
                           "join sierra_view.holding_record_cardlink on (holding_record_card.id=holding_record_cardlink.holding_record_card_id) " .
                           "join sierra_view.holding_record_box on (holding_record_box.holding_record_cardlink_id=holding_record_cardlink.id) " .
                      "where box_status_code='A' and " .
                            "varfield_type_code in ('h','i','j') " .
                      "group by varfield_view.record_num; " .

                      // now make the actual query leveraging that temp table
                      "select location_name.name, " .
                             "varfield_type_code, " . 
                             "field_content, " . 
                             "location_code, " . 
                             "holding_record_box.enum_level_a as bEA, " . 
                             "holding_record_cardlink.enum_level_a as clEA, " . 
                             "holding_record_box.enum_level_b as bEB, " . 
                             "holding_record_cardlink.enum_level_b as clEB, " . 
                             "holding_record_box.chron_level_i as bCI, " . 
                             "holding_record_cardlink.chron_level_i as clCI, " . 
                             "holding_record_box.chron_level_j as bCJ, " . 
                             "holding_record_cardlink.chron_level_j as clCJ, " . 
                             "holding_record_box.chron_level_k as bCK, " . 
                             "holding_record_cardlink.chron_level_k as clCK, " . 
                             "occ_num, " . 
                             "varfield_view.record_num, " . 
                             "bib_view.record_num as bnum " . 
                      "from sierra_view.bib_view " . 
                           "join sierra_view.bib_record_holding_record_link on (bib_record_holding_record_link.bib_record_id=bib_view.id) " . 
                           "join sierra_view.holding_record on (bib_record_holding_record_link.holding_record_id=holding_record.id) " . 
                           "join sierra_view.varfield_view on (varfield_view.record_id=holding_record_id) " . 
                           "join sierra_view.holding_record_location using (holding_record_id) " . 
                           "join sierra_view.location on (location_code=location.code) " . 
                           "join sierra_view.location_name on (location_id=location.id) " . 
                           "join sierra_view.holding_record_card using (holding_record_id) " . 
                           "join sierra_view.holding_record_cardlink on (holding_record_card.id=holding_record_cardlink.holding_record_card_id) " . 
                           "join sierra_view.holding_record_box on (holding_record_box.holding_record_cardlink_id=holding_record_cardlink.id) " . 
                           "join maxABoxes on (target_num=varfield_view.record_num and latest_box_count=box_count) " .
                      "where box_status_code='A' " . 
                            "and scode4 != 'n' " . 
                            "and varfield_type_code in ('h','i','j') " . 
                            "and holding_record_box.chron_level_i != '' " . 
                      "order by bib_view.record_num, varfield_view.record_num, box_count desc, varfield_type_code, occ_num"); 
  while($thisRow = pg_fetch_array($results)) {
      // add the new row if we haven't seen this bib before
      if( !isset($checkinRecords[$thisRow["bnum"]]) ) {
          $checkinRecords[$thisRow["bnum"]] = [];
      }
      // add the new location code if we haven't seen it before
      if( !isset($checkinRecords[$thisRow["bnum"]][$thisRow["location_code"]]) ) {
          $results2 = mysqli_fetch_assoc( mysqli_query($sqlDB, "select location.code as bCode from shelving_location join location using (locationId) where shelving_location.code='" . $thisRow["location_code"] . "'") );
          $checkinRecords[$thisRow["bnum"]][$thisRow["location_code"]] = [
              "type" => "issueSummary",
              "location" => $thisRow["name"],
              "code" => $thisRow["location_code"],
              "branchCode" => $results2["bCode"]
          ];
      }

      // handler for identity checkin records
      if( $thisRow["varfield_type_code"] == "i" ) {
          $addComma = false;
          if( isset($checkinRecords[$thisRow["bnum"]][$thisRow["location_code"]]["identity"]) ) {
              $addComma = true;
          } else {
              $checkinRecords[$thisRow["bnum"]][$thisRow["location_code"]]["identity"] = "";
          }
          $str = $thisRow["field_content"];
          while( ($index = strpos($str, "|")) !== false ) {
            $str = substr($str, 0, $index) . substr($str, $index + 2);
          }
          $str = str_replace(".  ", ".\n", trim($str));
          if( $str && strpos($checkinRecords[$thisRow["bnum"]][$thisRow["location_code"]]["identity"], $str) === false ) {
              $checkinRecords[$thisRow["bnum"]][$thisRow["location_code"]]["identity"] .= ($addComma ? "," : "") . $str;
          }
      }
      // handler for libHas checkin records
      if( $thisRow["varfield_type_code"] == "h" ) {
          $addComma = false;
          if( isset($checkinRecords[$thisRow["bnum"]][$thisRow["location_code"]]["libHas"]) ) {
              $addComma = true;
          } else {
              $checkinRecords[$thisRow["bnum"]][$thisRow["location_code"]]["libHas"] = "";
          }
          $str = $thisRow["field_content"];
          while( ($index = strpos($str, "|")) !== false ) {
            $str = substr($str, 0, $index) . substr($str, $index + 2);
          }
          $str = str_replace(".  ", ".\n", trim($str));
          if( $str && strpos($checkinRecords[$thisRow["bnum"]][$thisRow["location_code"]]["libHas"], $str) === false ) {
              $checkinRecords[$thisRow["bnum"]][$thisRow["location_code"]]["libHas"] .= ($addComma ? "," : "") . $str;
          }
      }

      $checkinRecords[$thisRow["bnum"]][$thisRow["location_code"]]["latestReceived"] = getIssueStr($thisRow);
  }

  // grab the number of holds on all this stuff
  $holds = [];
  $results = pg_query("select record_num, count(*) as num " .
                      "from sierra_view.hold " . 
                      "join sierra_view.bib_view on (hold.record_id=bib_view.id) " . 
                      "group by bib_view.record_num");
  while( $thisRow = pg_fetch_array($results) ) {
      $holds[$thisRow["record_num"]] = $thisRow["num"];
  }  

  // iterate over the checkin record bibs and dump the results out
  foreach( $checkinRecords as $bnum => $bibCheckins ) {
    $firstTime = true;
    echo $bnum . ":\"numberOfHolds\":";
    // if this bib has checkin records AND holds on it, show the holds here and remove them from the list
    if( isset($holds[$bnum]) ) {
      echo $holds[$bnum];
      unset($holds[$bnum]);
    } else {
      echo "0";
    }
    echo ",\"orderRecords\":";
    // if this bib has order records, add them here and remove them from the list
    if( isset($orderRecords[$bnum]) ) {
      echo json_encode($orderRecords[$bnum]);
      unset($orderRecords[$bnum]);
    } else {
      echo "{}";
    }
    echo ",\"checkinRecords\":[";
    // iterate over the holding locations
    foreach( $bibCheckins as $code => $value ) {
      echo ($firstTime ? "" : ",") . json_encode($value);
      $firstTime = false;
    }
    echo "]\n";
  }

  // iterate over the order record bibs and dump the results out
  foreach( $orderRecords as $bnum => $bibCheckins ) {
    echo $bnum . ":\"numberOfHolds\":";
    // if this bib has order records AND holds on it, show the holds here and remove them from the list
    if( isset($holds[$bnum]) ) {
      echo $holds[$bnum];
      unset($holds[$bnum]);
    } else {
      echo "0";
    }
    echo ",\"orderRecords\":" . json_encode($orderRecords[$bnum]) . ",\"checkinRecords\":[]\n";
  }

  // everything in this list has holds but no checkin records or order records
  foreach( $holds as $bnum => $bibHolds ) {
    echo $bnum . ":\"numberOfHolds\":" . $bibHolds . ",\"orderRecords\":{},\"checkinRecords\":[]\n";
  }
?>
