<?php
$html = file_get_contents(dirname(__DIR__) . '/public/index.html');
$lines = explode("\n", $html);

// Find where script starts
$scriptStartLine = 0;
foreach ($lines as $idx => $line) {
    if (strpos($line, '<script type="text/babel">') !== false) {
        $scriptStartLine = $idx + 1;
        break;
    }
}

$code = '';
for ($lineNum = $scriptStartLine; $lineNum < count($lines); $lineNum++) {
    $line = $lines[$lineNum];
    if (strpos($line, '</script>') !== false) break;
    $code .= $line . "\n";
}

// Let's test compiling this with a JS runner or let's find any HTML/JSX issues
// Can we run Windows cscript or powershell or edge/mshta to run Babel?
file_put_contents(__DIR__ . '/extracted_script.js', $code);
echo "Extracted script: " . strlen($code) . " bytes to tests/extracted_script.js\n";
