<?php

// sampple config file. Rename this file to config.php when done. Then copy the same file also to the www folder


// database users MySQL account
define('DBHOST', '127.0.0.1');      // IP address of host with mysql database (asume localhost)
define('DBUSER', 'xxxxx');      	// user to login into mysql database
define('DBPASSWORD', 'xxxx');    	// password mysql database
define('DBNAME', 'gps');       	  // name of database (rename to your liking)
define('DBTIMEZONE', "Europe/Amsterdam"); // Local time for Mysql database - see https://www.php.net/manual/en/timezones.php

// Port exposed to the internet to receive GPRS messages from device
// In case you have a NAT router to the internet, the address should be the local ethernet port address of your server
// The port can be anything you want as long as your NAT rule points to this port
define('GPSPORT', "tcp://192.168.1.1:4122"); 

define('TIMEGAP', 3600); // number of seconds (3600=1 hour) between individual database records when the front end maps display will asume a separate segment should be shown on the map

define('MINMOVELATLONG', 0.01); // if during the day the lat+long coordates move above this threshold, we assume there is movement on that date and highlight the date on the html calendar widget (number in degrees. 0.01=approx 1km)

?>