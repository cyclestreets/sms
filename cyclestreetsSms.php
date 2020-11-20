<?php

# Class to crate a CycleStreets SMS client, using 1997-era plain-old SMS
class cyclestreetsSms
{
	# Constructor
	public function __construct ($config)
	{
		# Obtain the settings
		$this->settings = $config;
		
		# Require posted message
		if (!$_POST) {return false;}
		
		# Obtain the posted data
		$user = (isSet ($_POST['from']) ? $_POST['from'] : false);
		$message = (isSet ($_POST['content']) ? $_POST['content'] : false);
		if (!$user || !$message) {return false;}
		
		# Get the waypoints
		if (!$waypoints = $this->getWaypoints ($message, $waypointNames /* returned by reference */, $error /* returned by reference */)) {
			$this->send ($user, $error);
			return false;
		}
		
		# Get the route
		$route = $this->getRoute ($waypoints);
		
		# Parse the route to streets
		$directions = $this->directions ($route, $waypointNames);
		
		# If there are to many directions, say it is too long, to avoid excessive SMS fees
		if (count ($directions) > 50) {
			$error = "Sorry, this route has too many parts to send by text. Please request a shorter route.";
			$this->send ($user, $message);
			return false;
		}
		
		# Construct the message
		$message = $this->constructMessage ($directions, $waypointNames);
		
		# Send SMS
		$this->send ($user, $message);
	}
	
	
	# Get waypoints
	private function getWaypoints ($message, &$locations, &$error)
	{
		# Parse out To and From
		$locations = explode (' to ', trim ($message));
		
		# Look up the location
		$waypoints = array ();
		foreach ($locations as $location) {
			$location = trim ($location);
			if (!$waypoints[] = $this->getLocationGoogle ($location)) {
				$error = "Sorry, we couldn't find the location {$location}. Please try again with a more specific location name.";
				return false;
			}
		}
		
		# Extract the destination name
		$destination = end ($locations);
		
		# Format requested location names
		foreach ($locations as $index => $location) {
			$locations[$index] = ucwords ($location);
		}
		
		# Return the waypoints
		return $waypoints;
	}
	
	
	# Get location from Geocoder, using the Google Geocoder API
	# See: https://developers.google.com/maps/documentation/geocoding/overview
	private function getLocationGoogle ($q)
	{
		$geocoderUrl = "https://maps.googleapis.com/maps/api/geocode/json?key={$this->settings['googleApiKey']}&bounds=-6.6577,49.9370|1.7797,57.6924&region=uk&address=" . urlencode ($q);
		$result = file_get_contents ($geocoderUrl);
		$locations = json_decode ($result, true);
		if (!$locations['results']) {
			return false;
		}
		$coordinates = $locations['results'][0]['geometry']['location'];
		$location = implode (',', array ($coordinates['lng'], $coordinates['lat'], $q));
		return $location;
	}
	
	
	# Get location from Geocoder, using the CycleStreets Geocoder API
	private function getLocationCycleStreets ($q)
	{
		$geocoderUrl = "https://api.cyclestreets.net/v2/geocoder?key={$this->settings['cyclestreetsApiKey']}&bounded=1&bbox=-6.6577,49.9370,1.7797,57.6924&q=" . urlencode ($q);
		$result = file_get_contents ($geocoderUrl);
		$locations = json_decode ($result, true);
		$coordinates = $locations['features'][0]['geometry']['coordinates'];
		$location = implode (',', $coordinates) . ',' . $q;
		return $location;
	}
	
	
	# Function to get a route
	private function getRoute ($waypoints)
	{
		# URL-encode the waypoints
		foreach ($waypoints as $index => $waypoint) {
			$waypoints[$index] = urlencode ($waypoint);
		}
		
		# Get the route from the routing API
		$url = "https://api.cyclestreets.net/v2/journey.plan?key={$this->settings['cyclestreetsApiKey']}&waypoints=" . implode ('|', $waypoints) . '&plans=balanced&speedKmph=20';
		$result = file_get_contents ($url);
		$route = json_decode ($result, true);
		return $route;
	}
	
	
	# Function to create the directions from the route
	# See e.g. /v2/journey.plan?waypoints=0.147861,52.2002883,Thoday%20Street,%20Cambridge|0.13755203043485,52.2030216,Mill%20Road%20Cambridge&plans=balanced&speedKmph=20
	private function directions ($route, $waypointNames, $strategy = 'balanced')
	{
		# Start the directions
		$directions = array ();
		
		# Loop through each street
		foreach ($route['features'] as $feature) {
			$properties = $feature['properties'];
			
			# Filter path
			if (!preg_match ("|^plan/{$strategy}/street/|", $properties['path'])) {continue;}
			
			# Extract the name and turn
			$turn = $this->turnToSymbol ($properties['turnPrevText']);
			$name = $this->shortenName ($properties['name']);
			if (!$directions) {		// i.e. if first entry
				$name .= $this->startDirection ($properties['startBearing']);
			}
			$distance = $properties['lengthMetres'];
			$time = $properties['timeSeconds'];
			
			# Construct the instruction
			$directions[] = $turn . ' ' . $name . ' (' . $distance . 'm, ' . $time . 's)';
		}

		# Add the destination at the end
		$destination = end ($waypointNames);
		$directions[] = 'Arrive at ' . $destination . '.';
		
		# Return the directions
		return $directions;
	}
	
	
	# Function to shorten names
	private function shortenName ($name)
	{
		# Do replacements
		$name = preg_replace ('/\bRoad\b/', 'Rd', $name);
		$name = preg_replace ('/\bStreet\b/', 'St', $name);
		$name = preg_replace ('/\bLane\b/', 'Ln', $name);
		
		# Return the name
		return $name;
	}
	
	
	# Function add a start direction modifier, e.g. ("Start at Thoday Street, heading North")
	private function startDirection ($bearing)
	{
		# Round to nearest 45 degrees
		$bearing = round ($bearing / 45) * 45;

		# Define the angles
		$angles = array (
			0	=> 'North',
			45	=> 'North-East',
			90	=> 'East',
			135	=> 'South-East',
			180	=> 'South',
			225	=> 'South-West',
			270	=> 'West',
			315	=> 'North-West',
			360	=> 'North',
		);
		
		# Look up the angle
		$startDirection = $angles[$bearing];
		
		# Construct the string
		$string = ", heading {$startDirection}";
		
		# Return the result
		return $string;
	}
	
	
	# Function to convert a turn string to a symbol
	private function turnToSymbol ($string)
	{
		# Define the turn symbols
		# See: https://www.cyclestreets.net/api/v1/journey/#jpReturnSegment
		# See: https://en.wikipedia.org/wiki/Arrows_(Unicode_block)
		$symbols = array (
			'start'			=> 'Start at',
			'straight on'	=> 'A↑',
			'bear right'	=> 'R↗',
			'turn right'	=> 'R→',
			'sharp right'	=> 'R↘',
			'double-back'	=> 'U↷',
			'sharp left'	=> 'L↙',
			'turn left'		=> 'L←',
			'bear left'		=> 'L↖',
		);
		
		# Sadly SMS doesn't handle unicode, so the letters have to be used
		foreach ($symbols as $turn => $symbol) {
			if ($turn == 'start') {continue;}
			$symbols[$turn] = substr ($symbol, 0, 1) . ':';
		}
		
		return (isSet ($symbols[$string]) ? $symbols[$string] : $string);
	}
	
	
	# Function to construct the message
	private function constructMessage ($directions, $waypointNames)
	{
		# Construct the message
		$message  = 'Route from ' . implode (' to ', $waypointNames) . ':';
		$message .= "\n\n" . implode ("\n", $directions);
		$message .= "\n\nBye,\nCycleStreets xx";
		
		# Return the message
		return $message;
	}
	
	
	# Send SMS
	# https://www.clockworksms.com/doc/easy-stuff/http-interface/receive-sms/
	# Example URL: https://api.clockworksms.com/http/send.aspx?key=KEY&to=441234567890&content=Hello+World
	private function send ($to, $message)
	{
		# Divide if longer than max length (459);
		# See: https://www.clockworksms.com/faqs/#can-i-send-a-long-or-concatenated-message-with-clockwork
		# See: https://app5.clockworksms.com/Sending/Defaults
		$messages = str_split ($message, 450);		// 450 gives a bit of overhead
		$lines = explode ("\n", $message);
		$messages = array ();
		$messageNumber = 0;
		$count = 0;
		foreach ($lines as $line) {
			$length = strlen ($line . "\n");	// Length of prospective line
			
			# If the line would take the message over the limit, go to next image
			if (($count + $length) > 450) {
				$messageNumber++;
				$header = "Page {$messageNumber}:" . "\n";
				$count = 0 + strlen ($header);
				$messages[$messageNumber][] = $header;
			}
			
			# Register the line and increment the count
			$messages[$messageNumber][] = $line;
			$count += $length;
		}
		
		# Compile the lines back to messages
		foreach ($messages as $messageNumber => $lines) {
			$messages[$messageNumber] = implode ("\n", $lines);
		}
		
		# Construct the message
		$url = 'https://api.clockworksms.com/http/send.aspx';
		$smss = array ();
		foreach ($messages as $messagePart) {
			$smss[] = array (
				'key'		=> $this->settings['clockworkApiKey'],
				'to'		=> $to,
				'content'	=> $messagePart,
			);
		}
		
		/*
		var_dump ($url);
		var_dump ($smss);
		die;
		*/
		
		# Send the message, POSTed given the lenth
		foreach ($smss as $sms) {
			$result = $this->file_post_contents ($url, $sms);
			//var_dump ($result);
			sleep (5);	// Basic attempt to try to have them be received in order
		}
	}
	
	
	# Equivalent of file_get_contents but for POST rather than GET
	private function file_post_contents ($url, $postData)
	{
		# Set the stream options
		$streamOptions = array (
			'http' => array (
				'method'		=> 'POST',
				'header'		=> 'Content-type: application/x-www-form-urlencoded',
				'content'		=> http_build_query ($postData),
			)
		);
		
		# Post the data and return the result
		return file_get_contents ($url, false, stream_context_create ($streamOptions));
	}
}

?>
