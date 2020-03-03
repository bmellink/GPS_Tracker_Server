<?php

/*
Receive GPS location data (user GPRS link) from a TK103 gps tracker device

(c) 2019 Bart Mellink

The code in this lib only supports receiving GPS data from the tracker device. Commands to program the
tracker are not implemented (the TK103 also supports SMS messaging for this).

This code should run on any Linux distribution. Mine runs on a Raspberyy pi

Revisions:
- 4 May 2019 - created
- 19 May 2019 - first live 
- 22 May 2019 - added column err in database
- 23 Feb 2020 - fixed problem with timezone (gmtdiff) if script runs multiple months crossing a daylight savings change

057045186956 - Ben
057045206556 - BM

Please note the device consumes more power then you may expect:
- 60-80 mA when charging battery
- 30 mA when active
- 20 mA when at rest (send event once every 10 minutes)
It is possible to remove the Orange and Red LED's from the PCB board. 
This reduces the current at rest to about 6mA 

A typical car battery has a capacity of 35 Ah. This means the TK103 will drain the battery at:
- 30 mA load --> 1167 hours -> 48 days 
- 6 mA load  --> 5833 hours -> 243 days (8 months)
Boat battery typically have a much higher capacity (100 Ah or more)

Full protocol description: http://gpsvehicletracking.co.in/GPS-Tracker-TK103-Protocol.pdf
See this page for various other protocols used for gps trackers.
https://raw.githubusercontent.com/traccar/traccar/master/tools/test-integration.py

The TK103 device sends string over GPRS socket connection that look like this
(057045206556BP05357857045206556190503A5210.8891N00428.3968E000.0152958000.0000000000L00000000)
- starter char '('
- serial number 12 bytes: 057045206556
- command 4 byte: BP05
- message body
- trail char ')'

Command codes for messages from device->server all start with B. Implemented messages:
BO01 - alarm
BP00 - handshake
BP01 - device software serial number
BP02 - device is configured
BP03 - device operated status
BP05 - answer device login  - first
BP12 - answer high and low speed limit
BR00 - isochronous cont feedback
BR01 - isometry continuous feedback

The server response commands all start with A. Implemented messages:
AP05 - Response to login BP05, 
AP01 - Handshake response BP00, 
AS01 - Alarm response to BO01, 
AS02 - Alarm response to BO02

The message body of the BP05/BR00/BR01 commands look like
357857045206556190503A5210.8942N00428.4043E000.0134955000.0000000000L00000000
[0] => 357857045206556190503A5210.8942N00428.4043E000.0134955000.0000000000L00000000
[1] => 357857045206556 --> IMEI or for other commands this may have other data or may be empty
[2] => 190503 -> DATE YYMMDD
[3] => 5210.8942 -> Lat in DDM format
[4] => N
[5] => 00428.4043 -> Long in DDM format
[6] => E
[7] => 000.0 -> speed
[8] => 134955 -> time HHMMSS
[9] => 000.00 -> angle in degrees
[10] => 00000000 -> status bits
[11] => 00000000 -> distance total in meter (not supported b TK103)

Status bit pattern
   0000 0000 - all resting
   0000 0001 - movement
   1000 0000 - power lost
   0100 0000 - ACC on (green wire connected to +12V)
   0000 0010 - shock sensor alarm enabled
   0000 0100 - cut off oil (send 222#)

Program settings of TK103 I use:
GPS moving report intervals: 60sec 
static report intervals: 10min 

This code was inspired by this code https://stackoverflow.com/questions/16715313/how-can-i-detect-when-a-stream-client-is-no-longer-available-in-php-eg-network

All data is stored in a MySQL database in a table called gpsdata
CREATE TABLE `gpsdata` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `datetime` datetime NOT NULL,
  `socket` int(5) NOT NULL,
  `cmd` char(4) NOT NULL DEFAULT '',
  `err` int(1) NOT NULL,
  `serial` bigint(12) NOT NULL,
  `valid` int(1) NOT NULL,
  `lat` double NOT NULL,
  `long` double NOT NULL,
  `heading` int(4) NOT NULL,
  `speed` decimal(10,1) NOT NULL,
  `status` int(8) NOT NULL,
  `distance` int(11) NOT NULL,
  `ip` varchar(25) NOT NULL DEFAULT '',
  `gpstime` time NOT NULL,
  PRIMARY KEY (`id`),
  KEY `datetime` (`datetime`),
  KEY `serialvalid` (`serial`,`valid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


*/

