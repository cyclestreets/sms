<?php

# Run the application with the supplied config
require_once ('.config.php');
require_once ('cyclestreetsSms.php');
new cyclestreetsSms ($config);

?>
