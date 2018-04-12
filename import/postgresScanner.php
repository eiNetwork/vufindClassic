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

  // get postgres connection
  $db = pg_connect("host=sierra-db.einetwork.net port=1032 dbname=iii user=xxbp password=" . chr(48) . chr(88) . chr(51) . chr(78) . chr(117) . chr(103) . chr(108) . chr(121));

  // get sql connection
  $sqlDB = mysqli_connect("localhost", "root", "vufind", "vufind");

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
          if( strpos($checkinRecords[$thisRow["bnum"]][$thisRow["location_code"]]["identity"], $str) === false ) {
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
          if( strpos($checkinRecords[$thisRow["bnum"]][$thisRow["location_code"]]["libHas"], $str) === false ) {
              $checkinRecords[$thisRow["bnum"]][$thisRow["location_code"]]["libHas"] .= ($addComma ? "," : "") . $str;
          }
      }
  }  

  // grab the latest issue
  $results = pg_query("select location_name.name, " .
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
                           "join (" . 
                           "  select varfield_view.record_num as target_num, max(box_count) as latest_box_count " . 
                           "  from sierra_view.bib_view " . 
                           "  join sierra_view.bib_record_holding_record_link on (bib_record_holding_record_link.bib_record_id=bib_view.id) " . 
                           "  join sierra_view.holding_record on (bib_record_holding_record_link.holding_record_id=holding_record.id) " . 
                           "  join sierra_view.varfield_view on (varfield_view.record_id=holding_record_id) " . 
                           "  join sierra_view.holding_record_card using (holding_record_id) " . 
                           "  join sierra_view.holding_record_cardlink on (holding_record_card.id=holding_record_cardlink.holding_record_card_id) " . 
                           "  join sierra_view.holding_record_box on (holding_record_box.holding_record_cardlink_id=holding_record_cardlink.id) " . 
                           "  where box_status_code='A' " . 
                           "  and varfield_type_code in ('h','i','j') " . 
                           "  group by varfield_view.record_num " . 
                           ") as maxABoxes on (target_num=varfield_view.record_num and latest_box_count=box_count) " . 
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
          if( strpos($checkinRecords[$thisRow["bnum"]][$thisRow["location_code"]]["identity"], $str) === false ) {
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
          if( strpos($checkinRecords[$thisRow["bnum"]][$thisRow["location_code"]]["libHas"], $str) === false ) {
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

  // iterate over the bibs and dump the results out
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
    echo ",\"checkinRecords\":[";
    // iterate over the holding locations
    foreach( $bibCheckins as $code => $value ) {
      echo ($firstTime ? "" : ",") . json_encode($value);
      $firstTime = false;
    }
    echo "]\n";
  }

  // everything in this list has holds but no checkin records
  foreach( $holds as $bnum => $bibHolds ) {
    echo $bnum . ":\"numberOfHolds\":" . $bibHolds . ",\"checkinRecords\":[]\n";
  }
?>