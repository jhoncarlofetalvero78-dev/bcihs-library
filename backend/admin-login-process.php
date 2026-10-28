<?php
// Set by this endpoint, never trusted from a submitted form.
$staffLoginOnly = true;
if (isset($_POST['username']) && !isset($_POST['identifier'])) {
    $_POST['identifier'] = $_POST['username'];
}

require_once __DIR__ . '/login-process.php';
