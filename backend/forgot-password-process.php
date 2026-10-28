<?php
// Retired legacy route: recovery never exposes codes or phone numbers in a URL.
header('Location: ../forgot-password.html', true, 303);
exit;