error_reporting(E_ERROR | E_PARSE);

require_once 'config.php'; // keys and login information (use config_sample.php as example)

// Paris and Idiorm are handy libs to handle mysql command sets
require_once 'Paris/idiorm.php';
require_once 'Paris/paris.php';

// database model
class Gpsdata extends Model { }

if (isset($_SERVER["REMOTE_ADDR"])) {
  die("Can not run through web server.");
}


ORM::configure('mysql:host='.DBHOST.';dbname='.DBNAME);
ORM::configure('username', DBUSER);
ORM::configure('password', DBPASSWORD);
// set sql mode to less strict
ORM::configure('driver_options', array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES \'UTF8\', SQL_MODE = \'\''));
date_default_timezone_set(DBTIMEZONE);

// ensure we set our php script to the same time zone as the database
date_default_timezone_set(DBTIMEZONE);
// gps datetime info will be in GMT, so we need to know the difference
$gmtdiff = date('Z'); // timezone difference in seconds. positive for the East, negative for the west of GMT

// setup socket server
$socket = stream_socket_server(GPSPORT, $errno, $errstr, STREAM_SERVER_BIND|STREAM_SERVER_LISTEN);
if (!$socket) {
  die("\n".date("H:i:s")." Test open stream: $errstr\n");
}

// we are ready to receive connections
logdata('BOOT', (int) $socket, '0');
echo "\n".date("H:i:s")." (#".(int) $socket.") Waiting for connection..";

// the $clients array contains both the server socket (listen to new connections) and the clients 
// each entry contains all meta data
$clients = array(
  (int) $socket => array(
        'sock' => $socket, // socket itself
        'ip' => "", // ip address of sender (0 for server)
        'serial' => 0, // unique serial number sender (0 for server)
        'time' => time() // timestamp of last communication
      )
  );

