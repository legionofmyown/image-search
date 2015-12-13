<?php
error_reporting(0);
set_time_limit(600);

require 'Katran/GoogleImageSearchService.php';
require 'Katran/SearchException.php';
require 'Katran/DbService.php';
require 'Katran/DbException.php';
require 'Katran/RestService.php';
require 'Katran/RestException.php';

$tmpDir = dirname(__FILE__) . '/tmp';

//create & set right to tmp directory if required
if (!file_exists($tmpDir)) {
    mkdir($tmpDir, 0666);
}

$rights = substr(sprintf('%o', fileperms($tmpDir)), -4);
if ($rights !== '0666') {
    chmod($tmpDir, 0666);
}

$db = new Katran\DbService();

$searcher = new Katran\GoogleImageSearchService($db, $tmpDir);

$rest = new Katran\RestService($searcher, $tmpDir);

$url = isset($_REQUEST['url']) ? $_REQUEST['url'] : null;
$mode = (isset($_REQUEST['mode']) && $_REQUEST['mode'] === 'async') ? false: true;
$minWidth = isset($_REQUEST['minWidth']) ? $_REQUEST['minWidth'] : -1;
$minHeight = isset($_REQUEST['minHeight']) ? $_REQUEST['minHeight'] : -1;
$asyncToken = isset($_REQUEST['asynctoken']) ? $_REQUEST['asynctoken'] : null;
$file = file_get_contents('php://input');
if(!$file && isset($_FILES['file'])) {
    $file = file_get_contents($_FILES['file']['tmp_name']);
}

$rest->handle($asyncToken, $url, $file, $mode, $minWidth, $minHeight);
