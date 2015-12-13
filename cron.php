<?php

set_time_limit(1200);

require 'Katran/GoogleImageSearchService.php';
require 'Katran/SearchException.php';
require 'Katran/DbService.php';
require 'Katran/DbException.php';
require 'Katran/AsyncProcessorService.php';

$db = new Katran\DbService();
$tmpDir = dirname(__FILE__) . '/tmp';

$searcher = new Katran\GoogleImageSearchService($db, $tmpDir);

$async = new Katran\AsyncProcessorService($searcher, $tmpDir);
$async->process();
