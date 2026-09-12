<?php
$html = file_get_contents(__DIR__ . '/../admin/index.html');
preg_match_all('/<script(?:\s+[^>]*)?>([\s\S]*?)<\/script>/i', $html, $matches);
$adminJs = $matches[1][4];

$testHtml = '<!DOCTYPE html>
<html>
<head><title>Admin JS Syntax Check</title></head>
<body>
<div id="res">Testing...</div>
<script>
window.onerror = function(msg, url, line, col, err) {
  document.getElementById("res").innerText = "ERROR: " + msg + " at " + line + ":" + col;
  window.hasError = true;
};
</script>
<script>
try {
  // Test parsing via Function constructor
  new Function(' . json_encode($adminJs) . ');
  if (!window.hasError) {
    document.getElementById("res").innerText = "SUCCESS: Admin JS parsed without syntax errors!";
  }
} catch(e) {
  document.getElementById("res").innerText = "SYNTAX ERROR: " + e.message;
}
</script>
</body>
</html>';

file_put_contents(__DIR__ . '/test_admin_syntax.html', $testHtml);
$chrome = '"C:\Program Files\Google\Chrome\Application\chrome.exe" --headless=new --disable-gpu --virtual-time-budget=5000 --dump-dom "http://localhost/accounting/tests/test_admin_syntax.html"';
$out = shell_exec($chrome);
if (preg_match('/<div id="res">([\s\S]*?)<\/div>/', $out, $m)) {
    echo $m[1] . "\n";
} else {
    echo "Could not read output.\n";
}
