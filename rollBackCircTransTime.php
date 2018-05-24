<?php
  // start memcached
  $memcached = new Memcached();
  $memcached->addServer('localhost', 11211);

  // grab the start time for our circ_trans query
  $circTransTime = strtotime("yesterday 20:00:00");
  $memcached->set("lastCircTransTime", strftime("%Y-%m-%d %T", $circTransTime));
?>
