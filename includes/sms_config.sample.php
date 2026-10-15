<?php
// includes/sms_config.sample.php - the SMS settings template.
// To turn SMS on: copy this file to includes/sms_config.php and paste your TextBee API key below.
// Keep sms_config.php private; never share the keys. With no key, SMS stays off and staff call the customer instead.
// This is the template for SMS. To turn texting on: copy this as includes/sms_config.php and put
// your TextBee API key here. With no key, SMS stays off and staff will just call the customer instead.

// SMS is sent with TextBee (free): https://textbee.dev -> dashboard -> API key.
// The phone with the TextBee app must be on, online, and have a SIM.
define('SMS_PROVIDER', 'textbee');
define('TEXTBEE_API_KEY',   'paste-your-textbee-api-key-here');
define('TEXTBEE_DEVICE_ID', '');   // leave '' to use your default phone; or paste the Device ID shown in the TextBee dashboard

define('SHOP_NAME', 'Sparkling Spin');   // shown at the start of every text

// Which status changes are texted to the customer (Ready for Pickup is always texted).
// Choose from: received, started, released, cancelled. Free TextBee plan = 50 texts a day, kaya fewer events = more orders.
define('SMS_EVENTS', 'received,started,ready,cancelled');