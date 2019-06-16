# GPS tracker server and Google Maps client for TK102/ TK103 GPS trackers

This software implements a server to capture GPS data from one or more TK102/TK103 or similar trackers. These trackers use GPRS (2G) data to send coordinates over TCP to your server. Data is stored in a MySQL database. A second part of this software implements a web client to view GPS tracks (by date) using the Google maps API.

## Description server part

The ```receivermulti.php``` runs as background process on the server and listens to incoming TCP connections on a pre-defined port. The GPS tracker is configured to send GPS coordinates and alerts to the defined TCP server port. Data is stored in the MySQL database based on the unique serial number of your GPS tracker. 

A ```cron``` configuration is provided as example how to implement a mechanism to ensure the server runs all the time (and is restarted in case of unscheduled termination).

## Description client part

The ```gpsmap.html``` file contains all the JavaScript logic to call the Google Maps API. The Google servers will then call the ```generate_kml.php``` script to retrieve the KML based coordinates from the MySQL database for a given date. This KML file is then plotted on the map. The HTML file uses Bootstrap to implement responsive behavior.

<img src="https://github.com/bmellink/GPS_Tracker_Server/raw/master/WebsiteClientView.png" height="500">

A user can only click on calendar dates for which data is available in the database. The JavaScript will then instruct the Google servers to load the KML file for that date. 

Please note the Google servers will call ```generate_kml.php``` only once and then cache the KML data. The JavaScript code supplies unique URLs to the Google servers to ensure the KML view is correctly updated when needed. The JS code forces a request of a new KML file once a minute if the latest data is less than 10 minutes old. This should update the map dynamically if your GPS coordinates change. A browser refresh will also force a new KML cache. For more information on KML layers, see [https://developers.google.com/maps/documentation/javascript/kmllayer]

## Getting started

### Requirements
The software was tested with the following environments:
- Server: Raspberry Pi (Ubuntu) and Amazon Web server (AWS Linux)
- Webserver: Apache (any other should work fine)
- PHP versions: 5.6 or 7.1
- Database: MySQL 5.5
- A Google Maps API. See below

The software uses the following libraries/modules:
- PHP Paris + Idiorm to implement a user friendly way to call MySQL
- Bootstrap 4.3.1 to implement responsiveness
- Bootstrap Datepicker 1.8.0
- Jquery 3.4.0

### Setting up the Google Maps API

Refer to [https://developers.google.com/maps/documentation/javascript/get-api-key] to setup your own Google Maps API key. You need to register a credit card if you do this for the first time, but it is not likely you will be charged because the minimal charging thresholds are relatively high (see [https://cloud.google.com/maps-platform/pricing/sheet/]). Keys can be managed from the Google Cloud Platform console at [https://console.cloud.google.com/apis/credentials]. You need to specify the API key in ```mapsconfig.js```.

**Tip**: As soon as you move your application to production, it is a good practice to restrict your Google Map API to your website URL only (using ```HTTP Referrer``` settings) as the API key itself is visible in the source of the ```gpsmap.html``` file. See [https://developers.google.com/maps/faq#keysystem] how to do this.

### Setting up the database

Create a separate MySQL database (default database name ```gps```) and related user credentials with read/write privileges. Then use the ```gps_table.sql``` script to create the ```gps``` table within this database.

Please note the ```gps``` table contains a column ```serial``` that will contain all records from your GPS tracker device, based on its serial number. If you have multiple gps trackers, all data from all trackers will be in the same database table.

### Setting up the code

- Copy the contents (including subdirectories) from the folder ```src``` to the root of your webserver html directory or to a subdirectory.
- Edit the ```mapsconfig_sample.js``` file and replace <span style="color:red">XXXXXXXXXXXXXXXXX</span> to your Google Maps API key in the line:
```
	$.getScript("https://maps.googleapis.com/maps/api/js?key=<span style="color:red">XXXXXXXXXXXXXXXXX</span>&callback=initMap");
```
- Raneme ```mapsconfig_sample.js``` to ```mapsconfig.js```.
- Edit the ```config_sample.php``` file and fill in the database parameters (name of database, credentials, time zone) and IP address and port number of the port exposed to the Internet.
- Rename ```config_sample.php``` to ```config.php```.

**Tip**: The IP address specified in the ```config.php``` file needs to be the address of the Ethernet port of your server that connects to the Internet (you find that with ```ifconfig```). The port number is free (select something above ```1024```). Most likely your server will be behind a firewall and/or NAT router. Make sure the firewall and NAT translation is setup to be able to reach the server and port (using port forwarding or dNAT rules). In case your server runs on Amazon AWS you need to configure your "inbound rule" of the "security group" linked to AWS instance running the GPS server.

### Initial test - step 1

Start your server manually using php on the commandline within the web server directory:
```
% php receivermulti.php
```

You should see something like:
```
22:01:01 (#8) Waiting for connection..0
```
If the server terminates with an error, you may have a problem with firewall software running on the server or the database connection. If the server runs normally, you should see a new record in the ```gps``` table with the current datetime stamp.

### Initial test - step 2

Use ```telnet``` (```nc``` on the Mac) or ```curl``` to try to connect to the server from another computer:
```
% echo "(1111)" | telnet <IP address> <port>
```

You should see the connection coming in on the server console
```
22:02:26 (#15) New connection from xx.xx.xx.xx:xxxxxx
22:02:26 (#15) Remote closed connection
```
If you do not see the connection attempt, you have a problem with network routing or firewall setup.

### Setting up the cron script

The folder ```cron.d``` contains an example cron script you can copy to your ```/etc/cron/cron.d``` directory. The cron script runs once every hour and tries to start the server (```receivermulti.php```), which will succeed if the script is not running (for whatever reason) or do nothing if the server is running normally. The cron script also defines the log file to store all terminal output (please ensure the directory specified in the cron file actually exists). 

### Setting up the GPS tracker

The TK102/ TK103 GPS trackers are programmed using SMS (phone text) messages. The SMS command to set the server is 
```
803#IPADDRESS#PORT#
```
You can also set the time interval the tracker should use to communicate its position to the server. To set the interval to 30 seconds when moving and 5 minutes while stationary, send these SMS commands:
```
730#30#
SUP#5#
```

### URL of the client HTML file

The MySQL database can store data from multiple GPS trackers. The ```key``` parameter in the URL used to call the ```gpsmap.html``` file determines which tracker data to show:
```
http://[Your server]/gpsmap.html?key=12345678
```
where 12345678 is the serial number of your tracker. TK102/ TK103 GPS trackers typically have a 11 digit serial number.

**Tip**: If you are using Apache as web server you can setup a virtual host to serve only the files from this repository. In that case you can add the following line to the ```<VirtualHost>``` section
```
DirectoryIndex gpsmap.html
```
Your client URL will then be simplified to:
```
http://[Your server]?key=12345678
```
