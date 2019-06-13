<?php

/*
Receive GPS location data (user GPRS link) from a TK103 gps tracker device and show in Google maps

(c) 2019 Bart Mellink

This code read the database with GPS data (created by receivermulti.php) and creates a KML file (to STDOUT)
This means the php file can be called from a web server

*/

error_reporting(E_ERROR | E_PARSE);

require_once 'config.php'; // keys and login information

// Paris and Idiorm are handy libs to handle mysql command sets
require_once 'Paris/idiorm.php';
require_once 'Paris/paris.php';

// database model
class Gpsdata extends Model { }

ORM::configure('mysql:host='.DBHOST.';dbname='.DBNAME);
ORM::configure('username', DBUSER);
ORM::configure('password', DBPASSWORD);
// set sql mode to less strict
ORM::configure('driver_options', array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES \'UTF8\', SQL_MODE = \'\''));

date_default_timezone_set(DBTIMEZONE);

// print_r($_GET);
// Arguments are:
//  rnd = random number to ensure Google does not cache our kml file
//  date = date in format YYYY-MM-DD
//  sn = serial number
//

// allow running the script from the web server and local (for testing)
// if date is not specified, we will set date to the last latest date we have data later on
if (isset($_SERVER["REMOTE_ADDR"])) {
  // normal mode: from server
  $sn = (isset($_GET['sn']) ? $_GET['sn'] : 0) * 1;
  $date = (isset($_GET['date']) ? $_GET['date'] : "");
} else {
  // testing when running from command line, first argument is serial number, second is date
  $sn = (isset($argv[1]) ? $argv[1] : 0) * 1;
  $date = (isset($argv[2]) ? $argv[2] : "");
}

$lastdate=$firstdate=date('Y-m-d');

// get a list of all available dates
$availdates = Model::factory('Gpsdata')
        ->where_equal('serial', $sn)
        ->select_expr('date(datetime)', 'date')
        ->select_expr('max(lat)-min(lat)', 'lat1')
        ->select_expr('max(`long`)-min(`long`)', 'long1')
        ->where_not_equal('cmd','BP05') // may not have stable gps signal yet
        ->where_not_equal('valid', 0)
        ->group_by_expr('date(datetime)')
        ->order_by_desc('date')
        ->find_many();

// create an array with all dates for which we have data 
// also indicate with the flag 'ismoved' if on the given date gps coordinates are spread wider
// then the minimum threshold defined in MINMOVELATLONG
// Always include the last date for which we have data in this list (ismoved==true)
// also assign $date in case it is not supplied as commandline argument
if (!$availdates) $alldates = 'No data'; else {
  $alldates = array();
  foreach ($availdates as $i=>$avail) {
    $dat=date_create($avail->date);

    if ($i==0) $lastdate = $avail->date;  // $availdates is sorted descending, so $i==0 is my last date
    $firstdate = $avail->date;
    if ($i==0 && $date=="") $date = $avail->date;
  
    $alldates[] = array('value'=>$avail->date, 'ismoved'=> $avail->lat1+$avail->long1 > MINMOVELATLONG || $i==0);
  }
}

// get all waypoints at the given date
// The GPS tracker often sends the same data more than once, we filter out duplicates
$datarec = Model::factory('Gpsdata')
 				->select('*')
        ->where_equal('serial', $sn)
 				->where_not_equal('valid', 0)
 				->where_not_equal('cmd','BP05') // may not have stable gps signal yet
        ->where_raw("date(datetime)=?", $date)
        ->group_by('gpstime')      // filter out duplicate entries
 				->order_by_asc('gpstime')  // this is sorting order of original gps signal
 				->find_many();
 // print_r($datarec);

// Now analyze these waypoints to figure out start/stop moments of trips
// We do this my looking at the gps speed parameter. Speed should be > 1 km/hour during at least
// 4 consecutive gps coordinates (one or 2 points may point to random movement) 
// We also consider at least 4 consecutive speed==0 as the end of a trip.
$trips = array();
$status = 0; //0=stop, 1,2,3=may move, 4=move, 5,6,7=may stop
$tripstart = $lastone = $firstone = array();

