<?php
// Optional MAMP/XAMPP settings. Copy to sms-local.php (Git-ignored).
// Keep simulation enabled until the owner explicitly authorizes paid/live tests.
// Put actual secrets only in ignored private files, never this example.
return ['mode' => 'simulate', 'live_enabled' => false, 'provider' => 'simulation', 'sender' => '',
    'unisms_secret' => '',
    'live_test' => null, // Separately authorized, expiring one-attempt scope; see PHASE7B-LIVE-GUARD.md.
    // Domestic phone => current network, verified by the owner. No prefix guessing.
    'unisms_verified_networks' => []];
