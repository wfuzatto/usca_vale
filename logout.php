<?php
require_once __DIR__.'/src/bootstrap.php';
startSession(); $_SESSION=[];session_regenerate_id(true);session_destroy();header('Location: login.php');
