<?php
// Optional MAMP/XAMPP settings. Copy to sms-local.php (Git-ignored).
// Keep simulation enabled until the owner explicitly authorizes paid/live tests.
// Put actual secrets only in ignored private files, never this example.
return ['mode' => 'simulate', 'live_enabled' => false, 'provider' => 'simulation', 'sender' => '',
    // Put Semaphore's key in the ignored includes/semaphore-api-key.php return string.
    // Set this status explicitly when preparing a registered school sender.
    'semaphore_sender_status' => null,
    'unisms_secret' => '',
    'unisms_delivery' => 'acceptance', // Separate one-attempt test policy; production needs final approval.
    'unisms_production_authorized' => false, // Private deployment approval, independent of the admin ON switch.
    'live_test' => null, // Separately authorized, expiring one-attempt scope; see PHASE7B-LIVE-GUARD.md.
    // Domestic phone => current network, verified by the owner. No prefix guessing.
    'unisms_verified_networks' => []];
