<?php
declare(strict_types=1);
header('Cache-Control: no-store');header('X-Frame-Options: DENY');header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; connect-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'none'");
readfile(__DIR__.'/view.html');
