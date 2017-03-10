<?php  // Moodle configuration file

unset($CFG);
global $CFG;
$CFG = new stdClass();

$CFG->dbtype    = 'mysqli';
//$CFG->dbtype    = 'mysqli_ms';
$CFG->dblibrary = 'native';
$CFG->dbhost    = 'localhost';
//$CFG->dbhost    = 'rdsqlinstance.ctf6mpozduxn.us-west-2.rds.amazonaws.com';
$CFG->dbname    = 'moodle32';
$CFG->dbuser    = 'root';
$CFG->dbpass    = 'root';
//$CFG->dbuser    = 'root';
//$CFG->dbpass    = 'alveria23$';
$CFG->prefix    = 'mdl_';
$CFG->dboptions = array (
  'dbpersist' => 0,
  'dbport' => '',
  'dbsocket' => '',
);

/*
$CFG->dbslaves = array(
    array(
        'dbhost' => 'rdsqlinstanceeu.cif9fvrwhs66.eu-central-1.rds.amazonaws.com',
        'dbname' => 'moodle32',
        'dbuser' => 'rdssuser',
        'dbpass' => 'rdspassword23$',
        'dboptions' => array(
            'dbpersist' => 0,
            'dbport' => '3306',
            'dbsocket' => '',
        ),
    ),
);
*/

//$CFG->wwwroot = 'http://localhost/moodle32';
$CFG->wwwroot = ($_SERVER["HTTPS"]=="on" ? 'https': 'http').'://'.$_SERVER["HTTP_HOST"].'';
$CFG->dataroot  = '/Applications/MAMP/moodledata32';
$CFG->admin     = 'admin';

$CFG->directorypermissions = 0777;

require_once(__DIR__ . '/lib/setup.php');

// There is no php closing tag in this file,
// it is intentional because it prevents trailing whitespace problems!!!!$$$$
