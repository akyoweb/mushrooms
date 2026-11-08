<?php
require_once __DIR__ . '/auth.php';

auth_logout();
header('Location: ' . base_url() . 'login.php');
exit;
