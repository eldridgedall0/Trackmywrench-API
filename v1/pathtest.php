<?php
// Visit: https://trackmywrench.com/api/v1/pathtest.php/auth/login
// This shows exactly what PHP sees - paste the output back to me
header('Content-Type: application/json');
echo json_encode([
    'REQUEST_URI'     => $_SERVER['REQUEST_URI'] ?? 'NOT SET',
    'PATH_INFO'       => $_SERVER['PATH_INFO'] ?? 'NOT SET',
    'SCRIPT_NAME'     => $_SERVER['SCRIPT_NAME'] ?? 'NOT SET',
    'PHP_SELF'        => $_SERVER['PHP_SELF'] ?? 'NOT SET',
    'REDIRECT_URL'    => $_SERVER['REDIRECT_URL'] ?? 'NOT SET',
    'QUERY_STRING'    => $_SERVER['QUERY_STRING'] ?? 'NOT SET',
    'REQUEST_METHOD'  => $_SERVER['REQUEST_METHOD'] ?? 'NOT SET',
], JSON_PRETTY_PRINT);
