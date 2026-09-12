<?php
require_once __DIR__ . '/../api/config/Database.php';
require_once __DIR__ . '/../api/middleware/Auth.php';

use App\Middleware\Auth;

// Generate token for user 13 (info@nextgenkerala.in)
$token = Auth::generateToken([
    'sub' => 13,
    'email' => 'info@nextgenkerala.in',
    'role' => 'user',
    'status' => 'active',
    'tier' => 'pro'
]);

echo "Generated token for user 13: " . substr($token, 0, 20) . "...\n";

$harness = '<!DOCTYPE html>
<html>
<head><title>Print Bill Flow Test</title></head>
<body>
<div id="status">Starting test...</div>
<pre id="logs" style="white-space: pre-wrap; font-family: monospace;"></pre>
<iframe id="app-frame" src="http://localhost/accounting/" style="width:1280px; height:900px;"></iframe>

<script>
localStorage.setItem("accounting_token", ' . json_encode($token) . ');

const logs = [];
function log(msg) {
  logs.push(msg);
  document.getElementById("logs").textContent = logs.join("\n");
}

const frame = document.getElementById("app-frame");

frame.onload = async () => {
  log("Iframe loaded.");
  try {
    const fWin = frame.contentWindow;
    const fDoc = frame.contentDocument;

    fWin.addEventListener("error", (e) => {
      log("UNCAUGHT RUNTIME ERROR: " + e.message);
    });

    await new Promise(r => setTimeout(r, 2500));

    // Click Invoicing & Billing tab
    const buttons = Array.from(fDoc.querySelectorAll("button"));
    const invoicingBtn = buttons.find(b => b.textContent.includes("Invoicing & Billing") || b.textContent.includes("Invoicing"));
    if (!invoicingBtn) {
      log("ERROR: Invoicing tab not found");
      return;
    }
    invoicingBtn.click();
    log("Clicked Invoicing tab.");
    await new Promise(r => setTimeout(r, 1500));

    // Switch to Invoices List (History tab)
    const updatedButtons = Array.from(fDoc.querySelectorAll("button"));
    const historyBtn = updatedButtons.find(b => b.textContent.includes("Invoices List") || b.textContent.includes("History"));
    if (historyBtn) {
      historyBtn.click();
      log("Clicked Invoices List tab.");
      await new Promise(r => setTimeout(r, 1500));
    }

    // Find Print button on invoice list
    const printBtns = Array.from(fDoc.querySelectorAll("button")).filter(b => b.textContent.includes("Print"));
    log("Found " + printBtns.length + " Print buttons in invoices list.");

    if (printBtns.length > 0) {
      log("Clicking Print button on first invoice...");
      printBtns[0].click();
      await new Promise(r => setTimeout(r, 2500));

      const fatalOverlay = fDoc.getElementById("fatal-error-overlay");
      if (fatalOverlay) {
        log("FAILED: Fatal error overlay appeared on bill printing:\n" + fatalOverlay.innerText);
      } else {
        log("SUCCESS: No fatal error on bill printing!");
      }

      // Check if Print modal is visible
      const modalHeader = fDoc.querySelector(".print-area");
      if (modalHeader) {
        log("SUCCESS: Printable bill modal is OPEN and rendered correctly!");
      } else {
        log("Notice: Print modal area not found. Page text snippet: " + fDoc.body.innerText.slice(0, 300));
      }
    } else {
      log("No print buttons found in invoice table.");
    }

  } catch (err) {
    log("TEST SCRIPT ERROR: " + err.message + "\n" + err.stack);
  }
};
</script>
</body>
</html>';

file_put_contents(__DIR__ . '/print_test_runner.html', $harness);

$chrome = '"C:\Program Files\Google\Chrome\Application\chrome.exe" --headless=new --disable-gpu --virtual-time-budget=15000 --dump-dom "http://localhost/accounting/tests/print_test_runner.html"';
$output = shell_exec($chrome);

if (preg_match('/<pre id="logs"[^>]*>([\s\S]*?)<\/pre>/', $output, $matches)) {
    echo "\n=== BROWSER PRINT TEST LOGS ===\n" . html_entity_decode($matches[1]) . "\n";
} else {
    echo "Could not extract logs from DOM output.\n";
    echo substr($output, 0, 1000);
}

@unlink(__DIR__ . '/print_test_runner.html');
