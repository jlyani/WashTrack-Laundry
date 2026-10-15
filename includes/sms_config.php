<?php
// includes/sms_config.php - the SMS settings file.
// To turn SMS on: copy this file to includes/sms_config.php and fill in ONE of the two providers below.
// Keep sms_config.php private; never share the keys. With no key, SMS stays off and staff call the customer instead.
// This is the active SMS config. Pick a provider: 'textbee' (free, own Android phone) or 'semaphore' (paid).
// Do not share this file - may API key here. With no key, SMS stays off and staff will just call instead.

// Which provider sends the texts: 'textbee' (free, own Android phone) or 'semaphore' (paid).
define('SMS_PROVIDER', 'textbee');

// --- TextBee (free): https://textbee.dev  -> dashboard -> API key. The phone with the TextBee app must be on, online, and have a SIM.
define('TEXTBEE_API_KEY',   'txb_fuzka4OkCep0mN3tVXSs789iipjhVjxs');
define('TEXTBEE_DEVICE_ID', '');   // leave '' to use your default phone; or paste the Device ID shown in the TextBee dashboard

// --- Semaphore (paid, needs a top-up and an approved Sender Name): https://semaphore.co -> Account -> API Key
define('SMS_API_KEY',     '');
define('SMS_SENDER_NAME', '');     // your approved Sender Name, e.g. 'SparklingSpin'. '' = your Semaphore default

// The shop name shown at the start of every text.
define('SHOP_NAME', 'Sparkling Spin');   // shown at the start of every text