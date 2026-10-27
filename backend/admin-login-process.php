<?php
if (isset($_POST['username']) && !isset($_POST['identifier'])) {
    $_POST['identifier'] = $_POST['username'];
}

require_once __DIR__ . '/login-process.php';