while (true) {
  echo (count($clients)-1); // show once every 60 seconds how many connections we have

  $gmtdiff = date('Z'); // need to recalc timezone difference in seconds if this script runs throughout a daylight savings change throughout the year

  // first we look if we have any duplicate entries (same serial number). This means the client reconnected
  // without clearly shutting down the connection
  foreach ($clients as $c1) {
    if ($c1['serial']!=0) { // exclude the server or connections that just have opened
      foreach ($clients as $c2) {
        // when another entry (differnt socket number) has the same serial number, we kill the oldest one
        if ((int) $c1['sock']!=(int) $c2['sock'] && $c1['serial']==$c2['serial'] && $c1['time']<$c2['time']) {
          $i = (int) $c1['sock'];
          echo "\n".date("H:i:s")." (#$i) Clean up socket from ".$c1['ip'];
          logdata('DOWN', $i, $c1['ip']);
          // print_r($clients);
          stream_socket_shutdown($c1['sock'], STREAM_SHUT_RDWR);
          unset($clients[$i]);
        }
      }
    } else if ((int) $c1['sock']!=(int) $socket && $c1['time']<time()-600) {
      $i = (int) $c1['sock'];
      echo "\n".date("H:i:s")." (#$i) Clean up orphen socket";
      logdata('KILL', $i, $c1['ip']);
      stream_socket_shutdown($c1['sock'], STREAM_SHUT_RDWR);
      unset($clients[$i]);
    }
  }

  // Compile a list of all our streams to call stream_select
  $read = array_column($clients,'sock'); $write = NULL; $ex = NULL;
  // stream_select will either end after 60 seconds or let us know if anything happens (new or existing socket)
  // if something happens, $read will be updated with those sockets that have data
  stream_select($read, $write, $ex, 60);

  foreach ($read as $sock) {
    if ($sock === $socket) {
      // something happens on the main server socket - a new connection
      $client = stream_socket_accept($socket, 1, $peername);
      echo "\n".date("H:i:s")." (#".(int) $client.") New connection ";
      if ($client) {
        echo "from $peername";
        logdata('CONN', (int) $client, $peername);
        stream_set_timeout($client, 1);
        stream_set_blocking($client, false);
        $clients[(int) $client] = array(
            'sock' => $client, 
            'ip'=>$peername, 
            'serial' => 0,  // will be populated when we start receiving data
            'time' => time() // will be updated when we receive data
          );
      } else echo "... aborted.";
    }
    else {
      // We may receive multiple entries (... data ...)(... data ...). Split based on closing ')'
      $ana = explode(')',stream_get_contents($clients[(int) $sock]['sock']));

      foreach ($ana as $hst) {
        // example: "(057045206556BP05357857045206556190503A5210.8942N00428.4043E000.0134955000.0000000000L00000000)";
        // note: the closing bracket ')' is stripped off by explode()
        
        // first analyze command structure
        if (preg_match('/\((\d{12})([AB][OPQRSTUVXY]\d\d)(.+)/', $hst, $command)) {
          /* this returns Array (
            [0] => (057045206556BP05357857045206556190503A5210.8942N00428.4043E000.0134955000.0000000000L00000000)
            [1] => 057045206556   --> serial number (12 bytes)
            [2] => BP05 -> command code
            [3] => 357857045206556190503A5210.8942N00428.4043E000.0134955000.0000000000L00000000 --> data
          ) 
          */
          echo "\n".date("H:i:s")." (#".(int) $sock.") SN=".$command[1]." Cmd=".$command[2]." data=".$command[3]." ";
          
          $clients[(int) $sock]['serial'] = $command[1];
          $clients[(int) $sock]['time'] = time();

          // now analyze the gps data
          $havegps = false;
          if (preg_match('/(.*)(\d{6})A([\d\.]+)([NS])([\d\.]+)([EW])([\d\.]{5})(\d{6})([\d\.]{6})(\d+)L(\d+)/', $command[3], $match)) {
            // print_r($match);
            /* returns analysis of GPS data
                [0] => 357857045206556190503A5210.8942N00428.4043E000.0134955000.0000000000L00000000
                [1] => 357857045206556 --> IMEI or for other commands this may have other data or may be empty
                [2] => 190503 -> DATE YYMMDD
                [3] => 5210.8942 -> North
                [4] => N
                [5] => 00428.4043 -> East
                [6] => E
                [7] => 000.0 -> speed
                [8] => 134955 -> time HHMMSS
                [9] => 000.00 -> angle in degrees
                [10] => 00000000 -> status bits
                [11] => 00000000 -> distance total in meter
            */
            $havegps = true;

            // store in database
            // please note we store two different times for each record:
            // datetime - date/time we received this data
            // gpstime - time when data was sent by the gps tracker. Some trackers may delay sending
            //    data points or even may reverse order of sending (an error condition may only be sent one 1 minute later)
            // you should always use gpsdata to find the position at a given time. gpsdata is received in UTC time zone
            // and is converted in your local time zone
            $datarec = Model::factory('Gpsdata')->create();
            $datarec->datetime = date("Y-m-d H:i:s");
            $datarec->socket = (int) $sock;
            $datarec->serial = $command[1];
            $datarec->valid = 1;
            $datarec->cmd = $command[2];
            $datarec->err = ($match[1]>9 ? 0 : $match[1]);
            $datarec->lat = gpsDDM2DD($match[3]*($match[4]=='N' ? 1 : -1));
            $datarec->long = gpsDDM2DD($match[5]*($match[6]=='E' ? 1 : -1));
            $datarec->heading = $match[9]*1;
            $datarec->speed = $match[7]*1;
            $datarec->status = $match[10]*1;
            $datarec->distance = $match[11]*1;
            $datarec->ip = $clients[(int) $sock]['ip'];
            $hst = substr_replace(substr_replace($match[8], ':', 4, 0), ':',2,0); // format HH:mm:ss
            $datarec->gpstime = date("H:i:s", strtotime($hst)+$gmtdiff); // convert gmt to our time
            $datarec->save();
          } 

          // now analyze command from client and see if we need to respond
          switch ($command[2]) {
            case 'BP05': // login, may includes gps if there is gps fix, should response with AP05
              echo "Login ";
              sendcmd($sock, $command[1], 'AP05', '');
              break;
            case 'BP00': // handshake. data is like: 357857045206556HSO1a4
              echo "Handshake ";
              sendcmd($sock, $command[1], 'AP01', 'HSO'); // will be sent a few times after login completed
              break;
            case 'BO01': // alarm message, includes gps
              // alarm codes 
              // 0:power off 
              // 1:accident 
              // 2:robbery 
              // 3:anti theft
              // 4:lowspeed
              // 5:overspeed
              // 6:geofence
              // 7:shock alarm
              $alm = ($havegps ? $match[1] : '0');
              echo "Alarm=$alm ";
              sendcmd($sock, $command[1], 'AS01', $alm); 
              break;
            case 'BO02': // Alarm for data offset and messages return, includes gps, code in $match[1]
              $alm = ($havegps ? $match[1] : '0');
              echo "Alarm=$alm ";
              // alarmcodes: (no need to respond)
              // 0:Cut of vehicle oil 
              // 1:vehicle anti-theft alarm 
              // 2:Vehiclerob (SOShelp) 
              // 3:Happen accident 
              // 4:Vehiclelow speed alarm 
              // 5:Vehicleover speed alarm 
              // 6:Vehicleout of Geo-fence
              break;
            case 'BP01': // response of sw version number, no response
            case 'BP04': // answered calling message, includes gps data, no response
            case 'BR00': // isochronous feedback, includes GPS, no response - when moving or standing still? 
            case 'BR01': // isometry continuous feedback GPS, no response - when standing still once every 10 min
            case 'BR02': // continous ending message, include GPS, no repsonse
              break;

            default:
              echo "Unknown command=".$command[2]." ";  
          }
        } else {
          if (strlen($hst)>1) echo "\n".date("H:i:s")." (#".(int) $sock.") Not recognized=".$hst." ";  
        }
      } 
      // if the client closes the socket, we will also free up memory
      if (feof($sock)) {
        $i = (int) $sock;
        echo "\n".date("H:i:s")." (#$i) Remote closed connection";
        logdata('CLOS', $i, $clients[$i]['ip']);
        stream_socket_shutdown($sock, STREAM_SHUT_RDWR);
        unset($clients[$i]);
      }
    }
  }
}

