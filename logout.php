<?php
require_once 'includes/config.php';
startSession();
session_destroy();
header('Location: index.php');
exit;