<?php
require_once __DIR__ . '/../api/config/Database.php';
require_once __DIR__ . '/../api/middleware/Auth.php';

use App\Middleware\Auth;

$token = Auth::generateToken([
    'sub' => 13,
    'email' => 'info@nextgenkerala.in',
    'role' => 'user',
    'status' => 'active',
    'tier' => 'pro'
]);

$harness = '<!DOCTYPE html>
<html>
<head><title>Direct Save and Preview Test</title></head>
<body>
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
  try {
    const fWin = frame.contentWindow;
    const fDoc = frame.contentDocument;

    let printCalled = false;
    fWin.print = function() {
      printCalled = true;
      log("CALL DETECTED: window.print() was called automatically!");
    };

    fWin.addEventListener("error", (e) => {
      log("UNCAUGHT RUNTIME ERROR: " + e.message);
    });

    await new Promise(r => setTimeout(r, 2500));

    // Click Invoicing tab
    const buttons = Array.from(fDoc.querySelectorAll("button"));
    const invoicingBtn = buttons.find(b => b.textContent.includes("Invoicing & Billing") || b.textContent.includes("Invoicing"));
    invoicingBtn.click();
    log("Clicked Invoicing tab.");
    await new Promise(r => setTimeout(r, 1500));

    // Get initial invoice number
    const inputs = Array.from(fDoc.querySelectorAll("input"));
    const invNumInput = inputs.find(i => i.value && i.value.startsWith("INV-"));
    const initialInvNumber = invNumInput ? invNumInput.value : "";
    log("Initial Invoice Number: " + initialInvNumber);

    // Fill customer
    const custInput = inputs.find(i => i.placeholder && i.placeholder.includes("Zenith"));
    if (custInput) {
      const nativeInputValueSetter = Object.getOwnPropertyDescriptor(fWin.HTMLInputElement.prototype, "value").set;
      nativeInputValueSetter.call(custInput, "Direct Print Test Client");
      custInput.dispatchEvent(new fWin.Event("input", { bubbles: true }));
      log("Set Customer: Direct Print Test Client");
    }

    // Fill description
    const descInput = inputs.find(i => i.placeholder && i.placeholder.includes("Software Development"));
    if (descInput) {
      const nativeInputValueSetter = Object.getOwnPropertyDescriptor(fWin.HTMLInputElement.prototype, "value").set;
      nativeInputValueSetter.call(descInput, "Consulting and Architectural Services");
      descInput.dispatchEvent(new fWin.Event("input", { bubbles: true }));
      log("Set description: Consulting and Architectural Services");
    }

    // Fill rate
    const priceInput = inputs.find(i => i.placeholder === "0.00");
    if (priceInput) {
      const nativeInputValueSetter = Object.getOwnPropertyDescriptor(fWin.HTMLInputElement.prototype, "value").set;
      nativeInputValueSetter.call(priceInput, "30000");
      priceInput.dispatchEvent(new fWin.Event("input", { bubbles: true }));
      log("Set rate: 30000");
    }

    await new Promise(r => setTimeout(r, 500));

    // Click Save & Generate Invoice
    const submitBtn = Array.from(fDoc.querySelectorAll("button")).find(b => 
      b.textContent.includes("Save & Generate") || b.textContent.includes("Generate Invoice")
    );
    submitBtn.click();
    log("Clicked Save & Generate Invoice.");

    // Check loading/buffering state immediately
    await new Promise(r => setTimeout(r, 200));
    const btnTextDuringSave = submitBtn.textContent;
    log("Button status during submit: " + btnTextDuringSave.trim());

    // Wait for generation, auto-modal open and auto-print trigger
    await new Promise(r => setTimeout(r, 3500));

    const fatal = fDoc.getElementById("fatal-error-overlay");
    if (fatal) {
      log("FAILED: fatal error overlay detected: " + fatal.innerText);
      return;
    }

    // Check if print-area modal is visible
    const printArea = fDoc.querySelector(".print-area");
    if (printArea) {
      log("SUCCESS: Executive Printable Bill Preview modal opened directly on screen!");
      log("Modal contains customer: " + (printArea.textContent.includes("Direct Print Test Client") ? "YES" : "NO"));
      log("Modal contains amount: " + (printArea.textContent.includes("35,400.00") ? "YES" : "NO"));
    } else {
      log("FAILED: Print modal (.print-area) not found on screen!");
    }

    // Check if auto-print was triggered
    log("Auto window.print() called: " + (printCalled ? "YES (SUCCESS)" : "NO"));

    // Check if new invoice number was generated
    const newInvNumInput = Array.from(fDoc.querySelectorAll("input")).find(i => i.value && i.value.startsWith("INV-"));
    const newInvNumber = newInvNumInput ? newInvNumInput.value : "";
    log("Regenerated Next Invoice Number: " + newInvNumber + " (Different: " + (newInvNumber !== initialInvNumber) + ")");

  } catch (err) {
    log("TEST SCRIPT ERROR: " + err.message);
  }
};
</script>
</body>
</html>';

file_put_contents(__DIR__ . '/direct_preview_test.html', $harness);

$chrome = '"C:\Program Files\Google\Chrome\Application\chrome.exe" --headless=new --disable-gpu --virtual-time-budget=20000 --dump-dom "http://localhost/accounting/tests/direct_preview_test.html"';
$output = shell_exec($chrome);

if (preg_match('/<pre id="logs"[^>]*>([\s\S]*?)<\/pre>/', $output, $matches)) {
    echo "\n=== DIRECT SAVE & PREVIEW TEST LOGS ===\n" . html_entity_decode($matches[1]) . "\n";
} else {
    echo "Could not extract logs.\n" . substr($output, 0, 1000);
}

@unlink(__DIR__ . '/direct_preview_test.html');
