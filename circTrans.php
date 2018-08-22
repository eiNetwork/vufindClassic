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

  // this query gets all item status changes since the last time we ran this script
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
                      "where transaction_gmt >= '" . $circTransTime . "' " . 
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
      mysqli_query($sqlDB, "insert into pollItems values (" . $thisRow["patron_record_id"] . "," . $thisRow["item_record_id"] . "," . $thisRow["bnum"] . ",\"" . $thisRow["istatus"] . "\") on duplicate key update patron_record_id=" . $thisRow["patron_record_id"]);
    }
  }

  // check everything in the poll items table (to reduce queries to postgres, we do this in groups of 100)
  $results2 = mysqli_query($sqlDB, "select *, bib_record_num as bnum from pollItems");
  $thisMysqlRow = mysqli_fetch_assoc($results2);
  while( $thisMysqlRow ) {
    // start building the postgres query (we're checking these items to see what their status is. eventually they're going to change from available to in transit to on holdshelf)
    $queryString = "select item_view.item_status_code as istatus, item_view.record_num as inum, concat('p', patron_record_id, 'i', record_id) as key " .
                   "from sierra_view.item_view join sierra_view.hold on (item_view.id=hold.record_id) where ";
    $statuses = [];
    while( $thisMysqlRow && count($statuses) < 100 ) {
      $queryString .= (count($statuses) ? "or " : "") . "(hold.record_id=" . $thisMysqlRow["item_record_id"] . " and hold.patron_record_id=" . $thisMysqlRow["patron_record_id"] . ")";
      // keep track of what mysql thinks the status is
      $statuses["p" . $thisMysqlRow["patron_record_id"] . "i" . $thisMysqlRow["item_record_id"]] = $thisMysqlRow["item_status_code"];
      $thisMysqlRow = mysqli_fetch_assoc($results2);
    }
    $results3 = pg_query($queryString);

    // see which rows need updated
    $updateSqlQueries = [];
    while( $thisRow = pg_fetch_array($results3) ) {
      // make sure this item is in the mysql database
      if( array_key_exists($thisRow["key"], $statuses) ) {
        // postgres has an updated status, so add this the relevant update query
        if( $statuses[$thisRow["key"]] != $thisRow["istatus"] ) {
          if( !isset($updateSqlQueries[$thisRow["istatus"]]) ) {
            $updateSqlQueries[$thisRow["istatus"]] = "update pollItems set item_status_code=\"" . $thisRow["istatus"] . "\" where";
          } else {
            $updateSqlQueries[$thisRow["istatus"]] .= " or";
          }
          $itemSplit = explode("i", $thisRow["key"]);
          $patronSplit = explode("p", $itemSplit[0]);
          $updateSqlQueries[$thisRow["istatus"]] .= " (item_record_id=" . $itemSplit[1] . " and patron_record_id=" . $patronSplit[1] . ")";
        }
        // remove it from the list of items to be handled
        unset( $statuses[$thisRow["key"]] );
      }
    }

    // anything that wasn't found needs to be deleted, either because it's been checked out or the hold has been cancelled and the item is available again
    if( count($statuses) ) {
      $sqlQueryString = "delete from pollItems ";
      $firstTime = true;
      foreach( $statuses as $key => $value ) {
        $itemSplit = explode("i", $key);
        $patronSplit = explode("p", $itemSplit[0]);
        $sqlQueryString .= ($firstTime ? " where" : " or") . " (patron_record_id=" . $patronSplit[1] . " and item_record_id=" . $itemSplit[1] . ")";
        $firstTime = false;
      }
      mysqli_query($sqlDB, $sqlQueryString);
    }

    // everything in the updates dictionary needs to be updated
    foreach( $updateSqlQueries as $thisQuery ) {
      mysqli_query($sqlDB, $thisQuery);
    }
  }
?>