// status/alarm codes based on bits
$powererr = array(); // power lost (1 on bit 7)
$starterr = array();  // start alarm (1 on bit 6)
$shockerr = array(); // movement (error 7) 
$chargeerr = array(); // charging now (error 1 or 2?)
$powerstat = $chargestat = $shockstat = $startstat = NULL; //copy of rec when became active

foreach($datarec as $i=>$rec) {
  $lastone = $rec->as_array();
  if ($i==0) $firstone = $lastone;
  switch($status) {
    case 0: // stopped
        if ($rec->speed > 1) $tripstart = $rec->as_array();
    case 1: // may move step 1
    case 2: // may move step 2
    case 3: // may move step 3
        if ($rec->speed > 1) $status++; else $status = 0;
        break;
    case 4: // moving
    case 5: // may stop step 1
    case 6: // may stop step 2
        if ($rec->speed == 0) $status++; else $status = 4;
        break;
    case 7: // may stop step 3
    default:
        if ($rec->speed == 0) {
          $d = date_diff(date_create($tripstart['gpstime']), date_create($rec->gpstime));
          $trips[] = array('start' => $tripstart, 'end' => $rec->as_array(), 'duration' => $d->h*60 + $d->i + ($d->s>=30 ? 1 : 0));
          $status = 0; 
        } else $status = 4;
        break;
  }
  errorhandling($powererr, (floor($rec->status/10000000)>0), $rec->as_array(), $powerstat);
  errorhandling($starterr, (floor($rec->status/1000000)%10>0), $rec->as_array(), $chargestat);
  errorhandling($shockerr, $rec->err==7, $rec->as_array(), $shockstat);
  errorhandling($chargeerr, $rec->err==2, $rec->as_array(), $startstat);
}
if ($status >= 4) {
  $d = date_diff(date_create($tripstart['gpstime']), date_create($lastone['gpstime']));
  $trips[] = array('start' => $tripstart, 'end' => $lastone, 'duration' => $d->h*60 + $d->i + ($d->s>=30 ? 1 : 0));
}
errorhandling($powererr, 0, $lastone, $powerstat);
errorhandling($starterr, 0, $lastone, $startstat);
errorhandling($shockerr, 0, $lastone, $shockstat);
errorhandling($chargeerr, 0, $lastone, $chargestat);

function errorhandling(&$err_array, $status, $rec, &$startmoment) {
  // only do something on change
  if ($status && $startmoment==NULL) {
    // Alarm just turns on
    $startmoment = $rec;
  } else if (!$status && $startmoment!=NULL) {
    // now off and it was not on
    $d = date_diff(date_create($startmoment['gpstime']), date_create($rec['gpstime']));
    $err_array[] = array('start' => $startmoment, 'end' => $rec, 'duration' => $d->h*60 + $d->i + ($d->s>=30 ? 1 : 0));
    $startmoment = NULL;
  }
}

// Creates the KML Document (which is in XML format)
$dom = new DOMDocument('1.0', 'UTF-8');

// Creates the root KML element and appends it to the root document.
$node = $dom->createElementNS('http://earth.google.com/kml/2.1', 'kml');
$parNode = $dom->appendChild($node);

// Creates a KML Document element and append it to the KML element.
$dnode = $dom->createElement('Document');
$docNode = $parNode->appendChild($dnode);

//print_r($powererr);

// The doc description field contains json_encoded data which we need to display the calendar view and other
// information about a given date
$docDesc = $dom->createElement('description', json_encode(
  array(
    'alldates' => $alldates,  // list of all dates for which we have data (and where there is movement)
    'date'     => $date,      // the current displayed date (for which we provide detailed data)
    'lastdate' => $lastdate,  // most recent date for which we have data (YY-MM-DD)
    'firstdate'=> $firstdate, // oldest date for which we have date (YY-MM-DD)
    'trips'    => $trips,     // list of trips for the given date
    'firstone' => $firstone,  // complete record of first data entry of given date
    'lastone'  => $lastone,   // complete record of most recent data for the given date
    'powererr' => $powererr,  // array of events related to battery power of gps tracker (on/off)
    'starterr' => $starterr,  // array of events related to motor start (on/off)
    'shockerr' => $shockerr,  // array of events related to shocking the gps tracker (on/off)
    'chargeerr' => $chargeerr,// array of events related to ecternal charging of boat battery (on/off)
    'age'      => (time() - strtotime($lastone['datetime'])), // age (in seconds) of last record
  )
));

