<?php
  // start memcached
  $memcached = new Memcached();
  $memcached->addServer('localhost', 11211);

  // grab the start time for our circ_trans query (the time the last extract ran - 8:00PM yesterday)
  $circTransTime = strftime("%Y-%m-%d %T", strtotime("yesterday 20:00:00")); 

  // calculate the check digit for a given bib number
  function getCheckDigit($id)
  {
    // pull off the item type if they included it
    if( !is_numeric($id) ) {
      $id = substr($id, 1);
    }
    // make sure it's a number
    if( !is_numeric($id) ) {
      return null;
    }

    // calculate it
    $checkDigit = 0;
    $multiple = 2;
    while( $id > 0 ) {
      $digit = $id % 10;
      $checkDigit += $multiple * $digit;
      $id = ($id - $digit) / 10;
      $multiple++;
    }
    $checkDigit = $checkDigit % 11;
    return ($checkDigit == 10) ? "x" : $checkDigit;
  }

  // get the cache for a given bib
  function getCache($thisRow) 
  {
    global $memcached;

    $cacheKey = "holdingID.b" . $thisRow["bnum"] . getCheckDigit($thisRow["bnum"]);
    $cachedStatus = $memcached->get($cacheKey);
    if( !$cachedStatus ) {
      $cachedStatus = ["CACHED_INFO" => []];
    } else if( !isset($cachedStatus["CACHED_INFO"]) ) {
      $cachedStatus["CACHED_INFO"] = [];
    }
    $updateKey = "updatesID.b" . $thisRow["bnum"] . getCheckDigit($thisRow["bnum"]);
    $updateStatus = $memcached->get($updateKey);
    if( !$updateStatus ) {
      $updateStatus = [];
    }
    return ["key" => $cacheKey, "value" => $cachedStatus, "updateKey" => $updateKey, "updateValue" => $updateStatus];
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

  // get mysql connection
  $sqlDB = mysqli_connect($mysqlProperties["host"], $mysqlProperties["user"], $mysqlProperties["password"], $mysqlProperties["circTransDbname"]);

  // get postgres connection
  $db = pg_connect("host=" . $postgresProperties["host"] . " port=" . $postgresProperties["port"] . " dbname=" . $postgresProperties["dbname"] . " user=" . $postgresProperties["user"] . " password=" . $postgresProperties["password"]);

  // this query gets the first status change occurring after our cutoff time
  $results = pg_query("select min(id) as minID from sierra_view.circ_trans where transaction_gmt >= '" . $circTransTime . "' and op_code in ('i','o','ni')");
  $minID = pg_fetch_array($results);
  $minID = $minID["minid"];

  // this query gets all item status changes happening after the minID
  $results = pg_query("select patron_view.barcode as pbar, " . 
                             "item_view.barcode as ibar, " . 
                             "item_view.location_code as iloc, " . 
                             "item_view.item_status_code as istatus, " . 
                             "item_view.record_num as inum, " . 
                             "bib_view.record_num as bnum, " . 
                             "statistic_group.location_code as operation_location, " . 
                             "op_code, " . 
                             "due_date_gmt, " . 
                             "item_record_id, " . 
                             "patron_record_id, " . 
                             "transaction_gmt " .  
                      "from sierra_view.circ_trans " . 
                           "left join sierra_view.patron_view on (circ_trans.patron_record_id=patron_view.id) " . 
                           "left join sierra_view.item_view on (circ_trans.item_record_id=item_view.id) " . 
                           "left join sierra_view.bib_view on (circ_trans.bib_record_id=bib_view.id) " . 
                           "left join sierra_view.statistic_group on (circ_trans.stat_group_code_num=statistic_group.code_num) " . 
                      "where circ_trans.id >= " . $minID . " and op_code in ('i','o','ni') " .
                      "order by transaction_gmt desc"); 
  while($thisRow = pg_fetch_array($results)) {
    $cache = getCache($thisRow);

    // make sure we don't have a more current status for this item
    if( isset($cache["updateValue"][$thisRow["inum"]]) && $cache["updateValue"][$thisRow["inum"]]["time"] > $thisRow["transaction_gmt"] ) {
      continue;
    }

    // item is checked out, change it to unavailable
    if( $thisRow["op_code"] == "o" ) {
      $thisChange = ["status" => $thisRow["istatus"], "duedate" => $thisRow["due_date_gmt"], "inum" => $thisRow["inum"], "bnum" => $thisRow["bnum"], "time" => $thisRow["transaction_gmt"], "handled" => false];
      // see whether this change has already been handled
      if( isset($cache["value"]["CACHED_INFO"]["holding"]) ) {
        foreach( $cache["value"]["CACHED_INFO"]["holding"] as $thisItem ) {
          // if the item ids match and the due dates match, we've already seen this. flag it as handled
          if( $thisItem["itemId"] == $thisChange["inum"] ) {
            $itemDueDate = (isset($thisItem["duedate"]) && ($thisItem["duedate"] != null)) ? $thisItem["duedate"] : "NULL";
            if( $itemDueDate == strftime("%m-%d-%y", strtotime($thisChange["duedate"])) ) {
              $thisChange["handled"] = true;
            }
          }
        }
      }
      $cache["updateValue"][$thisRow["inum"]] = $thisChange;
      $memcached->set($cache["updateKey"], $cache["updateValue"]);
    // this item has been returned, change it to in transit or available
    } else if( $thisRow["op_code"] == "i" ) {
      $thisChange = ["status" => $thisRow["istatus"], "duedate" => "NULL", "inum" => $thisRow["inum"], "bnum" => $thisRow["bnum"], "time" => $thisRow["transaction_gmt"], "handled" => false];
      // see whether this change has already been handled
      if( isset($cache["value"]["CACHED_INFO"]["holding"]) ) {
        foreach( $cache["value"]["CACHED_INFO"]["holding"] as $thisItem ) {
          // if the item ids match and the due dates match, we've already seen this. flag it as handled
          if( $thisItem["itemId"] == $thisChange["inum"] ) {
            if( !isset($thisItem["duedate"]) || ($thisItem["duedate"] == null) ) {
              $thisChange["handled"] = true;
            }
          }
        }
      }
      $cache["updateValue"][$thisRow["inum"]] = $thisChange;
      $memcached->set($cache["updateKey"], $cache["updateValue"]);
    // this item has been assigned to an item-level hold, add it to the poll table
    } else if( $thisRow["op_code"] == "ni" ) {
      $thisChange = ["status" => $thisRow["istatus"], "duedate" => "NULL", "inum" => $thisRow["inum"], "bnum" => $thisRow["bnum"], "time" => $thisRow["transaction_gmt"], "handled" => false];
      // see whether this change has already been handled
      if( isset($cache["value"]["CACHED_INFO"]["holding"]) ) {
        foreach( $cache["value"]["CACHED_INFO"]["holding"] as $thisItem ) {
          // if the item ids match and the statuses match, we've already seen this. flag it as handled
          if( $thisItem["itemId"] == $thisChange["inum"] ) {
            if( ($thisItem["status"] == $thisChange["status"]) && (!isset($thisItem["duedate"]) || ($thisItem["duedate"] == null)) ) {
              $thisChange["handled"] = true;
            }
          }
        }
      }
      $cache["updateValue"][$thisRow["inum"]] = $thisChange;
      $memcached->set($cache["updateKey"], $cache["updateValue"]);
    }
  }
?>