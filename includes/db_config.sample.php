<?php
// includes/db_config.sample.php - the settings template file.
// Sa WEB HOST: copy this file to includes/db_config.php and fill in the values from the host's
// "MySQL Databases" page. db.php loads db_config.php automatically kapag it exists.
// Do NOT upload db_config.php anywhere public, and never share it.
// This is the template for database settings. When deploying to hosting, copy this as
// includes/db_config.php and put the real host/user/password/dbname. Do not share the real config.
$host   = "sqlXXX.yourhost.example";   // the host's MySQL server name (often NOT "localhost" on free hosts)
$user   = "your_db_user";
$pass   = "your_db_password";
$dbname = "your_db_name";              // the empty database you import database/laundry_db.sql into
$port   = 3306;
