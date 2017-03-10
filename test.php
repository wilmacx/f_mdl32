<?php
// Standard GPL and phpdocs
require_once('config.php');

global $CFG, $USER;


 
//$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title("test");
$PAGE->set_heading("test");
$PAGE->set_url($CFG->wwwroot.'/test.php');


echo $OUTPUT->header();


//include('/blocks/configurable_reports/viewreport.php?id=4&courseid=1');

// Actual content goes here
//echo "Hello World";

//echo $USER->firstname;

/*
$DB_Server = "localhost"; //MySQL Server    
$DB_Username = "alveria4_moodle"; //MySQL Username     
$DB_Password = "alveria23$";             //MySQL Password     
$DB_DBName = "alveria4_moodle32";         //MySQL Database Name  
$DB_TBLName = "mdl_user"; //MySQL Table Name
*/

$sql = 'SELECT u.firstname FIRST,u.lastname LAST,u.city, u.country FROM mdl_user AS u WHERE u.id='.$USER->id;

//$Connect = @mysql_connect($DB_Server, $DB_Username, $DB_Password) or die("Couldn't connect to MySQL:<br>" . mysql_error() . "<br>" . mysql_errno());
$Connect = @mysql_connect($CFG->dbhost, $CFG->dbuser, $CFG->dbpass) or die("Couldn't connect to MySQL:<br>" . mysql_error() . "<br>" . mysql_errno());

//select database   
//$Db = @mysql_select_db($DB_DBName, $Connect) or die("Couldn't select database:<br>" . mysql_error(). "<br>" . mysql_errno());
$Db = @mysql_select_db($CFG->dbname, $Connect) or die("Couldn't select database:<br>" . mysql_error(). "<br>" . mysql_errno());  
//execute query 
$result = @mysql_query($sql,$Connect) or die("Couldn't execute query:<br>" . mysql_error(). "<br>" . mysql_errno());

$fields_num = mysql_num_fields($result);

echo "<h1>{$USER->firstname} {$USER->lastname} Reports</h1>";
echo "<table border='1'><tr>";
// printing table headers
for($i=0; $i<$fields_num; $i++)
{
    $field = mysql_fetch_field($result);
    echo "<td>{$field->name}</td>";
}
echo "</tr>\n";
// printing table rows
while($row = mysql_fetch_row($result))
{
    echo "<tr>";

    // $row is array... foreach( .. ) puts every element
    // of $row to $cell variable//8
    foreach($row as $cell)
        echo "<td>$cell</td>";

    echo "</tr>\n";
}
mysql_free_result($result);

echo "</table>";
echo $OUTPUT->footer();
?>