$docNode->appendChild($docDesc);
$docName = $dom->createElement('name','Document Name');
$docNode->appendChild($docName);

// line style
$StyleNode = $dom->createElement('Style');
$StyleNode->setAttribute('id', 'mycolorpoly');
$LineStyleNode = $dom->createElement('LineStyle');
$LineColor = $dom->createElement('color','501400F0');
$LineWidth = $dom->createElement('width','4');
$PolyStyleNode = $dom->createElement('PolyStyle');
$PolyColor = $dom->createElement('color','50140000');
$LineStyleNode->appendChild($LineColor);
$LineStyleNode->appendChild($LineWidth);
$PolyStyleNode->appendChild($PolyColor);
$StyleNode->appendChild($LineStyleNode);
$StyleNode->appendChild($PolyStyleNode);
$docNode->appendChild($StyleNode);


$PlacemarkNode = $dom->createElement('Placemark');
$docNode->appendChild($PlacemarkNode);
$PlaceStyle = $dom->createElement('styleUrl','#mycolorpoly');
$PlacemarkNode->appendChild($PlaceStyle);
$LineString = $dom->createElement('LineString');
$PlacemarkNode->appendChild($LineString);

$coorStr ="";
$timesave=0; 

/* nog doen:
- huidige positie
- geef pinpoints voor start/einde en pauze plekken
- statistieken voor hoe lang een trip heeft geduurd
- in html met een cvookie bepalen welk serienummer ik heb

*/

foreach($datarec as $i=>$rec) {
  // Creates a coordinates element and gives it the value of the lng and lat columns from the results.
  // Coordinates in database are already in DD (Decimal Degrees) format (52.1825)

  $t = strtotime($rec->gpstime);
  $hst = ($rec->long) . ','  . ($rec->lat).",0 \n";

  // add the first timestamp in the description within Placemark
  if ($i==0) {
  	$PlaceDesc = $dom->createElement('description',$rec->gpstime);
    $PlacemarkNode->appendChild($PlaceDesc);
  	$PlaceName = $dom->createElement('name','Name='.$rec->gpstime);
    $PlacemarkNode->appendChild($PlaceName);
  }

  // If the time interval between the current and prior point is larger than TIMEGAP 
  // or if we have a new date, we will create a new LineString 

  if ($rec->err==0) {
    if ($i>0 && ($t>$timesave+TIMEGAP || date('z',$t)!=date('z',$timesave))) { // date('z') gives day in year 0..365
    	// too big a time gap between points or new day, close LineString and create a new one
    	$coorNode = $dom->createElement('coordinates', $coorStr);
    	$LineString->appendChild($coorNode);
    	// and create a new one
    	$PlacemarkNode = $dom->createElement('Placemark');
    	$docNode->appendChild($PlacemarkNode);
     	$PlaceDesc = $dom->createElement('description',$rec->gpstime);
      $PlacemarkNode->appendChild($PlaceDesc);
     	$PlaceName = $dom->createElement('name','Name='.$rec->gpstime);
      $PlacemarkNode->appendChild($PlaceName);
    	$PlaceStyle = $dom->createElement('styleUrl','#mycolorpoly');
    	$PlacemarkNode->appendChild($PlaceStyle);
    	$LineString = $dom->createElement('LineString');
    	$PlacemarkNode->appendChild($LineString);
     	$coorStr = $hst;
    } else {
    	// append coordinate to string
    	$coorStr .= $hst;
    }
  }
  $timesave = $t;
}

$coorNode = $dom->createElement('coordinates', $coorStr);
$LineString->appendChild($coorNode);

// now generate our XML data
$kmlOutput = $dom->saveXML();
header('Content-type: application/vnd.google-earth.kml+xml');
echo $kmlOutput;


?>