// program will never come here
fclose($socket);

// ----------------------------------------------------------------------

function sendcmd($sock, $serial, $cmd, $arg) {
  // return data to the device
  fwrite($sock, "(".$serial.$cmd.$arg.")\n");
  echo "Send $cmd $arg ";
  fflush($sock); 
}

function gpsDDM2DD($gps) {
  // This function will convert from DMM (degree and decimal minutes) gps coordinate to DD (decimal degrees) format
  // Format received is in DMM format (without the space)
  // received  5210.9500 means 52'10.95"  (DDM) ==> 52.1825 (in DD) 
  // received 00428.4046 means 4'28.4046" (DDM) ==> 4.47341 (in DD)

  $d = floor($gps/100); // degrees
  $m = $gps-100*$d; // minutes
  return ($d+$m/60);
}

function logdata($cmd, $socket, $ip) {
  // log a simple code in the database without gps data
  $datarec = Model::factory('Gpsdata')->create();
  $datarec->datetime = date("Y-m-d H:i:s");
  $datarec->socket = $socket;
  $datarec->valid = 0;
  $datarec->serial = '';
  $datarec->cmd = $cmd;
  $datarec->err = 0;
  $datarec->lat = 0;
  $datarec->long = 0;
  $datarec->heading = 0;
  $datarec->speed = 0;
  $datarec->status = 0;
  $datarec->distance = 0;
  $datarec->ip = $ip;
  $datarec->gpstime = date("H:i:s");
  $datarec->save();
}

?>